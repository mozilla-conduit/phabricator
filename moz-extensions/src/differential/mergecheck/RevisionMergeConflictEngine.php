<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * Determines whether a revision's active diff still merges cleanly into its
 * target branch by performing a real git 3-way merge.
 *
 * Because the staging area is unreliable, we synthesize the revision's tree
 * ourselves: seed a temporary index from the diff's base commit, apply the
 * diff's patch into that index, and write a tree. We then 3-way merge that tree
 * against the current target-branch tip using the diff's base as the merge
 * base.
 *
 * The result is one of `clean`, `conflict`, or `unknown`. A `conflict` is only
 * ever returned when a successful merge reports a conflict; every ambiguous
 * situation (missing base, patch that doesn't apply, binary content, missing
 * branch, git failure) yields `unknown`. We never report a false `conflict`.
 */
final class RevisionMergeConflictEngine extends Phobject {

  // The modern merge path uses `git merge-tree --write-tree` (exit-code-based
  // conflict detection, git >= 2.38) together with `--merge-base` (git >= 2.40).
  // Anything older uses the legacy tree-based form, which is parsed for conflict
  // markers and works on the widest range of git versions.
  const MODERN_MERGE_TREE_VERSION = '2.40.0';

  private $viewer;
  private $revision;
  private $diff;
  private $repository;

  public function setViewer(PhabricatorUser $viewer) {
    $this->viewer = $viewer;
    return $this;
  }

  public function setRevision(DifferentialRevision $revision) {
    $this->revision = $revision;
    return $this;
  }

  public function setDiff(DifferentialDiff $diff) {
    $this->diff = $diff;
    return $this;
  }

  public function setRepository(PhabricatorRepository $repository) {
    $this->repository = $repository;
    return $this;
  }

/* -(  Execution  )---------------------------------------------------------- */

  /**
   * Returns a map with keys `status` and `reason`. `status` is one of the
   * `DifferentialMergeConflictStatusField::STATUS_*` constants.
   */
  public function executeCheck() {
    try {
      return $this->runCheck();
    } catch (Exception $ex) {
      // Any failure is reported as "unknown" rather than a conflict, so we
      // never claim a conflict we couldn't actually prove.
      return $this->newResult(
        DifferentialMergeConflictStatusField::STATUS_UNKNOWN,
        $ex->getMessage());
    }
  }

  private function runCheck() {
    $repository = $this->repository;

    if (!$repository->isGit()) {
      return $this->newResult(
        DifferentialMergeConflictStatusField::STATUS_UNKNOWN,
        pht('Repository is not a Git repository.'));
    }

    $base = $this->getDiffBaseCommit();
    $this->assertCommitExists($base);

    $patch = $this->renderGitPatch();
    if (!phutil_nonempty_string($patch)) {
      throw new Exception(pht('Diff produced an empty patch.'));
    }

    $target_tip = $this->resolveTargetTip();

    // Synthesize the revision's tree in a temporary index. The TempFile is held
    // until the method returns so it (and whatever git writes at its path) is
    // cleaned up automatically.
    $temp_index = new TempFile();
    $index_path = (string)$temp_index;

    // Git wants the index file to either not exist or be a valid index; an
    // empty placeholder file would be rejected, so remove it and let git
    // recreate it.
    Filesystem::remove($index_path);

    $revision_tree = $this->synthesizeRevisionTree($base, $index_path, $patch);

    $status = $this->runMerge($base, $target_tip, $revision_tree);

    return $this->newResult($status, pht('Merge check completed.'), $target_tip);
  }

/* -(  Inputs  )------------------------------------------------------------- */

  private function getDiffBaseCommit() {
    $base = $this->diff->getSourceControlBaseRevision();
    if (!phutil_nonempty_string($base)) {
      throw new Exception(pht('Diff has no recorded base revision.'));
    }
    return $base;
  }

  private function assertCommitExists($commit) {
    // `cat-file -e` exits non-zero if the object is missing, which surfaces as
    // a CommandException and is mapped to "unknown".
    $this->repository->execxLocalCommand(
      'cat-file -e %s',
      $commit.'^{commit}');
  }

  private function renderGitPatch() {
    $diff = id(new DifferentialDiffQuery())
      ->setViewer($this->viewer)
      ->withIDs(array($this->diff->getID()))
      ->needChangesets(true)
      ->executeOne();

    if (!$diff) {
      throw new Exception(pht('Failed to reload diff with changesets.'));
    }

    return id(new DifferentialRawDiffRenderer())
      ->setViewer($this->viewer)
      ->setChangesets($diff->getChangesets())
      ->setFormat('git')
      ->buildPatch();
  }

  private function resolveTargetTip() {
    $branch = $this->repository->getDefaultBranch();
    if (!phutil_nonempty_string($branch)) {
      throw new Exception(pht('Repository has no default branch.'));
    }

    list($stdout) = $this->repository->execxLocalCommand(
      'rev-parse --verify %s',
      'refs/heads/'.$branch.'^{commit}');

    return trim($stdout);
  }

/* -(  Git merge  )---------------------------------------------------------- */

  private function synthesizeRevisionTree($base, $index_path, $patch) {
    $this->newIndexCommandFuture($index_path, 'read-tree %s', $base)
      ->resolvex();

    $apply_future = $this->newIndexCommandFuture(
      $index_path,
      'apply --cached --whitespace=nowarn');
    $apply_future->write($patch);
    $apply_future->resolvex();

    list($stdout) = $this->newIndexCommandFuture($index_path, 'write-tree')
      ->resolvex();

    return trim($stdout);
  }

  private function runMerge($base, $target_tip, $revision_tree) {
    if ($this->supportsWriteTreeMerge()) {
      return $this->runModernMerge($base, $target_tip, $revision_tree);
    }
    return $this->runLegacyMerge($base, $target_tip, $revision_tree);
  }

  private function runModernMerge($base, $target_tip, $revision_tree) {
    // The modern form operates on commits, so wrap the synthesized tree in a
    // commit parented on the base. Fixed identity env keeps the hash
    // deterministic.
    $commit_future = $this->repository->getLocalCommandFuture(
      'commit-tree %s -p %s -m %s',
      $revision_tree,
      $base,
      'merge-check');
    $this->applyDeterministicIdentity($commit_future);
    list($commit_stdout) = $commit_future->resolvex();
    $revision_commit = trim($commit_stdout);

    // Use an explicit merge base so rebased/uplifted revisions are merged
    // against the intended base rather than an auto-computed ancestor. This
    // path is only selected for git >= 2.40 (see `MODERN_MERGE_TREE_VERSION`);
    // older git uses the legacy form instead.
    $merge_future = $this->repository->getLocalCommandFuture(
      'merge-tree --write-tree --merge-base=%s --name-only %s %s',
      $base,
      $target_tip,
      $revision_commit);

    list($err) = $merge_future->resolve();

    switch ($err) {
      case 0:
        return DifferentialMergeConflictStatusField::STATUS_CLEAN;
      case 1:
        return DifferentialMergeConflictStatusField::STATUS_CONFLICT;
      default:
        throw new Exception(
          pht('Unexpected "git merge-tree" exit code: %d.', $err));
    }
  }

  private function runLegacyMerge($base, $target_tip, $revision_tree) {
    // The legacy form takes tree-ish operands and always exits 0; conflicts are
    // detected by scanning the merged output for conflict markers.
    list($stdout) = $this->repository->execxLocalCommand(
      'merge-tree %s %s %s',
      $base.'^{tree}',
      $target_tip.'^{tree}',
      $revision_tree);

    if (self::legacyOutputHasConflict($stdout)) {
      return DifferentialMergeConflictStatusField::STATUS_CONFLICT;
    }

    return DifferentialMergeConflictStatusField::STATUS_CLEAN;
  }

  /**
   * The legacy `git merge-tree` form always exits 0 and emits the merged result
   * inline; an unresolved content conflict is marked with `<<<<<<<` conflict
   * markers in its output.
   */
  public static function legacyOutputHasConflict($stdout) {
    return (strpos($stdout, '<<<<<<<') !== false);
  }

/* -(  Git helpers  )-------------------------------------------------------- */

  private function newIndexCommandFuture($index_path, $pattern /* , ... */) {
    $args = array_slice(func_get_args(), 1);
    $future = call_user_func_array(
      array($this->repository, 'getLocalCommandFuture'),
      $args);
    $future->updateEnv('GIT_INDEX_FILE', $index_path);
    return $future;
  }

  private function applyDeterministicIdentity($future) {
    $name = 'Phabricator Merge Check';
    $email = 'merge-check@phabricator';
    $date = '2000-01-01T00:00:00+0000';

    $future
      ->updateEnv('GIT_AUTHOR_NAME', $name)
      ->updateEnv('GIT_AUTHOR_EMAIL', $email)
      ->updateEnv('GIT_AUTHOR_DATE', $date)
      ->updateEnv('GIT_COMMITTER_NAME', $name)
      ->updateEnv('GIT_COMMITTER_EMAIL', $email)
      ->updateEnv('GIT_COMMITTER_DATE', $date);
  }

  private function supportsWriteTreeMerge() {
    return version_compare(
      $this->getGitVersion(),
      self::MODERN_MERGE_TREE_VERSION,
      '>=');
  }

  private function getGitVersion() {
    list($stdout) = $this->repository->execxLocalCommand('--version');
    return self::parseGitVersion($stdout);
  }

  /**
   * Extracts a `major.minor.patch` version from `git --version` output. If the
   * version can't be parsed, returns `0.0.0` so callers fall back to the legacy
   * path, which works on the widest range of git versions.
   */
  public static function parseGitVersion($stdout) {
    $matches = null;
    if (preg_match('/git version (\d+\.\d+\.\d+)/', $stdout, $matches)) {
      return $matches[1];
    }
    return '0.0.0';
  }

/* -(  Results  )------------------------------------------------------------ */

  private function newResult($status, $reason, $target_commit = null) {
    return array(
      'status' => $status,
      'reason' => $reason,
      'targetCommit' => $target_commit,
    );
  }

}
