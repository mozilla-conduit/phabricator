<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * Builds real revisions in storage for testing the AI review draft hold.
 */
abstract class ReviewHelperDraftHoldFixtureTestCase
  extends PhabricatorTestCase {

  const BOT_USERNAME = 'reviewhelperbot';

  private $repository;

  protected function getPhabricatorTestCaseConfiguration() {
    return array(
      self::PHABRICATOR_TESTCONFIG_BUILD_STORAGE_FIXTURES => true,
    );
  }

  protected function willRunOneTest($test_method_name) {
    parent::willRunOneTest($test_method_name);
    $this->repository = null;
  }

  /**
   * The repository every revision in a test is created in.
   */
  protected function getRepository(): PhabricatorRepository {
    if (!$this->repository) {
      $seed = $this->getNextObjectSeed();
      $this->repository = PhabricatorRepository::initializeNewRepository(
        PhabricatorUser::getOmnipotentUser())
        ->setName(pht('Test Repository %s', $seed))
        ->setVersionControlSystem(
          PhabricatorRepositoryType::REPOSITORY_TYPE_GIT)
        ->save();
    }

    return $this->repository;
  }

  /**
   * Configures a working hold for `getRepository`, with `$overrides` applied
   * on top. Callers must hold the returned scope until the test returns.
   */
  protected function configureHold(
    array $overrides = array()): PhabricatorScopedEnv {

    $config = $overrides + array(
      'reviewhelper.draft-hold.enabled' => true,
      'reviewhelper.draft-hold.repository-phids' => array(
        $this->getRepository()->getPHID(),
      ),
      'reviewhelper.draft-hold.timeout' => 1200,
      // Nothing listens here, so any request to Review Helper fails.
      'reviewhelper.url' => 'https://reviewhelper.invalid',
      'reviewhelper.timeout' => 5,
      'reviewhelper.bot-username' => self::BOT_USERNAME,
      'reviewhelper.repository-phids' => array(),
      'reviewhelper.allow-private-revisions' => false,
    );

    $env = PhabricatorEnv::beginScopedEnv();
    foreach ($config as $key => $value) {
      $env->overrideEnvConfig($key, $value);
    }

    return $env;
  }

  protected function newBotUser(): PhabricatorUser {
    $user = id(new PhabricatorUser())
      ->setRealName(pht('Review Helper'))
      ->setUserName(self::BOT_USERNAME)
      ->setIsApproved(1);

    $email = id(new PhabricatorUserEmail())
      ->setAddress('reviewhelperbot@example.com')
      ->setIsVerified(1);

    id(new PhabricatorUserEditor())
      ->setActor($user)
      ->createNewUser($user, $email);

    return $user;
  }

  /**
   * Creates a draft revision as `moz-phab submit` would, before phab-bot has
   * seen it.
   */
  protected function newDraftRevision(
    PhabricatorUser $author): DifferentialRevision {

    $revision = DifferentialRevision::initializeNewRevision($author);

    $this->applyRevisionTransactions(
      $author,
      $revision,
      array(
        id(new DifferentialTransaction())
          ->setTransactionType(
            DifferentialRevisionTitleTransaction::TRANSACTIONTYPE)
          ->setNewValue(pht('Bug 1 - Test revision')),
        $this->newUpdateTransaction($author),
      ));

    return $this->reloadRevision($revision);
  }

  protected function newUpdateTransaction(
    PhabricatorUser $author): DifferentialTransaction {

    $raw_diff = <<<EODIFF
diff --git a/fruit b/fruit
new file mode 100644
index 0000000..1c0f49d
--- /dev/null
+++ b/fruit
@@ -0,0 +1,2 @@
+apple
+banana
EODIFF;

    $changes = id(new ArcanistDiffParser())->parseDiff($raw_diff);

    $diff = DifferentialDiff::newFromRawChanges($author, $changes)
      ->setRepositoryPHID($this->getRepository()->getPHID())
      ->setLintStatus(DifferentialLintStatus::LINT_AUTO_SKIP)
      ->setUnitStatus(DifferentialUnitStatus::UNIT_AUTO_SKIP)
      ->attachRevision(null)
      ->save();

    return id(new DifferentialTransaction())
      ->setTransactionType(
        DifferentialRevisionUpdateTransaction::TRANSACTIONTYPE)
      ->setNewValue($diff->getPHID());
  }

  protected function applyRevisionTransactions(
    PhabricatorUser $actor,
    DifferentialRevision $revision,
    array $xactions): void {

    id(new DifferentialTransactionEditor())
      ->setActor($actor)
      ->setContentSource($this->newContentSource())
      ->setContinueOnNoEffect(true)
      ->setContinueOnMissingFields(true)
      ->applyTransactions($revision, $xactions);
  }

  protected function reloadRevision(
    DifferentialRevision $revision): DifferentialRevision {

    return id(new DifferentialRevisionQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs(array($revision->getPHID()))
      ->needReviewers(true)
      ->needActiveDiffs(true)
      ->executeOne();
  }

  /**
   * Holds a revision directly, skipping the `submit-draft` transaction.
   */
  protected function holdRevision(
    DifferentialRevision $revision): DifferentialRevision {

    ReviewHelperDraftHold::startHold($revision);
    $revision->save();

    return $this->reloadRevision($revision);
  }

  protected function loadTransactionTypes(
    DifferentialRevision $revision): array {

    $xactions = id(new DifferentialTransactionQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withObjectPHIDs(array($revision->getPHID()))
      ->execute();

    return array_values(mpull($xactions, 'getTransactionType'));
  }

  protected function loadReleaseReason(
    DifferentialRevision $revision): ?string {

    $hold = $revision->getProperty(ReviewHelperDraftHold::PROPERTY_HOLD);
    return idx($hold, 'reason');
  }

  protected function assertPublished(
    DifferentialRevision $revision,
    string $message): void {

    $this->assertTrue(
      $revision->isNeedsReview() && $revision->getShouldBroadcast(),
      $message);
  }

  protected function assertHeldInDraft(
    DifferentialRevision $revision,
    string $message): void {

    $this->assertTrue(
      $revision->isDraft() && !$revision->getShouldBroadcast(),
      $message);
  }

}
