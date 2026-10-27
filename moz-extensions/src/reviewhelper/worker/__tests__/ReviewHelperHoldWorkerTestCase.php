<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

final class ReviewHelperHoldWorkerTestCase
  extends ReviewHelperDraftHoldFixtureTestCase {

  public function testTimeoutPublishesRevisionWaitingForReview() {
    $env = $this->configureHold(
      array('reviewhelper.draft-hold.timeout' => 0));
    $revision = $this->holdRevision(
      $this->newDraftRevision($this->generateNewTestUser()));

    $this->runSweep();
    $revision = $this->reloadRevision($revision);

    $this->assertPublished(
      $revision,
      pht(
        'A revision Review Helper never reviewed should publish on '.
        'timeout.'));
    $this->assertEqual(
      ReviewHelperReleaseHoldTransaction::REASON_TIMEOUT,
      $this->loadReleaseReason($revision),
      pht('The hold should record that it was released by the timeout.'));
    $this->assertTrue(
      in_array(
        ReviewHelperReleaseHoldTransaction::TRANSACTIONTYPE,
        $this->loadTransactionTypes($revision),
        true),
      pht('The timeline should record why the revision was published.'));
  }

  public function testSweepLeavesRevisionThatIsNotOverdue() {
    $env = $this->configureHold();
    $revision = $this->holdRevision(
      $this->newDraftRevision($this->generateNewTestUser()));

    $this->runSweep();

    $this->assertHeldInDraft(
      $this->reloadRevision($revision),
      pht(
        'The sweep should not publish a revision held for less than the '.
        'timeout.'));
  }

  public function testEnsureTriggerCreatesOneTrigger() {
    ReviewHelperHoldSweepWorker::ensureTrigger();
    ReviewHelperHoldSweepWorker::ensureTrigger();

    $triggers = id(new PhabricatorWorkerTriggerQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs(array(ReviewHelperHoldSweepWorker::TRIGGER_PHID))
      ->execute();

    $this->assertEqual(
      1,
      count($triggers),
      pht('Repeated calls to `ensureTrigger` should leave a single trigger.'));
  }

  public function testTimeoutPublishesEvenWhenTheHoldIsDisabled() {
    $env = $this->configureHold(
      array('reviewhelper.draft-hold.timeout' => 0));
    $revision = $this->holdRevision(
      $this->newDraftRevision($this->generateNewTestUser()));

    $env->overrideEnvConfig('reviewhelper.draft-hold.enabled', false);
    $this->runSweep();

    $this->assertPublished(
      $this->reloadRevision($revision),
      pht(
        'Turning the hold off should never strand a revision that was '.
        'already held.'));
  }

  public function testTimeoutLeavesRevisionWithFindingsForTheAuthor() {
    $env = $this->configureHold(
      array('reviewhelper.draft-hold.timeout' => 0));
    $revision = $this->holdRevision(
      $this->newDraftRevision($this->generateNewTestUser()));
    ReviewHelperDraftHold::recordFindings($revision);
    $revision->save();

    $this->runSweep();

    $this->assertHeldInDraft(
      $this->reloadRevision($revision),
      pht(
        'Once Review Helper has posted findings, the timeout should leave the '.
        'revision for the author to publish.'));
  }

  public function testTimeoutIgnoresRevisionPublishedByTheAuthor() {
    $env = $this->configureHold(
      array('reviewhelper.draft-hold.timeout' => 0));
    $author = $this->generateNewTestUser();
    $revision = $this->holdRevision($this->newDraftRevision($author));

    $this->applyRevisionTransactions(
      $author,
      $revision,
      array(
        id(new DifferentialTransaction())
          ->setTransactionType(
            DifferentialRevisionRequestReviewTransaction::TRANSACTIONTYPE)
          ->setNewValue(true),
      ));
    $this->runSweep();

    $this->assertFalse(
      in_array(
        ReviewHelperReleaseHoldTransaction::TRANSACTIONTYPE,
        $this->loadTransactionTypes($this->reloadRevision($revision)),
        true),
      pht(
        'The timeout should not add a story to a revision the author already '.
        'published.'));
  }

  public function testFailedRequestPublishesRevision() {
    $env = $this->configureHold();
    $revision = $this->holdRevision(
      $this->newDraftRevision($this->generateNewTestUser()));

    id(new ReviewHelperHoldRequestWorker(
      array('revisionPHID' => $revision->getPHID())))
      ->executeTask();
    $revision = $this->reloadRevision($revision);

    $this->assertPublished(
      $revision,
      pht(
        'A revision should publish right away when Review Helper cannot be '.
        'reached, rather than waiting for the timeout.'));
    $this->assertEqual(
      ReviewHelperReleaseHoldTransaction::REASON_SERVICE_ERROR,
      $this->loadReleaseReason($revision),
      pht('The hold should record that Review Helper failed.'));
  }

  private function runSweep(): void {
    id(new ReviewHelperHoldSweepWorker(array()))->executeTask();
  }

}
