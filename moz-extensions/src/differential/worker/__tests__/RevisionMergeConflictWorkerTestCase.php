<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

final class RevisionMergeConflictWorkerTestCase extends PhabricatorTestCase {

  public function testDisabledGloballyChecksNothing() {
    $repository = $this->newRepo();
    $env = $this->configure(false, array($repository->getPHID()));

    $this->assertFalse(
      RevisionMergeConflictWorker::isEnabledForRepository($repository),
      pht('Nothing should be checked while the feature is off.'));
  }

  public function testEnabledWithAnEmptyListChecksNothing() {
    $repository = $this->newRepo();
    $env = $this->configure(true, array());

    $this->assertFalse(
      RevisionMergeConflictWorker::isEnabledForRepository($repository),
      pht(
        'An empty repository list should check nothing, so turning the '.
        'feature on never starts checking a repository nobody asked about.'));
  }

  public function testRepositoryMatchesByPHID() {
    $repository = $this->newRepo();
    $env = $this->configure(true, array($repository->getPHID()));

    $this->assertTrue(
      RevisionMergeConflictWorker::isEnabledForRepository($repository),
      pht('A PHID in the list should enable that repository.'));
  }

  public function testRepositoryIsNotMatchedByCallsign() {
    $repository = $this->newRepo();
    $env = $this->configure(true, array($repository->getCallsign()));

    $this->assertFalse(
      RevisionMergeConflictWorker::isEnabledForRepository($repository),
      pht(
        'The list holds PHIDs, so a callsign should not enable a repository '.
        'and cannot be mistaken for one.'));
  }

  public function testRepositoryNotInTheListIsSkipped() {
    $repository = $this->newRepo();
    $env = $this->configure(true, array('PHID-REPO-someotherrepo'));

    $this->assertFalse(
      RevisionMergeConflictWorker::isEnabledForRepository($repository),
      pht(
        'A repository absent from a non-empty list should not be checked, so '.
        'a staged rollout stays limited to the repositories named.'));
  }

  public function testNonGitRepositoryIsSkipped() {
    $repository = $this->newRepo(
      PhabricatorRepositoryType::REPOSITORY_TYPE_MERCURIAL);
    $env = $this->configure(true, array($repository->getPHID()));

    $this->assertFalse(
      RevisionMergeConflictWorker::isEnabledForRepository($repository),
      pht(
        'The check performs a real `git` merge, so naming a repository using '.
        'another version control system should not enable it.'));
  }

  public function testTaskDataPinsTheCheckToADiff() {
    $data = RevisionMergeConflictWorker::newTaskData(
      'PHID-DREV-1',
      'PHID-DIFF-1');

    $this->assertEqual(
      'PHID-DREV-1',
      idx($data, 'revisionPHID'),
      pht('The task should name the revision to check.'));

    $this->assertEqual(
      'PHID-DIFF-1',
      idx($data, 'diffPHID'),
      pht(
        'The task should name the diff it was queued for, so it can be '.
        'dropped once a newer diff is attached.'));

    $this->assertFalse(
      array_key_exists('triggerCommit', $data),
      pht(
        'A check queued without a triggering commit should not carry an '.
        'empty one.'));
  }

  public function testTaskDataRecordsTheTriggeringCommit() {
    $data = RevisionMergeConflictWorker::newTaskData(
      'PHID-DREV-1',
      'PHID-DIFF-1',
      'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');

    $this->assertEqual(
      'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
      idx($data, 'triggerCommit'),
      pht(
        'A check queued by a landing should record the commit that triggered '.
        'it, so the daemon log says why the check ran.'));
  }

  public function testStoredResultIsCurrentForUnchangedInputs() {
    $this->assertTrue(
      RevisionMergeConflictWorker::isStoredResultCurrent(
        $this->newStoredResult(),
        'PHID-DIFF-active',
        array('PHID-DIFF-parent', 'PHID-DIFF-active'),
        'ffffffffffffffffffffffffffffffffffffffff'),
      pht(
        'A stored result computed from the current diff, stack and branch tip '.
        'should not be recomputed.'));
  }

  public function testStoredResultForAnotherDiffIsNotCurrent() {
    $this->assertFalse(
      RevisionMergeConflictWorker::isStoredResultCurrent(
        $this->newStoredResult(),
        'PHID-DIFF-newer',
        array('PHID-DIFF-parent', 'PHID-DIFF-newer'),
        'ffffffffffffffffffffffffffffffffffffffff'),
      pht('A result stored for an older diff should be recomputed.'));
  }

  public function testStoredResultIsNotCurrentWhenTheStackChanges() {
    $this->assertFalse(
      RevisionMergeConflictWorker::isStoredResultCurrent(
        $this->newStoredResult(),
        'PHID-DIFF-active',
        array('PHID-DIFF-newer-parent', 'PHID-DIFF-active'),
        'ffffffffffffffffffffffffffffffffffffffff'),
      pht(
        'A revision below this one receiving a new diff changes what this '.
        'revision would land on, so the answer should be recomputed.'));
  }

  public function testStoredResultIsNotCurrentWhenTheBranchMoves() {
    $this->assertFalse(
      RevisionMergeConflictWorker::isStoredResultCurrent(
        $this->newStoredResult(),
        'PHID-DIFF-active',
        array('PHID-DIFF-parent', 'PHID-DIFF-active'),
        'cccccccccccccccccccccccccccccccccccccccc'),
      pht(
        'A result computed against an older branch tip should be recomputed.'));
  }

  public function testStoredUnknownResultIsNeverCurrent() {
    $stored = $this->newStoredResult();
    $stored[DifferentialMergeConflictStatusField::KEY_STATUS] =
      DifferentialMergeConflictStatusField::STATUS_UNKNOWN;
    $stored[DifferentialMergeConflictStatusField::KEY_TARGET_COMMIT] = null;

    $this->assertFalse(
      RevisionMergeConflictWorker::isStoredResultCurrent(
        $stored,
        'PHID-DIFF-active',
        array('PHID-DIFF-parent', 'PHID-DIFF-active'),
        'ffffffffffffffffffffffffffffffffffffffff'),
      pht(
        'Only a definitive result records a target commit, so an `unknown` '.
        'result should never stop a retry.'));
  }

  /**
   * A stored `clean` verdict for diff `PHID-DIFF-active`, sitting on one parent
   * revision, computed against branch tip `ffff...`.
   */
  private function newStoredResult(): array {
    return array(
      DifferentialMergeConflictStatusField::KEY_STATUS =>
        DifferentialMergeConflictStatusField::STATUS_CLEAN,
      DifferentialMergeConflictStatusField::KEY_DIFF_PHID =>
        'PHID-DIFF-active',
      DifferentialMergeConflictStatusField::KEY_TARGET_COMMIT =>
        'ffffffffffffffffffffffffffffffffffffffff',
      DifferentialMergeConflictStatusField::KEY_STACK_DIFF_PHIDS =>
        array('PHID-DIFF-parent', 'PHID-DIFF-active'),
    );
  }

  /**
   * Returns the scoped environment, which the caller has to hold in a local so
   * the override survives until the test method returns.
   */
  private function configure(
    bool $enabled,
    array $repository_phids): PhabricatorScopedEnv {
    $env = PhabricatorEnv::beginScopedEnv();

    $env->overrideEnvConfig(
      MergeConflictConfigOptions::OPTION_ENABLED,
      $enabled);
    $env->overrideEnvConfig(
      MergeConflictConfigOptions::OPTION_REPOSITORIES,
      $repository_phids);

    return $env;
  }

  private function newRepo(
    string $version_control_system =
      PhabricatorRepositoryType::REPOSITORY_TYPE_GIT): PhabricatorRepository {

    return id(new PhabricatorRepository())
      ->setID(49)
      ->setPHID('PHID-REPO-testrepo')
      ->setCallsign('TESTREPO')
      ->setVersionControlSystem($version_control_system);
  }

}
