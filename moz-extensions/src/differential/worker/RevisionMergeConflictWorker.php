<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * Recomputes a revision's merge-conflict status against its target branch and
 * stores the result on the revision.
 *
 * Scheduled on two edges (like a GitHub PR mergeability recompute): when a
 * commit lands on the branch (see `PhabricatorRepositoryCommitPublishWorker`)
 * and when a revision receives a new diff (see `DifferentialTransactionEditor`).
 *
 * Task data: `revisionPHID` (required), `diffPHID` (the active diff at schedule
 * time, used to drop stale work), `triggerCommit` (optional, for logging).
 */
final class RevisionMergeConflictWorker extends PhabricatorWorker {

  public function getMaximumRetryCount() {
    return 2;
  }

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

    $this->writeResult($revision, $active_diff, $result);
  }

  /**
   * Returns true if the stored status is already definitive for the revision's
   * current active diff and the current target-branch tip, so recomputing would
   * produce an identical result.
   *
   * Only definitive (`clean`/`conflict`) results record a target commit, so an
   * `unknown` result never short-circuits a retry.
   */
  private function isResultCurrent(
    DifferentialRevision $revision,
    DifferentialDiff $diff,
    RevisionMergeConflictEngine $engine) {

    $stored = id(new DifferentialMergeConflictStatusField())
      ->readStoredValueForObject($revision->getPHID());
    if (!is_array($stored)) {
      return false;
    }

    $stored_diff_phid = idx(
      $stored,
      DifferentialMergeConflictStatusField::KEY_DIFF_PHID);
    if ($stored_diff_phid !== $diff->getPHID()) {
      return false;
    }

    $stored_tip = idx(
      $stored,
      DifferentialMergeConflictStatusField::KEY_TARGET_COMMIT);
    if (!$stored_tip) {
      return false;
    }

    try {
      $current_tip = $engine->resolveTargetTip();
    } catch (Exception $ex) {
      // If we can't resolve the tip cheaply, don't skip; let the full check
      // run and record an `unknown`.
      return false;
    }

    return ($stored_tip === $current_tip);
  }

  private function loadRepository(
    PhabricatorUser $viewer,
    DifferentialRevision $revision) {

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
    array $result) {

    $value = array(
      DifferentialMergeConflictStatusField::KEY_STATUS =>
        $result['status'],
      DifferentialMergeConflictStatusField::KEY_REASON =>
        idx($result, 'reason'),
      DifferentialMergeConflictStatusField::KEY_TARGET_COMMIT =>
        idx($result, 'targetCommit'),
      DifferentialMergeConflictStatusField::KEY_DIFF_PHID =>
        $diff->getPHID(),
      DifferentialMergeConflictStatusField::KEY_DIFF_ID =>
        $diff->getID(),
      DifferentialMergeConflictStatusField::KEY_EPOCH =>
        PhabricatorTime::getNow(),
    );

    id(new DifferentialMergeConflictStatusField())
      ->writeStatusForObject($revision->getPHID(), $value);
  }

}
