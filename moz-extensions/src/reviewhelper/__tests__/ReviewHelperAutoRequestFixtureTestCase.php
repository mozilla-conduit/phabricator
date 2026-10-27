<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * Builds real revisions in storage for testing automatic AI review requests.
 */
abstract class ReviewHelperAutoRequestFixtureTestCase
  extends PhabricatorTestCase {

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
   * The repository revisions are created in unless a test passes another.
   */
  protected function getRepository(): PhabricatorRepository {
    if (!$this->repository) {
      $this->repository = $this->newRepository();
    }

    return $this->repository;
  }

  protected function newRepository(): PhabricatorRepository {
    $seed = $this->getNextObjectSeed();

    return PhabricatorRepository::initializeNewRepository(
      PhabricatorUser::getOmnipotentUser())
      ->setName(pht('Test Repository %s', $seed))
      ->setVersionControlSystem(
        PhabricatorRepositoryType::REPOSITORY_TYPE_GIT)
      ->save();
  }

  /**
   * Configures Review Helper so every repository is eligible. Callers must
   * hold the returned scope until the test returns.
   */
  protected function configureReviewHelper(): PhabricatorScopedEnv {
    $config = array(
      // Nothing listens here, so any request to Review Helper fails.
      'reviewhelper.url' => 'https://reviewhelper.invalid',
      'reviewhelper.timeout' => 5,
      'reviewhelper.repository-phids' => array(),
      'reviewhelper.allow-private-revisions' => false,
    );

    $env = PhabricatorEnv::beginScopedEnv();
    foreach ($config as $key => $value) {
      $env->overrideEnvConfig($key, $value);
    }

    return $env;
  }

  /**
   * Creates a draft revision as `moz-phab submit` would, before phab-bot has
   * seen it.
   */
  protected function newDraftRevision(
    PhabricatorUser $author,
    ?PhabricatorRepository $repository = null): DifferentialRevision {

    if (!$repository) {
      $repository = $this->getRepository();
    }

    $revision = DifferentialRevision::initializeNewRevision($author);

    $this->applyRevisionTransactions(
      $author,
      $revision,
      array(
        id(new DifferentialTransaction())
          ->setTransactionType(
            DifferentialRevisionTitleTransaction::TRANSACTIONTYPE)
          ->setNewValue(pht('Bug 1 - Test revision')),
        $this->newUpdateTransaction($author, $repository),
      ));

    return $this->reloadRevision($revision);
  }

  protected function newUpdateTransaction(
    PhabricatorUser $author,
    PhabricatorRepository $repository): DifferentialTransaction {

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
      ->setRepositoryPHID($repository->getPHID())
      ->setLintStatus(DifferentialLintStatus::LINT_AUTO_SKIP)
      ->setUnitStatus(DifferentialUnitStatus::UNIT_AUTO_SKIP)
      ->attachRevision(null)
      ->save();

    return id(new DifferentialTransaction())
      ->setTransactionType(
        DifferentialRevisionUpdateTransaction::TRANSACTIONTYPE)
      ->setNewValue($diff->getPHID());
  }

  /**
   * Makes a revision visible only to its author by setting its view policy
   * directly, as phab-bot's custom policy does for a security bug.
   */
  protected function makePrivate(
    DifferentialRevision $revision): DifferentialRevision {

    $revision
      ->setViewPolicy($revision->getAuthorPHID())
      ->save();

    return $this->reloadRevision($revision);
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
      ->needDiffIDs(true)
      ->needReviewers(true)
      ->needActiveDiffs(true)
      ->executeOne();
  }

  /**
   * Classes of the Review Helper tasks queued for a revision.
   */
  protected function loadQueuedTaskClasses(
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
