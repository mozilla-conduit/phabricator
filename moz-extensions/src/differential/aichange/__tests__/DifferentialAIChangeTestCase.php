<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

final class DifferentialAIChangeTestCase extends PhabricatorTestCase {

  protected function getPhabricatorTestCaseConfiguration() {
    return array(
      self::PHABRICATOR_TESTCONFIG_BUILD_STORAGE_FIXTURES => true,
    );
  }

  public function testRequestMarksRevisionPending() {
    $author = $this->generateNewTestUser();
    $lando = $this->generateNewTestUser();
    list($revision, $conflict_diff, $change_diff) =
      $this->newResolvedRevision($author);

    $this->assertEqual(
      DifferentialAIChange::STATUS_NONE,
      $this->loadAIChange($revision)->getStatus(),
      pht('A revision without a request should not need an approval.'));

    $this->requestApproval($lando, $revision, array(
      'changeDiffID' => (int)$change_diff->getID(),
      'baseDiffID' => (int)$conflict_diff->getID(),
      'summary' => 'Kept the beta API.',
      'confidence' => 'high',
      'details' => 'Moved the overflow handling into `updateOverflow()`.',
    ));

    $ai_change = $this->loadAIChange($revision);
    $this->assertEqual(
      DifferentialAIChange::STATUS_PENDING,
      $ai_change->getStatus(),
      pht('A request should mark the revision pending.'));
    $this->assertEqual(
      $change_diff->getPHID(),
      $ai_change->getChangeDiffPHID(),
      pht('The request should record the change diff.'));
    $this->assertEqual(
      (int)$conflict_diff->getID(),
      $ai_change->getBaseDiffID(),
      pht('The request should record the diff to compare with.'));
    $this->assertEqual(
      $lando->getPHID(),
      $ai_change->getRequesterPHID(),
      pht('The request should record who made it.'));
    $this->assertEqual(
      'Kept the beta API.',
      $ai_change->getSummary(),
      pht('The request should record the summary.'));
    $this->assertEqual(
      'high',
      $ai_change->getConfidence(),
      pht('The request should record the confidence.'));
  }

  public function testRequestAcceptsStringDiffIDs() {
    $author = $this->generateNewTestUser();
    list($revision, $conflict_diff, $change_diff) =
      $this->newResolvedRevision($author);

    $this->requestApproval($author, $revision, array(
      'changeDiffID' => (string)$change_diff->getID(),
      'baseDiffID' => (string)$conflict_diff->getID(),
    ));

    $ai_change = $this->loadAIChange($revision);
    $this->assertEqual(
      DifferentialAIChange::STATUS_PENDING,
      $ai_change->getStatus(),
      pht('Form-encoded diff IDs should be accepted.'));
    $this->assertEqual(
      (int)$conflict_diff->getID(),
      $ai_change->getBaseDiffID(),
      pht('String diff IDs should be stored as integers.'));
  }

  public function testRequestLapsesOnNewDiff() {
    $author = $this->generateNewTestUser();
    list($revision, $conflict_diff, $change_diff) =
      $this->newResolvedRevision($author);

    $this->requestApproval($author, $revision, array(
      'changeDiffID' => (int)$change_diff->getID(),
    ));
    $this->updateRevision($author, $revision);

    $this->assertEqual(
      DifferentialAIChange::STATUS_LAPSED,
      $this->loadAIChange($revision)->getStatus(),
      pht('A newer diff should make the approval no longer required.'));
  }

  public function testRejectInvalidRequests() {
    $author = $this->generateNewTestUser();
    list($revision, $conflict_diff, $change_diff) =
      $this->newResolvedRevision($author);
    list($other_revision) = $this->newResolvedRevision($author);
    $other_diff = $other_revision->loadActiveDiff();

    $change_diff_id = (int)$change_diff->getID();
    $cases = array(
      'not a map' => 'yes',
      'missing kind' => array(
        'kind' => null,
        'changeDiffID' => $change_diff_id,
      ),
      'unknown kind' => array(
        'kind' => 'vibes',
        'changeDiffID' => $change_diff_id,
      ),
      'missing change diff' => array(
        'summary' => 'Kept the beta API.',
      ),
      'change diff is not active' => array(
        'changeDiffID' => (int)$conflict_diff->getID(),
      ),
      'base diff on another revision' => array(
        'changeDiffID' => $change_diff_id,
        'baseDiffID' => (int)$other_diff->getID(),
      ),
      'base diff is the change diff' => array(
        'changeDiffID' => $change_diff_id,
        'baseDiffID' => $change_diff_id,
      ),
      'unknown key' => array(
        'changeDiffID' => $change_diff_id,
        'approver' => 'someone',
      ),
      'summary is not a string' => array(
        'changeDiffID' => $change_diff_id,
        'summary' => array('Kept the beta API.'),
      ),
    );

    foreach ($cases as $label => $request) {
      $caught = null;
      try {
        $this->requestApproval($author, $revision, $request);
      } catch (Exception $ex) {
        $caught = $ex;
      }

      $this->assertTrue(
        ($caught instanceof Exception),
        pht('An invalid request (%s) should be rejected.', $label));
    }

    $this->assertEqual(
      DifferentialAIChange::STATUS_NONE,
      $this->loadAIChange($revision)->getStatus(),
      pht('Rejected requests should leave the revision unchanged.'));
  }

  /**
   * Create a revision whose first diff is a merge conflict and whose second,
   * active diff is the change.
   */
  private function newResolvedRevision(PhabricatorUser $author) {
    $conflict_diff = $this->newDiff($author, "<<<<<<< HEAD\nbeta\n");

    $result = id(new ConduitCall(
      'differential.revision.edit',
      array(
        'transactions' => array(
          array(
            'type' => 'update',
            'value' => $conflict_diff->getPHID(),
          ),
          array(
            'type' => 'title',
            'value' => pht('Uplift'),
          ),
          array(
            'type' => 'testPlan',
            'value' => pht('Ran it.'),
          ),
        ),
      )))
      ->setUser($author)
      ->execute();

    $revision = $this->reloadRevision($author, $result['object']['phid']);
    $change_diff = $this->updateRevision($author, $revision);

    return array(
      $this->reloadRevision($author, $revision->getPHID()),
      $conflict_diff,
      $change_diff,
    );
  }

  private function updateRevision(
    PhabricatorUser $author,
    DifferentialRevision $revision) {

    $diff = $this->newDiff($author, "resolved\n");
    $this->editRevision($author, $revision, array(
      array(
        'type' => 'update',
        'value' => $diff->getPHID(),
      ),
    ));

    return $diff;
  }

  private function newDiff(PhabricatorUser $author, $content) {
    $lines = explode("\n", rtrim($content, "\n"));
    $hunk = implode('', array_map(
      function ($line) {
        return '+'.$line."\n";
      },
      $lines));

    $raw_diff =
      "diff --git a/tabs.js b/tabs.js\n".
      "new file mode 100644\n".
      "index 0000000..1c0f49d\n".
      "--- /dev/null\n".
      "+++ b/tabs.js\n".
      '@@ -0,0 +1,'.count($lines)." @@\n".
      $hunk;

    $parser = new ArcanistDiffParser();
    $changes = $parser->parseDiff($raw_diff);

    return DifferentialDiff::newFromRawChanges($author, $changes)
      ->setLintStatus(DifferentialLintStatus::LINT_AUTO_SKIP)
      ->setUnitStatus(DifferentialUnitStatus::UNIT_AUTO_SKIP)
      ->attachRevision(null)
      ->save();
  }

  private function requestApproval(
    PhabricatorUser $actor,
    DifferentialRevision $revision,
    $request) {

    // Most tests don't care about the kind, so default it.
    if (is_array($request) && !array_key_exists('kind', $request)) {
      $request['kind'] = DifferentialAIChange::KIND_MERGE_CONFLICT;
    }

    return $this->editRevision($actor, $revision, array(
      array(
        'type' => 'ai-change.request',
        'value' => $request,
      ),
    ));
  }

  private function editRevision(
    PhabricatorUser $actor,
    DifferentialRevision $revision,
    array $xactions) {

    return id(new ConduitCall(
      'differential.revision.edit',
      array(
        'objectIdentifier' => $revision->getPHID(),
        'transactions' => $xactions,
      )))
      ->setUser($actor)
      ->execute();
  }

  private function reloadRevision(PhabricatorUser $viewer, $phid) {
    return id(new DifferentialRevisionQuery())
      ->setViewer($viewer)
      ->withPHIDs(array($phid))
      ->executeOne();
  }

  private function loadAIChange(DifferentialRevision $revision) {
    $revision = $this->reloadRevision(
      PhabricatorUser::getOmnipotentUser(),
      $revision->getPHID());

    return DifferentialAIChange::newFromRevision($revision);
  }

}
