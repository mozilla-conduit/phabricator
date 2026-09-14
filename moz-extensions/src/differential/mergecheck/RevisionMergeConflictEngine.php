<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * Determines whether a revision's active diff still merges cleanly into its
 * target branch by performing a real git 3-way merge.
 *
 * Because the staging area is unreliable, we synthesize the revision's tree
 * ourselves: seed a temporary index from the bottom of the revision's stack,
 * apply each patch in the stack into that index, and write a tree. We then
 * 3-way merge that tree against the current target-branch tip using the
 * stack's base as the merge base. The merge itself is `git merge-tree
 * --write-tree --merge-base=<base>`, which reports its verdict through the
 * exit code: 0 clean, 1 conflict, anything else a git failure rather than an
 * answer. The merge base is passed explicitly because git would otherwise
 * compute the common ancestor of the two commits, which is not what a rebased
 * or uplifted revision should be merged against. This needs git 2.40 or newer,
 * which the image provides and its build asserts; the version is checked here
 * anyway so an old git produces an actionable reason rather than a bare
 * non-zero exit code.
 *
 * A revision in a stack only lands after everything below it, so the tree we
 * merge includes every open ancestor's patch as well as the revision's own
 * (see `RevisionMergeConflictStackQuery`). A standalone revision is just the
 * degenerate one-element stack.
 *
 * When the bottom of the stack is based on a commit that isn't in the
 * repository -- the usual sign of a partially-landed stack, where the recorded
 * base only ever existed in the author's checkout -- we fall back to the commit
 * that closed the parent revision. Landing rewrites a commit's message but not
 * its tree, so that is what the rest of the stack was written on top of. If the
 * parent was genuinely rebased and its tree differs, the patch fails to apply
 * and the result is inconclusive, so this can never invent a conflict.
 *
 * The result is one of `clean`, `conflict`, or `unknown`. A `conflict` is only
 * ever returned when `git merge-tree` completed and reported one; every
 * ambiguous situation (missing base, a base that isn't on the target branch, a
 * patch that doesn't apply, a missing branch, a git failure) yields `unknown`.
 * We never report a false `conflict`. Command failures are logged rather than
 * having their output stored in a field shown to users.
 *
 * Two things the check refuses to answer rather than guess at:
 *
 *   - A base that is in the object store but not an ancestor of the target
 *     branch. It is forced as the merge base, so an off-branch base makes
 *     everything the branch gained since the real fork point read as a
 *     conflicting edit. Only a zero exit from `merge-base --is-ancestor`
 *     proves ancestry; a git error is treated the same as "not an ancestor".
 *   - A stack whose patch text exceeds a fixed budget. Every patch is
 *     materialized in memory before reaching git, and the budget spans the
 *     whole stack, since a tall stack of moderate diffs costs as much as one
 *     enormous one.
 *
 * Every git invocation carries a time limit so a wedged command fails the check
 * instead of pinning a taskmaster until its lease expires. Timeouts are
 * detected explicitly, because a command killed by a signal must never have its
 * exit code read as a merge verdict.
 */
final class RevisionMergeConflictEngine extends Phobject {

  // `merge-tree --write-tree` needs git 2.38 and `--merge-base` needs 2.40.
  const MINIMUM_GIT_VERSION = '2.40.0';

  // Every patch in the stack is materialized in memory before being handed to
  // git, so cap the total across the stack. This runs unattended for every open
  // revision touching a landed path, and a diff bigger than this is not
  // something we can usefully check anyway.
  const MAX_PATCH_BYTES = 32 * 1024 ** 2;

  // Ceiling on any single git invocation. Generous enough for a large merge on
  // a large repository, short enough that a wedged command frees its taskmaster
  // instead of holding it until the task lease expires two hours later.
  const GIT_TIMEOUT_SECONDS = 300;

  private ?PhabricatorUser $viewer = null;
  private ?DifferentialRevision $revision = null;
  private ?DifferentialDiff $diff = null;
  private ?PhabricatorRepository $repository = null;

  private ?RevisionMergeConflictStackQuery $stackQuery = null;
  private ?array $stackRevisions = null;
  private ?array $stackDiffs = null;
  private ?string $stackStopReason = null;
  private ?DifferentialRevision $baseFromLandedParent = null;

  public function setViewer(PhabricatorUser $viewer): self {
    $this->viewer = $viewer;
    return $this;
  }

  public function setRevision(DifferentialRevision $revision): self {
    $this->revision = $revision;
    return $this;
  }

  public function setDiff(DifferentialDiff $diff): self {
    $this->diff = $diff;
    return $this;
  }

  public function setRepository(PhabricatorRepository $repository): self {
    $this->repository = $repository;
    return $this;
  }

/* -(  Execution  )---------------------------------------------------------- */

  /**
   * Returns a map with keys `status`, `reason`, `baseCommit` and
   * `targetCommit`. `status` is one of the
   * `DifferentialMergeConflictStatusField::STATUS_*` constants. The two commits
   * are only present on a definitive result, since an `unknown` one may not
   * have got as far as resolving them.
   */
  public function executeCheck(): array {
    try {
      return $this->runCheck();
    } catch (RevisionMergeConflictReasonException $ex) {
      // An explanation this check wrote for the reader of the revision. Every
      // failure is reported as "unknown" rather than a conflict, so we never
      // claim a conflict we couldn't actually prove.
      return $this->newResult(
        DifferentialMergeConflictStatusField::STATUS_UNKNOWN,
        $ex->getMessage());
    } catch (CommandException $ex) {
      // A command exception's message is a multi-line dump of the command line,
      // stdout and stderr. That belongs in the log, not in a field we render to
      // users and hand to Lando, so keep only a summary here.
      phlog($ex);

      return $this->newResult(
        DifferentialMergeConflictStatusField::STATUS_UNKNOWN,
        pht(
          'A git command failed while checking mergeability. See the daemon '.
          'log for details.'));
    } catch (Exception $ex) {
      // Anything else was not written with this audience in mind: a filesystem
      // error naming a server path, a policy exception, a programming error.
      // Log it and say only that we could not answer.
      phlog($ex);

      return $this->newResult(
        DifferentialMergeConflictStatusField::STATUS_UNKNOWN,
        pht(
          'Mergeability could not be determined. See the daemon log for '.
          'details.'));
    }
  }

  private function runCheck(): array {
    if (!$this->repository->isGit()) {
      return $this->newResult(
        DifferentialMergeConflictStatusField::STATUS_UNKNOWN,
        pht('Repository is not a Git repository.'));
    }

    $stack = $this->getStackDiffs();

    $base = $this->resolveBaseCommit();

    $target_tip = $this->resolveTargetTip();

    $this->requireBaseOnTargetBranch($base, $target_tip);

    // Synthesize the stack's tree in a temporary index. The TempFile is held
    // until the method returns so it (and whatever git writes at its path) is
    // cleaned up automatically.
    $temp_index = new TempFile();
    $index_path = (string)$temp_index;

    // Git wants the index file to either not exist or be a valid index; an
    // empty placeholder file would be rejected, so remove it and let git
    // recreate it.
    Filesystem::remove($index_path);

    $revision_tree = $this->synthesizeStackTree($base, $index_path, $stack);

    $status = $this->runMerge($base, $target_tip, $revision_tree);

    return $this->newResult(
      $status,
      $this->newSuccessReason($base, $target_tip),
      $base,
      $target_tip);
  }

/* -(  Stack  )-------------------------------------------------------------- */

  /**
   * Returns the diffs whose patches must be applied to produce the tree this
   * revision would land, ordered bottom-most ancestor first and ending with
   * this revision's own diff.
   */
  public function getStackDiffs(): array {
    if ($this->stackDiffs !== null) {
      return $this->stackDiffs;
    }

    $query = id(new RevisionMergeConflictStackQuery())
      ->setViewer($this->viewer)
      ->setRevision($this->revision);

    $chain = $query->loadAncestorChain();
    $this->stackQuery = $query;
    $this->stackStopReason = $query->getStopReason();

    $diffs = array();
    foreach ($chain as $revision) {
      if ($revision->getPHID() === $this->revision->getPHID()) {
        // Use the caller's diff rather than re-reading the active diff, so the
        // worker can pin the check to the diff it scheduled work for.
        $diffs[] = $this->diff;
      } else {
        $diffs[] = $revision->getActiveDiff();
      }
    }

    $this->stackRevisions = $chain;
    $this->stackDiffs = $diffs;

    return $this->stackDiffs;
  }

  /**
   * Returns the PHIDs of the diffs this check depends on, in stack order. The
   * worker stores these so a change to any revision below this one invalidates
   * the stored result.
   */
  public function getStackDiffPHIDs(): array {
    return mpull($this->getStackDiffs(), 'getPHID');
  }

  /**
   * Returns the commit to merge the stack from: the bottom of the stack's
   * recorded base if it is present in the repository, otherwise the commit that
   * closed the parent revision the stack walk stopped at.
   */
  private function resolveBaseCommit(): string {
    $recorded = head($this->getStackDiffs())->getSourceControlBaseRevision();

    if (phutil_nonempty_string($recorded) && $this->commitExists($recorded)) {
      return $recorded;
    }

    // A missing base usually means a partially-landed stack, where the recorded
    // base is the parent's pre-land commit. Landing rewrites that commit's
    // message but not its tree, so the commit that closed the parent is what the
    // rest of the stack was written on top of.
    $landed = $this->resolveLandedParentCommit();
    if ($landed !== null) {
      return $landed;
    }

    if (!phutil_nonempty_string($recorded)) {
      throw new RevisionMergeConflictReasonException(
        $this->describeDiffProblem(0, pht('has no recorded base revision')));
    }

    if ($this->stackStopReason !== null) {
      throw new RevisionMergeConflictReasonException($this->stackStopReason);
    }

    throw new RevisionMergeConflictReasonException(
      pht(
        'The stack is based on commit "%s", which is not present in the '.
        'repository.',
        $recorded));
  }

  /**
   * Returns the identifier of a commit that closed the landed parent revision
   * and is present in the repository, or `null` if there is no such parent or
   * none of its commits can be resolved.
   */
  private function resolveLandedParentCommit(): ?string {
    if (!$this->stackQuery) {
      return null;
    }

    $parent = $this->stackQuery->getLandedParent();
    if (!$parent) {
      return null;
    }

    $commit_phids = PhabricatorEdgeQuery::loadDestinationPHIDs(
      $parent->getPHID(),
      DifferentialRevisionHasCommitEdgeType::EDGECONST);
    if (!$commit_phids) {
      return null;
    }

    $commits = id(new DiffusionCommitQuery())
      ->setViewer($this->viewer)
      ->withPHIDs($commit_phids)
      ->withRepository($this->repository)
      ->execute();
    if (!$commits) {
      return null;
    }

    // A revision can be associated with more than one commit (a reland, say).
    // The most recent one is the state the stack above it sits on.
    $commits = msort($commits, 'getEpoch');
    $commits = array_reverse($commits);

    foreach ($commits as $commit) {
      $identifier = $commit->getCommitIdentifier();
      if (!phutil_nonempty_string($identifier)) {
        continue;
      }

      if ($this->commitExists($identifier)) {
        $this->baseFromLandedParent = $parent;
        return $identifier;
      }
    }

    return null;
  }

  private function commitExists(string $commit): bool {
    try {
      // `cat-file -e` exits non-zero if the object is missing.
      $this->newGitFuture(
        'cat-file -e %s',
        $commit.'^{commit}')
        ->resolvex();
      return true;
    } catch (CommandException $ex) {
      return false;
    }
  }

  /**
   * Renders one diff as a git patch, spending at most `$byte_limit` bytes. The
   * caller passes what is left of the stack's budget, so a tall stack of large
   * diffs is refused rather than being assembled in memory.
   */
  private function renderGitPatch(
    DifferentialDiff $diff,
    int $position,
    int $byte_limit): string {

    // The renderer treats a limit of zero as "no limit", so an exhausted budget
    // has to stop here rather than being passed through.
    if ($byte_limit < 1) {
      throw new RevisionMergeConflictReasonException(
        $this->newPatchTooLargeMessage($position));
    }

    $loaded_diff = id(new DifferentialDiffQuery())
      ->setViewer($this->viewer)
      ->withIDs(array($diff->getID()))
      ->needChangesets(true)
      ->executeOne();

    if (!$loaded_diff) {
      throw new RevisionMergeConflictReasonException(
        pht('Failed to reload diff %d with changesets.', $diff->getID()));
    }

    try {
      return id(new DifferentialRawDiffRenderer())
        ->setViewer($this->viewer)
        ->setChangesets($loaded_diff->getChangesets())
        ->setFormat('git')
        ->setByteLimit($byte_limit)
        ->buildPatch();
    } catch (ArcanistDiffByteSizeException $ex) {
      throw new RevisionMergeConflictReasonException(
        $this->newPatchTooLargeMessage($position));
    }
  }

  private function newPatchTooLargeMessage(int $position): string {
    return $this->describeDiffProblem(
      $position,
      pht(
        'is too large to check (the stack may use at most %s bytes of patch '.
        'text)',
        new PhutilNumber(self::MAX_PATCH_BYTES)));
  }

  public function resolveTargetTip(): string {
    $branch = $this->repository->getDefaultBranch();
    if (!phutil_nonempty_string($branch)) {
      throw new RevisionMergeConflictReasonException(
        pht('Repository has no default branch.'));
    }

    list($stdout) = $this->newGitFuture(
      'rev-parse --verify %s',
      'refs/heads/'.$branch.'^{commit}')
      ->resolvex();

    return trim($stdout);
  }

  /**
   * Refuses to answer unless the base is on the target branch.
   *
   * `commitExists` only proves the object is in the store, and we then force it
   * as the merge base. A base outside the target branch's history -- a revision
   * written on another branch, or a commit that was force-pushed away -- makes
   * everything the branch gained since the real fork point look like a
   * conflicting edit, which is exactly the false `conflict` we promise never to
   * report.
   */
  private function requireBaseOnTargetBranch(
    string $base,
    string $target_tip): void {

    $future = $this->newGitFuture(
      'merge-base --is-ancestor %s %s',
      $base,
      $target_tip);

    list($err) = $future->resolve();

    if (self::isAncestorExitCode($err)) {
      return;
    }

    if ($future->getWasKilledByTimeout()) {
      throw new RevisionMergeConflictReasonException(
        pht(
          '"git merge-base" did not finish within %s seconds.',
          new PhutilNumber(self::GIT_TIMEOUT_SECONDS)));
    }

    throw new RevisionMergeConflictReasonException(
      pht(
        'The stack is based on commit "%s", which is not an ancestor of the '.
        'target branch. Rebase onto the target branch to check it.',
        $base));
  }

  /**
   * Interprets the exit code of `git merge-base --is-ancestor`. Only a zero
   * exit proves ancestry: `1` means the commit is not an ancestor, and anything
   * else means git could not tell us, which we treat the same way.
   */
  public static function isAncestorExitCode(int $err): bool {
    return ($err === 0);
  }

/* -(  Git merge  )---------------------------------------------------------- */

  private function synthesizeStackTree(
    string $base,
    string $index_path,
    array $stack): string {
    $this->newIndexCommandFuture($index_path, 'read-tree %s', $base)
      ->resolvex();

    // One budget for the whole stack, since a tall stack of moderately large
    // diffs costs just as much memory as one enormous diff.
    $remaining_bytes = self::MAX_PATCH_BYTES;

    foreach ($stack as $position => $diff) {
      $patch = $this->renderGitPatch($diff, $position, $remaining_bytes);
      if (!phutil_nonempty_string($patch)) {
        throw new RevisionMergeConflictReasonException(
          $this->describeDiffProblem($position, pht('produced an empty patch')));
      }

      $remaining_bytes -= strlen($patch);

      $apply_future = $this->newIndexCommandFuture(
        $index_path,
        'apply --cached --whitespace=nowarn');
      $apply_future->write($patch);

      try {
        $apply_future->resolvex();
      } catch (CommandException $ex) {
        if ($apply_future->getWasKilledByTimeout()) {
          throw new RevisionMergeConflictReasonException(
            $this->describeDiffProblem(
              $position,
              pht(
                'took longer than %s seconds to apply',
                new PhutilNumber(self::GIT_TIMEOUT_SECONDS))));
        }

        throw new RevisionMergeConflictReasonException(
          $this->describeDiffProblem(
            $position,
            pht('does not apply on top of the stack base')));
      }
    }

    list($stdout) = $this->newIndexCommandFuture($index_path, 'write-tree')
      ->resolvex();

    return trim($stdout);
  }

  /**
   * 3-way merges the synthesized tree against the target branch tip.
   *
   * `merge-tree --write-tree` reports the verdict through its exit code: 0 for a
   * clean merge, 1 for a conflict. Anything else is a git failure rather than an
   * answer, so it becomes `unknown` rather than a guess.
   */
  private function runMerge(
    string $base,
    string $target_tip,
    string $revision_tree): string {

    $this->assertModernGit();

    // `merge-tree` operates on commits, so wrap the synthesized tree in one
    // parented on the base. A fixed identity keeps the hash deterministic.
    $commit_future = $this->newGitFuture(
      'commit-tree %s -p %s -m %s',
      $revision_tree,
      $base,
      'merge-check');
    $this->applyDeterministicIdentity($commit_future);
    list($commit_stdout) = $commit_future->resolvex();
    $revision_commit = trim($commit_stdout);

    // Pass the merge base explicitly. Left to itself git would compute the
    // common ancestor of the two commits, which is not what a rebased or
    // uplifted revision should be merged against.
    $merge_future = $this->newGitFuture(
      'merge-tree --write-tree --merge-base=%s --name-only %s %s',
      $base,
      $target_tip,
      $revision_commit);

    list($err) = $merge_future->resolve();

    // A timed-out command is killed by a signal, and the resulting exit code
    // must never be read as a merge verdict.
    if ($merge_future->getWasKilledByTimeout()) {
      throw new RevisionMergeConflictReasonException(
        pht(
          '"git merge-tree" did not finish within %s seconds.',
          new PhutilNumber(self::GIT_TIMEOUT_SECONDS)));
    }

    switch ($err) {
      case 0:
        return DifferentialMergeConflictStatusField::STATUS_CLEAN;
      case 1:
        return DifferentialMergeConflictStatusField::STATUS_CONFLICT;
      default:
        throw new RevisionMergeConflictReasonException(
          pht('Unexpected "git merge-tree" exit code: %d.', $err));
    }
  }

/* -(  Git helpers  )-------------------------------------------------------- */

  /**
   * Builds a git command future with a time limit, so a wedged command fails
   * the check instead of pinning a taskmaster. Every git invocation in this
   * class goes through here.
   */
  private function newGitFuture($pattern /* , ... */): ExecFuture {
    $args = func_get_args();
    $future = call_user_func_array(
      array($this->repository, 'getLocalCommandFuture'),
      $args);
    $future->setTimeout(self::GIT_TIMEOUT_SECONDS);
    return $future;
  }

  private function newIndexCommandFuture(
    string $index_path,
    $pattern /* , ... */): ExecFuture {
    $args = array_slice(func_get_args(), 1);
    $future = call_user_func_array(array($this, 'newGitFuture'), $args);
    $future->updateEnv('GIT_INDEX_FILE', $index_path);
    return $future;
  }

  private function applyDeterministicIdentity(ExecFuture $future): void {
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

  /**
   * `merge-tree --write-tree` needs git 2.38 and `--merge-base` needs 2.40. The
   * image build asserts this, so failing here means git was changed underneath
   * us; say so plainly rather than reporting a bare non-zero exit code.
   */
  private function assertModernGit(): void {
    $version = $this->getGitVersion();

    if (version_compare($version, self::MINIMUM_GIT_VERSION, '<')) {
      throw new RevisionMergeConflictReasonException(
        pht(
          'Merge conflict detection requires git %s or newer, but this '.
          'repository is using git %s.',
          self::MINIMUM_GIT_VERSION,
          $version));
    }
  }

  private function getGitVersion(): string {
    list($stdout) = $this->newGitFuture('--version')->resolvex();
    return self::parseGitVersion($stdout);
  }

  /**
   * Extracts a `major.minor.patch` version from `git --version` output.
   * Unparseable output returns `0.0.0`, which fails the minimum-version check
   * rather than being assumed good.
   */
  public static function parseGitVersion(string $stdout): string {
    $matches = null;
    if (preg_match('/git version (\d+\.\d+\.\d+)/', $stdout, $matches)) {
      return $matches[1];
    }
    return '0.0.0';
  }

/* -(  Results  )------------------------------------------------------------ */

  private function newResult(
    string $status,
    string $reason,
    ?string $base_commit = null,
    ?string $target_commit = null): array {
    return array(
      'status' => $status,
      'reason' => $reason,
      'baseCommit' => $base_commit,
      'targetCommit' => $target_commit,
    );
  }

  /**
   * Explains what was merged, naming both commits so the answer can be
   * reproduced by hand long after the branch has moved on.
   */
  private function newSuccessReason(string $base, string $target_tip): string {
    $parent_count = count($this->getStackDiffs()) - 1;
    $base_name = $this->formatCommitName($base);
    $target_name = $this->formatCommitName($target_tip);

    if ($this->baseFromLandedParent) {
      if (!$parent_count) {
        return pht(
          'Merged against target branch tip %s, starting from %s, the commit '.
          'that landed %s.',
          $target_name,
          $base_name,
          $this->baseFromLandedParent->getMonogram());
      }

      return pht(
        'Merged against target branch tip %s, starting from %s, the commit '.
        'that landed %s, with %s parent revision(s) applied first.',
        $target_name,
        $base_name,
        $this->baseFromLandedParent->getMonogram(),
        new PhutilNumber($parent_count));
    }

    if (!$parent_count) {
      return pht(
        'Merged against target branch tip %s, starting from %s.',
        $target_name,
        $base_name);
    }

    return pht(
      'Merged against target branch tip %s, starting from %s, with %s parent '.
      'revision(s) applied first.',
      $target_name,
      $base_name,
      new PhutilNumber($parent_count));
  }

  /**
   * Abbreviates a commit the way the rest of the interface does, so a hash in
   * a merge check message is recognisable next to one in Diffusion.
   */
  private function formatCommitName(string $commit): string {
    return $this->repository->formatCommitName($commit, true);
  }

  /**
   * Names the revision at a position in the stack when explaining a failure, so
   * an `unknown` result says which revision was the problem.
   */
  private function describeDiffProblem(int $position, string $problem): string {
    $revision = idx($this->stackRevisions, $position);

    if (!$revision || $revision->getPHID() === $this->revision->getPHID()) {
      return pht('The patch for this revision %s.', $problem);
    }

    return pht(
      'The patch for parent revision %s %s.',
      $revision->getMonogram(),
      $problem);
  }

}
