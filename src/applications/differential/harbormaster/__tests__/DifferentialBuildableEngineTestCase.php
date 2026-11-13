<?php

final class DifferentialBuildableEngineTestCase extends PhabricatorTestCase {

  protected function getPhabricatorTestCaseConfiguration() {
    return array(
      self::PHABRICATOR_TESTCONFIG_BUILD_STORAGE_FIXTURES => true,
    );
  }

  public function testAbortingSupersededDiffBuildsIsNotPublished() {
    $author = $this->generateNewTestUser();
    $plan = $this->newBuildPlan($author);

    $revision = $this->newRevision($author, 'apple');
    $old_diff = $revision->loadActiveDiff();
    $old_buildable = $this->newBuildable($author, $plan, $revision, $old_diff);

    $this->updateRevision($author, $revision, 'banana');
    $new_diff = $revision->loadActiveDiff();
    $new_buildable = $this->newBuildable($author, $plan, $revision, $new_diff);

    $this->abortBuildable($author, $old_buildable);

    $this->assertEqual(
      HarbormasterBuildableStatus::STATUS_FAILED,
      $this->reloadBuildable($old_buildable)->getBuildableStatus());
    $this->assertEqual(
      array(),
      $this->loadPublishedBuildableStatuses($revision));

    $this->abortBuildable($author, $new_buildable);

    $this->assertEqual(
      array(
        $new_buildable->getPHID() => HarbormasterBuildableStatus::STATUS_FAILED,
      ),
      $this->loadPublishedBuildableStatuses($revision));
  }

  public function testSupersededDiffBuildResultsAreNotPublished() {
    $author = $this->generateNewTestUser();
    $plan = $this->newBuildPlan($author);

    $revision = $this->newRevision($author, 'apple');
    $old_diff = $revision->loadActiveDiff();
    $old_buildable = $this->newBuildable($author, $plan, $revision, $old_diff);

    $this->updateRevision($author, $revision, 'banana');
    $new_diff = $revision->loadActiveDiff();
    $new_buildable = $this->newBuildable($author, $plan, $revision, $new_diff);

    $this->continueBuilds($old_buildable);

    $this->assertEqual(
      HarbormasterBuildableStatus::STATUS_PASSED,
      $this->reloadBuildable($old_buildable)->getBuildableStatus());
    $this->assertEqual(
      array(),
      $this->loadPublishedBuildableStatuses($revision));

    $this->continueBuilds($new_buildable);

    $this->assertEqual(
      array(
        $new_buildable->getPHID() => HarbormasterBuildableStatus::STATUS_PASSED,
      ),
      $this->loadPublishedBuildableStatuses($revision));
  }

  private function newBuildPlan(PhabricatorUser $author) {
    return HarbormasterBuildPlan::initializeNewBuildPlan($author)
      ->setName(pht('Test Plan'))
      ->save();
  }

  private function newRevision(PhabricatorUser $author, $content) {
    $diff = $this->newDiff($author, $content);

    $result = id(new ConduitCall(
      'differential.revision.edit',
      array(
        'transactions' => array(
          array(
            'type' => 'update',
            'value' => $diff->getPHID(),
          ),
          array(
            'type' => 'title',
            'value' => pht('Fruit'),
          ),
          array(
            'type' => 'testPlan',
            'value' => pht('Ate it.'),
          ),
        ),
      )))
      ->setUser($author)
      ->execute();

    return id(new DifferentialRevisionQuery())
      ->setViewer($author)
      ->withPHIDs(array($result['object']['phid']))
      ->executeOne();
  }

  private function updateRevision(
    PhabricatorUser $author,
    DifferentialRevision $revision,
    $content) {

    $diff = $this->newDiff($author, $content);

    id(new ConduitCall(
      'differential.revision.edit',
      array(
        'objectIdentifier' => $revision->getPHID(),
        'transactions' => array(
          array(
            'type' => 'update',
            'value' => $diff->getPHID(),
          ),
        ),
      )))
      ->setUser($author)
      ->execute();
  }

  private function newDiff(PhabricatorUser $author, $content) {
    $raw_diff = <<<EODIFF
diff --git a/fruit b/fruit
new file mode 100644
index 0000000..1c0f49d
--- /dev/null
+++ b/fruit
@@ -0,0 +1 @@
+{$content}
EODIFF;

    $parser = new ArcanistDiffParser();
    $changes = $parser->parseDiff($raw_diff);

    return DifferentialDiff::newFromRawChanges($author, $changes)
      ->setLintStatus(DifferentialLintStatus::LINT_AUTO_SKIP)
      ->setUnitStatus(DifferentialUnitStatus::UNIT_AUTO_SKIP)
      ->attachRevision(null)
      ->save();
  }

  private function newBuildable(
    PhabricatorUser $author,
    HarbormasterBuildPlan $plan,
    DifferentialRevision $revision,
    DifferentialDiff $diff) {

    $request = id(new HarbormasterBuildRequest())
      ->setBuildPlanPHID($plan->getPHID())
      ->setInitiatorPHID($author->getPHID());

    HarbormasterBuildable::applyBuildPlans(
      $diff->getPHID(),
      $revision->getPHID(),
      array($request));

    $buildable = id(new HarbormasterBuildableQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withBuildablePHIDs(array($diff->getPHID()))
      ->withManualBuildables(false)
      ->executeOne();

    $buildable->sendMessage(
      $author,
      HarbormasterMessageType::BUILDABLE_BUILD,
      false);

    return $buildable;
  }

  private function abortBuildable(
    PhabricatorUser $author,
    HarbormasterBuildable $buildable) {

    id(new ConduitCall(
      'harbormaster.sendmessage',
      array(
        'receiver' => $buildable->getPHID(),
        'type' => HarbormasterBuildMessageAbortTransaction::MESSAGETYPE,
      )))
      ->setUser($author)
      ->execute();

    $this->continueBuilds($buildable);
  }

  private function continueBuilds(HarbormasterBuildable $buildable) {
    $viewer = PhabricatorUser::getOmnipotentUser();

    $builds = id(new HarbormasterBuildQuery())
      ->setViewer($viewer)
      ->withBuildablePHIDs(array($buildable->getPHID()))
      ->execute();
    $this->assertEqual(1, count($builds));

    foreach ($builds as $build) {
      id(new HarbormasterBuildEngine())
        ->setViewer($viewer)
        ->setBuild($build)
        ->continueBuild();
    }
  }

  private function reloadBuildable(HarbormasterBuildable $buildable) {
    return id(new HarbormasterBuildableQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs(array($buildable->getPHID()))
      ->executeOne();
  }

  private function loadPublishedBuildableStatuses(
    DifferentialRevision $revision) {

    $xactions = id(new DifferentialTransactionQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withObjectPHIDs(array($revision->getPHID()))
      ->withTransactionTypes(
        array(
          DifferentialRevisionBuildableTransaction::TRANSACTIONTYPE,
        ))
      ->execute();

    $statuses = array();
    foreach ($xactions as $xaction) {
      $buildable_phid = $xaction->getMetadataValue(
        'harbormaster:buildablePHID');
      $statuses[$buildable_phid] = $xaction->getNewValue();
    }

    return $statuses;
  }

}
