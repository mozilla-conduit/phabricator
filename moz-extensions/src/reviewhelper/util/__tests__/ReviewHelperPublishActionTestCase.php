<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

final class ReviewHelperPublishActionTestCase
  extends ReviewHelperDraftHoldFixtureTestCase {

  public function testAuthorIsOfferedToPublishWithoutAIReview() {
    $env = $this->configureHold();
    $author = $this->generateNewTestUser();
    $revision = $this->holdRevision($this->newDraftRevision($author));

    $field = $this->newRequestReviewField($revision, $author);

    $this->assertEqual(
      pht('Publish Without AI Review'),
      $field->getCommentActionLabel(),
      pht('The author should be told that publishing skips the AI review.'));
    $this->assertEqual(
      pht('Publish Without AI Review'),
      $field->getActionSubmitButtonText(),
      pht('The submit button should match the action label.'));
    $this->assertTrue(
      strpos($field->getActionDescription(), 'has not finished') !== false,
      pht('The description should say Review Helper is still reviewing.'));
  }

  public function testSecureRevisionMembersAreOfferedToSkipAIReview() {
    $env = $this->configureHold();
    $revision = $this->holdRevision(
      $this->newDraftRevision($this->generateNewTestUser()));

    $field = $this->newRequestReviewField(
      $revision,
      $this->newSecureRevisionMember());

    $this->assertEqual(
      pht('Begin Review Without AI Review'),
      $field->getCommentActionLabel(),
      pht(
        'Users who may publish other users\' drafts should be told that '.
        'beginning review skips the AI review.'));
  }

  public function testFindingsChangeTheDescription() {
    $env = $this->configureHold();
    $author = $this->generateNewTestUser();
    $revision = $this->holdRevision($this->newDraftRevision($author));
    ReviewHelperDraftHold::recordFindings($revision);
    $revision->save();

    $field = $this->newRequestReviewField(
      $this->reloadRevision($revision),
      $author);

    $this->assertTrue(
      strpos($field->getActionDescription(), 'not been addressed') !== false,
      pht('The description should say the findings have not been addressed.'));
  }

  public function testDraftThatIsNotHeldKeepsTheUsualWording() {
    $env = $this->configureHold();
    $author = $this->generateNewTestUser();
    $revision = $this->newDraftRevision($author);

    $field = $this->newRequestReviewField($revision, $author);

    $this->assertEqual(
      pht('Request Review'),
      $field->getCommentActionLabel(),
      pht(
        'A draft that is not held for AI review should keep the usual '.
        '"Request Review" label.'));
    $this->assertEqual(
      pht('Publish Revision'),
      $field->getActionSubmitButtonText(),
      pht('A draft that is not held should keep the usual button text.'));
  }

  public function testPublishingBeforeTheReviewIsRecorded() {
    $env = $this->configureHold();
    $author = $this->generateNewTestUser();
    $revision = $this->holdRevision($this->newDraftRevision($author));

    $this->requestReview($author, $revision);
    $revision = $this->reloadRevision($revision);

    $this->assertPublished(
      $revision,
      pht('Requesting review should publish a held revision right away.'));
    $this->assertEqual(
      ReviewHelperReleaseHoldTransaction::REASON_SKIPPED_REVIEW,
      $this->loadReleaseReason($revision),
      pht('The hold should record that it was skipped before the review.'));
    $this->assertEqual(
      ReviewHelperReleaseHoldTransaction::REASON_SKIPPED_REVIEW,
      $this->loadRequestReviewReason($revision),
      pht(
        'The "Request Review" story should record that it skipped the '.
        'review, so the timeline and the ETL can see it.'));
  }

  public function testPublishingWithUnaddressedFindingsIsRecorded() {
    $env = $this->configureHold();
    $author = $this->generateNewTestUser();
    $revision = $this->holdRevision($this->newDraftRevision($author));
    ReviewHelperDraftHold::recordFindings($revision);
    $revision->save();

    $this->requestReview($author, $this->reloadRevision($revision));

    $this->assertEqual(
      ReviewHelperReleaseHoldTransaction::REASON_SKIPPED_FINDINGS,
      $this->loadReleaseReason($this->reloadRevision($revision)),
      pht(
        'The hold should record that the revision was published without a '.
        'new diff after the findings.'));
  }

  public function testOtherUserPublishingBeforeTheReviewIsRecorded() {
    $env = $this->configureHold();
    $revision = $this->holdRevision(
      $this->newDraftRevision($this->generateNewTestUser()));

    $this->requestReview($this->newSecureRevisionMember(), $revision);

    $this->assertEqual(
      ReviewHelperReleaseHoldTransaction::REASON_SKIPPED_REVIEW,
      $this->loadReleaseReason($this->reloadRevision($revision)),
      pht(
        'A sheriff beginning review of a held revision should be recorded '.
        'as skipping the review too.'));
  }

  public function testPublishingADraftThatIsNotHeldRecordsNothing() {
    $env = $this->configureHold();
    $author = $this->generateNewTestUser();
    $revision = $this->newDraftRevision($author);

    $this->requestReview($author, $revision);

    $this->assertEqual(
      null,
      $this->loadRequestReviewReason($this->reloadRevision($revision)),
      pht(
        'Publishing a draft that was never held should not mention the AI '.
        'review.'));
  }

  private function requestReview(
    PhabricatorUser $actor,
    DifferentialRevision $revision): void {

    $this->applyRevisionTransactions(
      $actor,
      $revision,
      array(
        id(new DifferentialTransaction())
          ->setTransactionType(
            DifferentialRevisionRequestReviewTransaction::TRANSACTIONTYPE)
          ->setNewValue(true),
      ));
  }

  private function loadRequestReviewReason(
    DifferentialRevision $revision): ?string {

    $xaction = id(new DifferentialTransactionQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withObjectPHIDs(array($revision->getPHID()))
      ->withTransactionTypes(
        array(DifferentialRevisionRequestReviewTransaction::TRANSACTIONTYPE))
      ->executeOne();

    return $xaction->getMetadataValue(
      ReviewHelperDraftHold::METADATA_PUBLISH_REASON);
  }

  private function newRequestReviewField(
    DifferentialRevision $revision,
    PhabricatorUser $viewer): PhabricatorApplyEditField {

    $actions = DifferentialRevisionActionTransaction::loadAllActions();
    $action = $actions[DifferentialRevisionRequestReviewTransaction::ACTIONKEY];

    return $action->newEditField($revision, $viewer);
  }

}
