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
