<?php

/* Any copyright is dedicated to the Public Domain.
 * https://creativecommons.org/publicdomain/zero/1.0/ */

final class DifferentialRevisionQueryTestCase extends PhabricatorTestCase {

  protected function getPhabricatorTestCaseConfiguration() {
    return array(
      self::PHABRICATOR_TESTCONFIG_BUILD_STORAGE_FIXTURES => true,
    );
  }

  public function testExcludedAuthors() {
    $alice = $this->generateNewTestUser();
    $bob = $this->generateNewTestUser();

    $alice_revision = $this->newRevision($alice, array());
    $bob_revision = $this->newRevision($bob, array($alice->getPHID()));

    $this->assertEqual(
      array($bob_revision->getPHID()),
      $this->loadRevisionPHIDs(
        id(new DifferentialRevisionQuery())
          ->withExcludedAuthors(array($alice->getPHID()))));

    $both_phids = array($alice_revision->getPHID(), $bob_revision->getPHID());
    sort($both_phids);
    $this->assertEqual(
      $both_phids,
      $this->loadRevisionPHIDs(
        id(new DifferentialRevisionQuery())
          ->withResponsibleUsers(array($alice->getPHID()))));

    $this->assertEqual(
      array($bob_revision->getPHID()),
      $this->loadRevisionPHIDs(
        id(new DifferentialRevisionQuery())
          ->withResponsibleUsers(array($alice->getPHID()))
          ->withExcludedAuthors(array($alice->getPHID()))));
  }

  private function loadRevisionPHIDs(DifferentialRevisionQuery $query) {
    $revisions = $query
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->execute();

    $phids = mpull($revisions, 'getPHID');
    sort($phids);
    return array_values($phids);
  }

  private function newRevision(PhabricatorUser $author, array $reviewers) {
    $raw_diff = <<<EODIFF
diff --git a/fruit b/fruit
new file mode 100644
index 0000000..1c0f49d
--- /dev/null
+++ b/fruit
@@ -0,0 +1 @@
+apple
EODIFF;

    $changes = id(new ArcanistDiffParser())->parseDiff($raw_diff);

    $diff = DifferentialDiff::newFromRawChanges($author, $changes)
      ->setLintStatus(DifferentialLintStatus::LINT_AUTO_SKIP)
      ->setUnitStatus(DifferentialUnitStatus::UNIT_AUTO_SKIP)
      ->attachRevision(null)
      ->save();

    $transactions = array(
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
    );

    if ($reviewers) {
      $transactions[] = array(
        'type' => 'reviewers.add',
        'value' => $reviewers,
      );
    }

    $result = id(new ConduitCall(
      'differential.revision.edit',
      array(
        'transactions' => $transactions,
      )))
      ->setUser($author)
      ->execute();

    return id(new DifferentialRevisionQuery())
      ->setViewer($author)
      ->withPHIDs(array($result['object']['phid']))
      ->executeOne();
  }

}
