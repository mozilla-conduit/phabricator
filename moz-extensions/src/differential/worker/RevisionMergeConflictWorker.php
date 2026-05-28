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

    $result = id(new RevisionMergeConflictEngine())
      ->setViewer($viewer)
      ->setRevision($revision)
      ->setDiff($active_diff)
      ->setRepository($repository)
      ->executeCheck();

    $this->writeResult($revision, $active_diff, $result);
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
