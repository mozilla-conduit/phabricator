<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

final class ReviewHelperRequestReviewHeraldActionTestCase
  extends ReviewHelperAutoRequestFixtureTestCase {

  public function testDraftRevisionIsNotRequested() {
    $env = $this->configureReviewHelper();
    $this->newRequestReviewRule(array($this->getRepository()));

    $revision = $this->newDraftRevision($this->generateNewTestUser());

    $this->assertEqual(
      array(),
      $this->loadQueuedTaskClasses($revision),
      pht('A draft revision should not be sent to Review Helper.'));
  }

  public function testPublishingRequestsOneReview() {
    $env = $this->configureReviewHelper();
    $this->newRequestReviewRule(array($this->getRepository()));
    $author = $this->generateNewTestUser();
    $revision = $this->newDraftRevision($author);

    $this->publish($author, $revision);

    $this->assertEqual(
      array('ReviewHelperAutoRequestWorker'),
      $this->loadQueuedTaskClasses($revision),
      pht('Publishing a revision should queue an AI review request.'));

    $this->applyRevisionTransactions(
      $author,
      $this->reloadRevision($revision),
      array(
        id(new DifferentialTransaction())
          ->setTransactionType(
            DifferentialRevisionTitleTransaction::TRANSACTIONTYPE)
          ->setNewValue(pht('Bug 1 - Retitled revision')),
      ));

    $this->assertEqual(
      array('ReviewHelperAutoRequestWorker'),
      $this->loadQueuedTaskClasses($revision),
      pht(
        'Later edits should not queue another request from a rule that '.
        'applies only the first time.'));
  }

  public function testRevisionInOtherRepositoryIsNotRequested() {
    $env = $this->configureReviewHelper();
    $this->newRequestReviewRule(array($this->newRepository()));
    $author = $this->generateNewTestUser();
    $revision = $this->newDraftRevision($author);

    $this->publish($author, $revision);

    $this->assertEqual(
      array(),
      $this->loadQueuedTaskClasses($revision),
      pht(
        'A revision in a repository the rule does not select should not be '.
        'sent to Review Helper.'));
  }

  public function testPrivateRevisionIsNotRequested() {
    $env = $this->configureReviewHelper();
    $this->newRequestReviewRule(array($this->getRepository()));
    $author = $this->generateNewTestUser();
    $revision = $this->makePrivate($this->newDraftRevision($author));

    $this->publish($author, $revision);

    $this->assertEqual(
      array(),
      $this->loadQueuedTaskClasses($revision),
      pht(
        'A private revision should never be sent to Review Helper, even '.
        'when a rule matches it.'));
  }

  public function testRevisionHeldForAIReviewIsNotRequestedAgain() {
    $env = $this->configureReviewHelper();
    $this->newRequestReviewRule(array($this->getRepository()));
    $author = $this->generateNewTestUser();
    $revision = $this->newDraftRevision($author);
    ReviewHelperDraftHold::startHold($revision);
    $revision->save();

    $this->publish($author, $this->reloadRevision($revision));

    $this->assertEqual(
      array(),
      $this->loadQueuedTaskClasses($revision),
      pht(
        'Publishing a revision held for AI review should not request a '.
        'second review, since the hold already requested one.'));
  }

  public function testPersonalRulesCannotUseTheAction() {
    $action = new ReviewHelperRequestReviewHeraldAction();

    $this->assertFalse(
      $action->supportsRuleType(HeraldRuleTypeConfig::RULE_TYPE_PERSONAL),
      pht(
        'Personal rules should not be able to send other people\'s '.
        'revisions to Review Helper.'));
    $this->assertTrue(
      $action->supportsRuleType(HeraldRuleTypeConfig::RULE_TYPE_GLOBAL),
      pht('Global rules should be able to request AI reviews.'));
  }

  /**
   * Creates a global rule that requests an AI review, only the first time, for
   * revisions in any of `$repositories`.
   */
  private function newRequestReviewRule(array $repositories): HeraldRule {
    $rule = id(new HeraldRule())
      ->setName(pht('Request AI review'))
      ->setAuthorPHID($this->generateNewTestUser()->getPHID())
      ->setContentType(
        id(new HeraldDifferentialRevisionAdapter())->getAdapterContentType())
      ->setMustMatchAll(1)
      ->setRepetitionPolicyStringConstant(HeraldRule::REPEAT_FIRST)
      ->setRuleType(HeraldRuleTypeConfig::RULE_TYPE_GLOBAL)
      ->save();

    $rule->saveConditions(
      array(
        id(new HeraldCondition())
          ->setFieldName(DifferentialRevisionRepositoryHeraldField::FIELDCONST)
          ->setFieldCondition(HeraldAdapter::CONDITION_IS_ANY)
          ->setValue(mpull($repositories, 'getPHID')),
      ));

    $rule->saveActions(
      array(
        id(new HeraldActionRecord())
          ->setAction(ReviewHelperRequestReviewHeraldAction::ACTIONCONST)
          ->setTarget(null),
      ));

    return $rule;
  }

  /**
   * Publishes a draft revision with "Request Review", as phab-bot does today.
   */
  private function publish(
    PhabricatorUser $author,
    DifferentialRevision $revision): void {

    $this->applyRevisionTransactions(
      $author,
      $revision,
      array(
        id(new DifferentialTransaction())
          ->setTransactionType(
            DifferentialRevisionRequestReviewTransaction::TRANSACTIONTYPE)
          ->setNewValue(true),
      ));
  }

}
