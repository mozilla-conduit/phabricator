<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * Recomputes a revision's merge-conflict status against its target branch and
 * stores the result on the revision.
 *
 * Scheduled on the edges where an answer can change (like a GitHub PR
 * mergeability recompute): a commit landing on the branch (see
 * `PhabricatorRepositoryCommitPublishWorker`), a revision receiving a new diff,
 * a revision closing or reopening, and the stack being re-wired (see
 * `DifferentialTransactionEditor`). Because a revision lands on top of its
 * stack, every one of those edges also fans out to the revisions stacked above
 * the one that changed.
 *
 * Task data: `revisionPHID` (required), `diffPHID` (the active diff at schedule
 * time, used to drop stale work), `triggerCommit` (optional, for logging).
 */
final class RevisionMergeConflictWorker extends PhabricatorWorker {

  public function getMaximumRetryCount() {
    return 2;
  }

/* -(  Configuration  )------------------------------------------------------ */

  /**
   * Whether merge conflict detection is turned on for a repository.
   *
   * Every repository has to be named explicitly: an empty list checks nothing,
   * so turning the feature on can never start checking a repository nobody
   * asked about. The merge is a real `git` 3-way merge, so a repository using
   * another version control system is never checked either.
   *
   * Checked both when scheduling work and again when running it, so disabling
   * the feature stops new checks and discards anything already queued.
   */
  public static function isEnabledForRepository(
    PhabricatorRepository $repository): bool {

    $enabled = PhabricatorEnv::getEnvConfig(
      MergeConflictConfigOptions::OPTION_ENABLED);
    if (!$enabled) {
      return false;
    }

    if (!$repository->isGit()) {
      return false;
    }

    $allowed_phids = PhabricatorEnv::getEnvConfig(
      MergeConflictConfigOptions::OPTION_REPOSITORIES);
    if (!$allowed_phids) {
      return false;
    }

    return in_array($repository->getPHID(), $allowed_phids, true);
  }

/* -(  Scheduling  )--------------------------------------------------------- */

  /**
   * Queues checks for the given open revisions and for every revision stacked
   * above them, whose mergeability depends on the revisions below.
   */
  public static function queueChecks(
    PhabricatorUser $viewer,
    array $revision_phids,
    ?string $trigger_commit = null): void {

    if (!$revision_phids) {
      return;
    }

    $descendant_phids = RevisionMergeConflictStackQuery::loadDescendantPHIDs(
      $revision_phids);

    self::queueChecksForPHIDs(
      $viewer,
      array_merge($revision_phids, $descendant_phids),
      $trigger_commit);
  }

  /**
   * Queues checks for the revisions stacked above the given revisions, but not
   * for the given revisions themselves. Callers that are mid-edit already know
   * which diff to check the edited revision against and should schedule that
   * one with `queueCheck`.
   */
  public static function queueDescendantChecks(
    PhabricatorUser $viewer,
    array $revision_phids): void {

    if (!$revision_phids) {
      return;
    }

    self::queueChecksForPHIDs(
      $viewer,
      RevisionMergeConflictStackQuery::loadDescendantPHIDs($revision_phids));
  }

  private static function queueChecksForPHIDs(
    PhabricatorUser $viewer,
    array $revision_phids,
    ?string $trigger_commit = null): void {

    if (!$revision_phids) {
      return;
    }

    $revisions = id(new DifferentialRevisionQuery())
      ->setViewer($viewer)
      ->withPHIDs($revision_phids)
      ->withIsOpen(true)
      ->needActiveDiffs(true)
      ->execute();

    foreach ($revisions as $revision) {
      $active_diff = $revision->getActiveDiff();
      if (!$active_diff) {
        continue;
      }

      self::queueCheck(
        $revision->getPHID(),
        $active_diff->getPHID(),
        $trigger_commit);
    }
  }

  /**
   * Queues a check for a single revision against a specific diff. Callers that
   * are mid-edit and already hold the new diff should use this so the task is
   * pinned to that diff rather than to whatever the database currently says.
   */
  public static function queueCheck(
    string $revision_phid,
    string $diff_phid,
    ?string $trigger_commit = null): void {

    self::scheduleTask(
      'RevisionMergeConflictWorker',
      self::newTaskData($revision_phid, $diff_phid, $trigger_commit),
      array(
        'objectPHID' => $revision_phid,
        // Run at bulk priority so a wide fan-out (a commit touching a popular
        // file, or a tall stack) doesn't starve more important queued work
        // like mail, commit import and Herald.
        'priority' => self::PRIORITY_BULK,
      ));
  }

  /**
   * Builds the task data a queued check runs from. The diff is recorded so a
   * task that has been overtaken by a newer diff can be dropped instead of
   * writing an outdated result, and the triggering commit is only recorded
   * when there is one, since it exists for logging alone.
   */
  public static function newTaskData(
    string $revision_phid,
    string $diff_phid,
    ?string $trigger_commit = null): array {

    $data = array(
      'revisionPHID' => $revision_phid,
      'diffPHID' => $diff_phid,
    );

    if ($trigger_commit !== null) {
      $data['triggerCommit'] = $trigger_commit;
    }

    return $data;
  }

/* -(  Execution  )---------------------------------------------------------- */

  protected function doWork() {
    $viewer = PhabricatorUser::getOmnipotentUser();

    $revision_phid = $this->getTaskDataValue('revisionPHID');
    if (!$revision_phid) {
      return;
    }

    $revision = id(new DifferentialRevisionQuery())
      ->setViewer($viewer)
      ->withPHIDs(array($revision_phid))
      ->needActiveDiffs(true)
      ->executeOne();
    if (!$revision) {
      return;
    }

    // Closed/abandoned revisions won't land, so there's nothing to check.
    if ($revision->isClosed() || $revision->isAbandoned()) {
      return;
    }

    $active_diff = $revision->getActiveDiff();
    if (!$active_diff) {
      return;
    }

    // If a newer diff has since been attached, this task is stale: a fresh
    // check is (or will be) queued for the current diff, so skip writing an
    // outdated result.
    $scheduled_diff_phid = $this->getTaskDataValue('diffPHID');
    if ($scheduled_diff_phid &&
        $scheduled_diff_phid !== $active_diff->getPHID()) {
      return;
    }

    $repository = $this->loadRepository($viewer, $revision);
    if (!$repository) {
      return;
    }

    if (!self::isEnabledForRepository($repository)) {
      return;
    }

    $engine = id(new RevisionMergeConflictEngine())
      ->setViewer($viewer)
      ->setRevision($revision)
      ->setDiff($active_diff)
      ->setRepository($repository);

    // Avoid recomputing a result we already have. A burst of landings on a
    // branch can queue many tasks for the same revision; since each task
    // resolves the branch tip live, they would otherwise all recompute the
    // same answer.
    if ($this->isResultCurrent($revision, $active_diff, $engine)) {
      return;
    }

    $result = $engine->executeCheck();

    $this->writeResult($revision, $active_diff, $engine, $result);
  }

  /**
   * Returns true if the revision's stored status was computed from the inputs a
   * fresh check would use, so recomputing it would produce an identical result.
   */
  private function isResultCurrent(
    DifferentialRevision $revision,
    DifferentialDiff $diff,
    RevisionMergeConflictEngine $engine): bool {

    $stored = id(new DifferentialMergeConflictStatusField())
      ->readStoredValueForObject($revision->getPHID());
    if (!is_array($stored)) {
      return false;
    }

    try {
      $current_stack = $engine->getStackDiffPHIDs();
      $current_tip = $engine->resolveTargetTip();
    } catch (Exception $ex) {
      // If we can't cheaply establish the current inputs, don't skip; let the
      // full check run and record an `unknown`.
      return false;
    }

    return self::isStoredResultCurrent(
      $stored,
      $diff->getPHID(),
      $current_stack,
      $current_tip);
  }

  /**
   * Whether a stored status is already definitive for the given inputs: the
   * revision's active diff, the diffs of every revision below it in the stack,
   * and the current target-branch tip.
   *
   * Only definitive (`clean`/`conflict`) results record a target commit, so an
   * `unknown` result never short-circuits a retry.
   */
  public static function isStoredResultCurrent(
    array $stored,
    string $diff_phid,
    array $current_stack,
    string $current_tip): bool {

    $stored_diff_phid = idx(
      $stored,
      DifferentialMergeConflictStatusField::KEY_DIFF_PHID);
    if ($stored_diff_phid !== $diff_phid) {
      return false;
    }

    $stored_tip = idx(
      $stored,
      DifferentialMergeConflictStatusField::KEY_TARGET_COMMIT);
    if (!$stored_tip) {
      return false;
    }

    $stored_stack = idx(
      $stored,
      DifferentialMergeConflictStatusField::KEY_STACK_DIFF_PHIDS);
    if ($stored_stack !== $current_stack) {
      return false;
    }

    return ($stored_tip === $current_tip);
  }

  private function loadRepository(
    PhabricatorUser $viewer,
    DifferentialRevision $revision): ?PhabricatorRepository {

    $repository_phid = $revision->getRepositoryPHID();
    if (!$repository_phid) {
      return null;
    }

    return id(new PhabricatorRepositoryQuery())
      ->setViewer($viewer)
      ->withPHIDs(array($repository_phid))
      ->executeOne();
  }

  private function writeResult(
    DifferentialRevision $revision,
    DifferentialDiff $diff,
    RevisionMergeConflictEngine $engine,
    array $result): void {

    // The stack may not have resolved at all (that is itself a reason for an
    // `unknown` result), in which case there is nothing to record.
    try {
      $stack_diff_phids = $engine->getStackDiffPHIDs();
    } catch (Exception $ex) {
      $stack_diff_phids = null;
    }

    $value = DifferentialMergeConflictStatusField::newStatusValue(
      $result,
      $diff,
      $stack_diff_phids,
      PhabricatorTime::getNow());

    id(new DifferentialMergeConflictStatusField())
      ->writeStatusForObject($revision->getPHID(), $value);
  }

}
