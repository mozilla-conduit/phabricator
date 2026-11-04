<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

final class DifferentialSubmitDraftTransactionTestCase
  extends ReviewHelperDraftHoldFixtureTestCase {

  public function testEligibleRevisionIsHeldInDraft() {
    $env = $this->configureHold();
    $author = $this->generateNewTestUser();
    $revision = $this->newDraftRevision($author);

    $this->submit($author, $revision);
    $revision = $this->reloadRevision($revision);

    $this->assertHeldInDraft(
      $revision,
      pht('An eligible revision should stay in draft for AI review.'));
    $this->assertTrue(
      ReviewHelperDraftHold::isWaitingForReview($revision),
      pht('A newly held revision should be waiting for Review Helper.'));
    $this->assertEqual(
      array('ReviewHelperHoldRequestWorker'),
      $this->loadQueuedTaskClasses($revision),
      pht('Holding a revision should request the review.'));

    $trigger = id(new PhabricatorWorkerTriggerQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs(array(ReviewHelperHoldSweepWorker::TRIGGER_PHID))
      ->executeOne();
    $this->assertTrue(
      $trigger !== null,
      pht(
        'Holding a revision should make sure the timeout sweep is '.
        'scheduled.'));
  }

  public function testIneligibleRevisionIsPublished() {
    $env = $this->configureHold(
      array('reviewhelper.draft-hold.repository-phids' => array()));
    $author = $this->generateNewTestUser();
    $revision = $this->newDraftRevision($author);

    $this->submit($author, $revision);
    $revision = $this->reloadRevision($revision);

    $this->assertPublished(
      $revision,
      pht('A revision outside the pilot should publish right away.'));
    $this->assertEqual(
      array(),
      $this->loadQueuedTaskClasses($revision),
      pht('A revision that is not held should not queue an AI review.'));
  }

  public function testPrivateRevisionIsPublished() {
    $env = $this->configureHold();
    $author = $this->generateNewTestUser();
    $revision = $this->newDraftRevision($author);

    // Like phab-bot, set the view policy in the same edit as the submission.
    $this->submit(
      $author,
      $revision,
      array(
        array(
          'type' => 'view',
          'value' => PhabricatorPolicies::POLICY_NOONE,
        ),
      ));

    $this->assertPublished(
      $this->reloadRevision($revision),
      pht(
        'A revision made private in the same edit should be published, not '.
        'held for an AI review it can\'t get.'));
  }

  public function testPublishedRevisionIsUnchanged() {
    $env = $this->configureHold();
    $author = $this->generateNewTestUser();
    $revision = $this->newDraftRevision($author);
    $this->applyRevisionTransactions(
      $author,
      $revision,
      array(
        id(new DifferentialTransaction())
          ->setTransactionType(
            DifferentialRevisionRequestReviewTransaction::TRANSACTIONTYPE)
          ->setNewValue(true),
      ));

    $this->submit($author, $revision);
    $revision = $this->reloadRevision($revision);

    $this->assertFalse(
      in_array(
        DifferentialSubmitDraftTransaction::TRANSACTIONTYPE,
        $this->loadTransactionTypes($revision),
        true),
      pht(
        'Submitting a revision the author already published should have no '.
        'effect.'));
    $this->assertFalse(
      ReviewHelperDraftHold::isHeld($revision),
      pht('A published revision should never return to draft.'));
  }

  public function testRevisionHeldAsDraftByTheAuthorIsUnchanged() {
    $env = $this->configureHold();
    $author = $this->generateNewTestUser();
    $revision = $this->newDraftRevision($author);
    $revision->setHoldAsDraft(true)->save();

    $this->submit($author, $revision);

    $this->assertFalse(
      in_array(
        DifferentialSubmitDraftTransaction::TRANSACTIONTYPE,
        $this->loadTransactionTypes($this->reloadRevision($revision)),
        true),
      pht(
        'A revision the author is holding as a draft should be left for the '.
        'author to publish.'));
  }

  public function testOtherUsersCanNotSubmit() {
    $env = $this->configureHold();
    $revision = $this->newDraftRevision($this->generateNewTestUser());

    $caught = null;
    try {
      $this->submit($this->generateNewTestUser(), $revision);
    } catch (Exception $ex) {
      $caught = $ex;
    }

    $this->assertTrue(
      $caught !== null,
      pht(
        'Only the author or phab-bot should be able to submit a revision, '.
        'like "Request Review".'));
  }

  /**
   * Submits the revision through `differential.revision.edit`, as phab-bot
   * does, after applying any `$transactions` in the same edit.
   */
  private function submit(
    PhabricatorUser $actor,
    DifferentialRevision $revision,
    array $transactions = array()): void {

    $transactions[] = array(
      'type' => DifferentialSubmitDraftTransaction::ACTIONKEY,
      'value' => true,
    );

    id(new ConduitCall(
      'differential.revision.edit',
      array(
        'objectIdentifier' => $revision->getPHID(),
        'transactions' => $transactions,
      )))
      ->setUser($actor)
      ->execute();
  }

  private function loadQueuedTaskClasses(
    DifferentialRevision $revision): array {

    $tasks = id(new PhabricatorWorkerActiveTask())->loadAllWhere(
      'objectPHID = %s AND taskClass LIKE %>',
      $revision->getPHID(),
      'ReviewHelper');

    $classes = mpull($tasks, 'getTaskClass');
    sort($classes);

    return array_values($classes);
  }

}
