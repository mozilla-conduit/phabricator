<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * Exercises queueing against real worker storage, since the check for an
 * already-waiting task is a query against the task tables.
 */
final class RevisionMergeConflictWorkerQueueTestCase
  extends PhabricatorTestCase {

  protected function getPhabricatorTestCaseConfiguration() {
    return array(
      self::PHABRICATOR_TESTCONFIG_BUILD_STORAGE_FIXTURES => true,
    );
  }

  protected function willRunOneTest($test) {
    parent::willRunOneTest($test);

    // Migrations may have queued tasks, so start every test from an empty
    // queue.
    $task_table = new PhabricatorWorkerActiveTask();
    queryfx(
      $task_table->establishConnection('w'),
      'TRUNCATE %R',
      $task_table);
  }

  public function testWaitingCheckIsNotQueuedTwice() {
    RevisionMergeConflictWorker::queueCheck('PHID-DREV-1', 'PHID-DIFF-1');
    RevisionMergeConflictWorker::queueCheck(
      'PHID-DREV-1',
      'PHID-DIFF-1',
      'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');

    $this->assertEqual(
      1,
      count($this->loadQueuedTasks()),
      pht(
        'A second check for the same revision and diff should not be queued '.
        'while the first is still waiting.'));
  }

  public function testCheckForANewDiffIsQueued() {
    RevisionMergeConflictWorker::queueCheck('PHID-DREV-1', 'PHID-DIFF-1');
    RevisionMergeConflictWorker::queueCheck('PHID-DREV-1', 'PHID-DIFF-2');

    $this->assertEqual(
      2,
      count($this->loadQueuedTasks()),
      pht('A check for a newer diff should be queued alongside the old one.'));
  }

  public function testCheckIsQueuedWhenTheWaitingOneIsLeased() {
    RevisionMergeConflictWorker::queueCheck('PHID-DREV-1', 'PHID-DIFF-1');

    $task = head($this->loadQueuedTasks());
    $task->setLeaseOwner('test');
    $task->setLeaseExpires(time() + 3600);
    $task->forceSaveWithoutLease();

    RevisionMergeConflictWorker::queueCheck('PHID-DREV-1', 'PHID-DIFF-1');

    $this->assertEqual(
      2,
      count($this->loadQueuedTasks()),
      pht(
        'A leased check may have resolved the branch tip before this change, '.
        'so another check should be queued.'));
  }

  public function testCheckIsQueuedWhenTheWaitingOneHasLowerPriority() {
    RevisionMergeConflictWorker::queueCheck('PHID-DREV-1', 'PHID-DIFF-1');

    $task = head($this->loadQueuedTasks());
    $task->setPriority(PhabricatorWorker::PRIORITY_IMPORT);
    $task->forceSaveWithoutLease();

    RevisionMergeConflictWorker::queueCheck('PHID-DREV-1', 'PHID-DIFF-1');

    $this->assertEqual(
      2,
      count($this->loadQueuedTasks()),
      pht(
        'A check queued behind a lower-priority backfill should not wait for '.
        'it.'));
  }

  private function loadQueuedTasks(): array {
    return id(new PhabricatorWorkerActiveTask())->loadAllWhere(
      'taskClass = %s',
      'RevisionMergeConflictWorker');
  }

}
