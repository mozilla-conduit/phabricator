<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

final class ReviewHelperAutoRequestWorkerTestCase
  extends ReviewHelperAutoRequestFixtureTestCase {

  public function testPublicRevisionIsRequested() {
    $env = $this->configureReviewHelper();
    $revision = $this->newDraftRevision($this->generateNewTestUser());

    $this->assertTrue(
      ReviewHelperAutoRequestWorker::shouldRequestReview($revision),
      pht('A public revision should be sent to Review Helper.'));
  }

  public function testNewPrivateRevisionIsNotRequested() {
    $env = $this->configureReviewHelper();
    $revision = $this->makePrivate(
      $this->newDraftRevision($this->generateNewTestUser()));

    $this->assertTrue(
      ReviewHelperService::isEligibleForReview($revision, true),
      pht(
        'An author may request a review of a new private revision, since '.
        'phab-bot may not have set its view policy yet.'));
    $this->assertFalse(
      ReviewHelperAutoRequestWorker::shouldRequestReview($revision),
      pht(
        'An automatic request should never send a private revision, even '.
        'a new one.'));
  }

  public function testRevisionOutsideAllowedRepositoriesIsNotRequested() {
    $env = $this->configureReviewHelper();
    $env->overrideEnvConfig(
      'reviewhelper.repository-phids',
      array($this->newRepository()->getPHID()));
    $revision = $this->newDraftRevision($this->generateNewTestUser());

    $this->assertFalse(
      ReviewHelperAutoRequestWorker::shouldRequestReview($revision),
      pht(
        'A revision outside `reviewhelper.repository-phids` should not be '.
        'sent to Review Helper.'));
  }

  public function testServiceErrorDoesNotFailTheTask() {
    $env = $this->configureReviewHelper();
    $revision = $this->newDraftRevision($this->generateNewTestUser());

    $caught = null;
    PhabricatorWorker::setRunAllTasksInProcess(true);
    try {
      ReviewHelperAutoRequestWorker::queueRequest($revision);
    } catch (Exception $ex) {
      $caught = $ex;
    } finally {
      PhabricatorWorker::setRunAllTasksInProcess(false);
    }

    $this->assertEqual(
      null,
      $caught,
      pht(
        'An unreachable Review Helper should be logged rather than fail '.
        'the task.'));
  }

}
