<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

final class ReviewHelperDraftHoldEditTestCase
  extends ReviewHelperDraftHoldFixtureTestCase {

  public function testReviewWithoutFindingsPublishesRevision() {
    $env = $this->configureHold();
    $revision = $this->holdRevision(
      $this->newDraftRevision($this->generateNewTestUser()));

    $this->postReview($this->newBotUser(), $revision, 0);
    $revision = $this->reloadRevision($revision);

    $this->assertPublished(
      $revision,
      pht('A review with no findings should publish the revision.'));
    $this->assertEqual(
      ReviewHelperReleaseHoldTransaction::REASON_NO_FINDINGS,
      $this->loadReleaseReason($revision),
      pht('The hold should record that Review Helper found nothing.'));
  }

  public function testReviewWithFindingsWaitsOnTheAuthor() {
    $env = $this->configureHold();
    $revision = $this->holdRevision(
      $this->newDraftRevision($this->generateNewTestUser()));

    $this->postReview($this->newBotUser(), $revision, 2);
    $revision = $this->reloadRevision($revision);

    $this->assertHeldInDraft(
      $revision,
      pht('A review with findings should keep the revision in draft.'));
    $this->assertTrue(
      ReviewHelperDraftHold::hasFindings($revision),
      pht('After a review with findings, the hold should wait on the author.'));
    $this->assertTrue(
      in_array(
        ReviewHelperFindingsTransaction::TRANSACTIONTYPE,
        $this->loadTransactionTypes($revision),
        true),
      pht('The timeline should explain why the revision is still in draft.'));
  }

  public function testReviewWithFindingsEmailsOnlyTheAuthor() {
    $env = $this->configureHold();
    $author = $this->generateNewTestUser();
    $revision = $this->holdRevision($this->newDraftRevision($author));

    // Run the publish worker in process so the edit's mail is written.
    PhabricatorWorker::setRunAllTasksInProcess(true);
    try {
      $this->postReview($this->newBotUser(), $revision, 1);
    } finally {
      PhabricatorWorker::setRunAllTasksInProcess(false);
    }

    $mails = id(new PhabricatorMetaMTAMail())->loadAllWhere(
      'relatedPHID = %s',
      $revision->getPHID());
    $recipient_phids = array();
    foreach ($mails as $mail) {
      $recipient_phids[] = $mail->getToPHIDs();
      $recipient_phids[] = $mail->getCcPHIDs();
    }
    $recipient_phids = array_values(
      array_unique(array_mergev($recipient_phids)));

    $this->assertEqual(
      array($author->getPHID()),
      $recipient_phids,
      pht(
        'Review Helper\'s findings on a held revision should be emailed to '.
        'the author, and nobody else.'));
  }

  public function testCommentFromAnotherUserLeavesTheHold() {
    $env = $this->configureHold();
    $revision = $this->holdRevision(
      $this->newDraftRevision($this->generateNewTestUser()));

    $this->postReview($this->generateNewTestUser(), $revision, 0);

    $this->assertTrue(
      ReviewHelperDraftHold::isWaitingForReview(
        $this->reloadRevision($revision)),
      pht('Only a comment from the Review Helper bot should end the wait.'));
  }

  public function testAuthorUpdatePublishesRevisionWithFindings() {
    $env = $this->configureHold();
    $author = $this->generateNewTestUser();
    $revision = $this->holdRevision($this->newDraftRevision($author));
    $this->postReview($this->newBotUser(), $revision, 1);

    $this->applyRevisionTransactions(
      $author,
      $this->reloadRevision($revision),
      array($this->newUpdateTransaction($author)));
    $revision = $this->reloadRevision($revision);

    $this->assertPublished(
      $revision,
      pht(
        'Updating the diff after Review Helper\'s findings should publish the '.
        'revision.'));
    $this->assertEqual(
      ReviewHelperReleaseHoldTransaction::REASON_AUTHOR_UPDATED,
      $this->loadReleaseReason($revision),
      pht('The hold should record that the author updated the diff.'));
  }

  public function testAuthorUpdateWithRequestReviewPublishesOnce() {
    $env = $this->configureHold();
    $author = $this->generateNewTestUser();
    $revision = $this->holdRevision($this->newDraftRevision($author));
    $this->postReview($this->newBotUser(), $revision, 1);

    // `moz-phab` sends "Request Review" along with the new diff.
    $this->applyRevisionTransactions(
      $author,
      $this->reloadRevision($revision),
      array(
        $this->newUpdateTransaction($author),
        id(new DifferentialTransaction())
          ->setTransactionType(
            DifferentialRevisionRequestReviewTransaction::TRANSACTIONTYPE)
          ->setNewValue(true),
      ));
    $revision = $this->reloadRevision($revision);

    $this->assertPublished(
      $revision,
      pht('Requesting review should publish the revision.'));
    $this->assertEqual(
      ReviewHelperReleaseHoldTransaction::REASON_AUTHOR_UPDATED,
      $this->loadReleaseReason($revision),
      pht(
        'An update sent with "Request Review" after the findings should be '.
        'recorded as the author responding, not skipping them.'));
    $this->assertFalse(
      in_array(
        ReviewHelperReleaseHoldTransaction::TRANSACTIONTYPE,
        $this->loadTransactionTypes($revision),
        true),
      pht(
        'The timeline should not say the revision was published twice when '.
        'the author also requested review.'));
  }

  public function testAuthorUpdateBeforeTheReviewKeepsTheHold() {
    $env = $this->configureHold();
    $author = $this->generateNewTestUser();
    $revision = $this->holdRevision($this->newDraftRevision($author));

    $this->applyRevisionTransactions(
      $author,
      $revision,
      array($this->newUpdateTransaction($author)));

    $this->assertTrue(
      ReviewHelperDraftHold::isWaitingForReview(
        $this->reloadRevision($revision)),
      pht(
        'Updating the diff before Review Helper has posted should keep '.
        'waiting for the review.'));
  }

  /**
   * Posts a review the way Review Helper does: draft inline comments, then a
   * summary comment that publishes them.
   */
  private function postReview(
    PhabricatorUser $actor,
    DifferentialRevision $revision,
    int $finding_count): void {

    $diff = $revision->getActiveDiff();
    for ($line = 1; $line <= $finding_count; $line++) {
      id(new ConduitCall(
        'differential.createinline',
        array(
          'revisionID' => $revision->getID(),
          'diffID' => $diff->getID(),
          'filePath' => 'fruit',
          'isNewFile' => true,
          'lineNumber' => $line,
          'lineLength' => 0,
          'content' => pht('Finding %d.', $line),
        )))
        ->setUser($actor)
        ->execute();
    }

    id(new ConduitCall(
      'differential.createcomment',
      array(
        'revision_id' => $revision->getID(),
        'message' => pht('Review Helper summary.'),
        'attach_inlines' => true,
      )))
      ->setUser($actor)
      ->execute();
  }

}
