<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

final class ReviewHelperDraftHoldTestCase extends PhabricatorTestCase {

  const REPOSITORY_PHID = 'PHID-REPO-testrepo';

  public function testNewPublicDraftInAllowedRepositoryIsHeld() {
    $env = $this->configure();

    $this->assertTrue(
      ReviewHelperDraftHold::shouldHoldRevision($this->newDraft()),
      pht('A new public draft in an allow-listed repository should be held.'));
  }

  public function testNothingIsHeldWhileDisabled() {
    $env = $this->configure(array('reviewhelper.draft-hold.enabled' => false));

    $this->assertFalse(
      ReviewHelperDraftHold::shouldHoldRevision($this->newDraft()),
      pht(
        'Turning `reviewhelper.draft-hold.enabled` off should hold '.
        'nothing.'));
  }

  public function testNothingIsHeldWithAnEmptyRepositoryList() {
    $env = $this->configure(
      array('reviewhelper.draft-hold.repository-phids' => array()));

    $this->assertFalse(
      ReviewHelperDraftHold::shouldHoldRevision($this->newDraft()),
      pht(
        'An empty repository list should hold nothing, so enabling the hold '.
        'never affects a repository nobody asked about.'));
  }

  public function testRepositoryNotInTheListIsNotHeld() {
    $env = $this->configure(
      array(
        'reviewhelper.draft-hold.repository-phids' => array(
          'PHID-REPO-someotherrepo',
        ),
      ));

    $this->assertFalse(
      ReviewHelperDraftHold::shouldHoldRevision($this->newDraft()),
      pht('A repository absent from the list should not be held.'));
  }

  public function testNothingIsHeldWithoutTheBotUsername() {
    $env = $this->configure(array('reviewhelper.bot-username' => ''));

    $this->assertFalse(
      ReviewHelperDraftHold::shouldHoldRevision($this->newDraft()),
      pht(
        'Without `reviewhelper.bot-username`, Phabricator cannot tell when '.
        'Review Helper is done, so nothing should be held.'));
  }

  public function testNothingIsHeldWithoutTheServiceURL() {
    $env = $this->configure(array('reviewhelper.url' => ''));

    $this->assertFalse(
      ReviewHelperDraftHold::shouldHoldRevision($this->newDraft()),
      pht('Without `reviewhelper.url`, nothing should be held.'));
  }

  public function testPrivateRevisionIsNotHeld() {
    $env = $this->configure();
    $revision = $this->newDraft()
      ->setViewPolicy('PHID-PROJ-securebugs');

    $this->assertFalse(
      ReviewHelperDraftHold::shouldHoldRevision($revision),
      pht('A private revision should be published without a hold.'));
  }

  public function testRevisionHeldAsDraftByTheAuthorIsNotHeld() {
    $env = $this->configure();
    $revision = $this->newDraft()
      ->setHoldAsDraft(true);

    $this->assertFalse(
      ReviewHelperDraftHold::shouldHoldRevision($revision),
      pht(
        'A revision the author asked to hold as a draft should stay under the '.
        'author\'s control.'));
  }

  public function testPublishedRevisionIsNotHeld() {
    $env = $this->configure();
    $revision = $this->newDraft()
      ->setModernRevisionStatus(DifferentialRevisionStatus::NEEDS_REVIEW)
      ->setShouldBroadcast(true);

    $this->assertFalse(
      ReviewHelperDraftHold::shouldHoldRevision($revision),
      pht(
        'A revision that is already published should never return to '.
        'draft.'));
  }

  public function testHoldMovesThroughItsStatuses() {
    $revision = $this->newDraft();

    $this->assertFalse(
      ReviewHelperDraftHold::isHeld($revision),
      pht('A revision should not be held before `startHold`.'));

    ReviewHelperDraftHold::startHold($revision);
    $this->assertTrue(
      ReviewHelperDraftHold::isWaitingForReview($revision),
      pht('A new hold should be waiting for Review Helper.'));
    $this->assertTrue(
      ReviewHelperDraftHold::getHeldAt($revision) !== null,
      pht('A new hold should record when it started.'));

    ReviewHelperDraftHold::recordFindings($revision);
    $this->assertTrue(
      ReviewHelperDraftHold::hasFindings($revision),
      pht('After `recordFindings`, the hold should be waiting on the author.'));

    ReviewHelperDraftHold::releaseHold($revision, 'timeout');
    $this->assertFalse(
      ReviewHelperDraftHold::isHeld($revision),
      pht('A released hold should no longer hold the revision.'));

    $hold = $revision->getProperty(ReviewHelperDraftHold::PROPERTY_HOLD);
    $this->assertEqual(
      'timeout',
      idx($hold, 'reason'),
      pht('A released hold should record why it was released.'));
  }

  public function testPublishingTheRevisionEndsTheHold() {
    $revision = $this->newDraft();
    ReviewHelperDraftHold::startHold($revision);

    $revision
      ->setModernRevisionStatus(DifferentialRevisionStatus::NEEDS_REVIEW)
      ->setShouldBroadcast(true);

    $this->assertFalse(
      ReviewHelperDraftHold::isHeld($revision),
      pht(
        'A revision published by any route, like "Request Review", should no '.
        'longer be held.'));
  }

  private function newDraft(): DifferentialRevision {
    return id(new DifferentialRevision())
      ->setViewPolicy(PhabricatorPolicies::POLICY_USER)
      ->setRepositoryPHID(self::REPOSITORY_PHID)
      ->setModernRevisionStatus(DifferentialRevisionStatus::DRAFT)
      ->setShouldBroadcast(false);
  }

  /**
   * Configures a working hold for `REPOSITORY_PHID`, with `$overrides` applied
   * on top. Callers must hold the returned scope until the test returns.
   */
  private function configure(array $overrides = array()): PhabricatorScopedEnv {
    $config = $overrides + array(
      'reviewhelper.draft-hold.enabled' => true,
      'reviewhelper.draft-hold.repository-phids' => array(
        self::REPOSITORY_PHID,
      ),
      'reviewhelper.url' => 'https://reviewhelper.example.com',
      'reviewhelper.bot-username' => 'reviewhelper-bot',
      'reviewhelper.repository-phids' => array(),
      'reviewhelper.allow-private-revisions' => false,
    );

    $env = PhabricatorEnv::beginScopedEnv();
    foreach ($config as $key => $value) {
      $env->overrideEnvConfig($key, $value);
    }

    return $env;
  }

}
