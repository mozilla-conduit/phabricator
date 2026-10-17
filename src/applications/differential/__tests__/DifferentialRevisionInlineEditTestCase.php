<?php

final class DifferentialRevisionInlineEditTestCase
  extends PhabricatorTestCase {

  protected function getPhabricatorTestCaseConfiguration() {
    return array(
      self::PHABRICATOR_TESTCONFIG_BUILD_STORAGE_FIXTURES => true,
    );
  }

  public function testPublishInlineWithReviewAction() {
    $author = $this->generateNewTestUser();
    $reviewer = $this->generateNewTestUser();
    $revision = $this->newRevision($author);

    $this->editRevision(
      $reviewer,
      $revision,
      array(
        $this->newInlineXaction(
          array(
            'path' => 'fruit',
            'line' => 2,
            'length' => 2,
            'isNewFile' => true,
            'content' => 'Spelling.',
          )),
        array(
          'type' => 'comment',
          'value' => 'Looks good otherwise.',
        ),
        array(
          'type' => 'accept',
          'value' => true,
        ),
      ));

    $inlines = $this->loadInlineTransactions($reviewer, $revision);
    $this->assertEqual(1, count($inlines));

    $inline = head($inlines);
    $fields = $inline['fields'];
    $this->assertEqual('Spelling.', $inline['comments'][0]['content']['raw']);
    $this->assertEqual('fruit', $fields['path']);
    $this->assertEqual(2, $fields['line']);
    $this->assertEqual(2, $fields['length']);
    $this->assertEqual(true, $fields['isNewFile']);
    $this->assertEqual(null, $fields['replyToCommentPHID']);
    $this->assertEqual(false, $fields['hasSuggestion']);

    // The inline is published, not left as a draft, and it is published in
    // the same group as the comment and the accept.
    $comment = $this->loadInlineComment($inline['comments'][0]['phid']);
    $this->assertTrue((bool)$comment->getTransactionPHID());

    $xactions = $this->loadTransactions($reviewer, $revision);
    $group = $this->getGroupID($xactions, $inline['phid']);
    $types = array();
    foreach ($xactions as $xaction) {
      if ($xaction->getTransactionGroupID() === $group) {
        $types[] = $xaction->getTransactionType();
      }
    }
    $this->assertTrue(in_array(PhabricatorTransactions::TYPE_COMMENT, $types));
    $this->assertTrue(
      in_array(DifferentialRevisionAcceptTransaction::TRANSACTIONTYPE, $types));
  }

  public function testReplyWithSuggestion() {
    $author = $this->generateNewTestUser();
    $reviewer = $this->generateNewTestUser();
    $revision = $this->newRevision($author);

    $this->editRevision(
      $reviewer,
      $revision,
      array(
        $this->newInlineXaction(
          array(
            'path' => 'fruit',
            'line' => 1,
            'isNewFile' => true,
            'content' => 'Typo.',
          )),
      ));

    $parent = head($this->loadInlineTransactions($reviewer, $revision));
    $parent_phid = $parent['comments'][0]['phid'];

    // The reply omits the location, so it is placed on the parent's line.
    $this->editRevision(
      $author,
      $revision,
      array(
        $this->newInlineXaction(
          array(
            'replyToCommentPHID' => $parent_phid,
            'content' => '',
            'suggestionText' => "apple\n",
          )),
      ));

    $inlines = $this->loadInlineTransactions($author, $revision);
    $this->assertEqual(2, count($inlines));

    $reply = head($inlines);
    $fields = $reply['fields'];
    $this->assertEqual($parent_phid, $fields['replyToCommentPHID']);
    $this->assertEqual($parent['fields']['diff'], $fields['diff']);
    $this->assertEqual('fruit', $fields['path']);
    $this->assertEqual(1, $fields['line']);
    $this->assertEqual(1, $fields['length']);
    $this->assertEqual(true, $fields['hasSuggestion']);
    $this->assertEqual("apple\n", $fields['suggestionText']);

    $this->assertTrue(
      (bool)$this->loadInlineComment($parent_phid)->getHasReplies());

    // Location keys which disagree with the parent are rejected.
    $this->assertEditFails(
      $author,
      $revision,
      array(
        'replyToCommentPHID' => $parent_phid,
        'line' => 2,
        'content' => 'Elsewhere.',
      ));
  }

  public function testInvalidInlines() {
    $author = $this->generateNewTestUser();
    $reviewer = $this->generateNewTestUser();
    $revision = $this->newRevision($author);

    $valid = array(
      'path' => 'fruit',
      'line' => 1,
      'isNewFile' => true,
      'content' => 'Comment.',
    );

    $invalid = array(
      'unknown path' => array('path' => 'vegetable') + $valid,
      'missing line' => array_diff_key($valid, array('line' => true)),
      'zero line' => array('line' => 0) + $valid,
      'missing isNewFile' => array_diff_key($valid, array('isNewFile' => true)),
      'empty content' => array('content' => '') + $valid,
      'unknown key' => array('isDone' => true) + $valid,
      'unknown diff' => array('diff' => 'PHID-DIFF-nope') + $valid,
      'unknown parent' => array('replyToCommentPHID' => 'PHID-XCMT-nope'),
    );

    foreach ($invalid as $label => $value) {
      $this->assertEditFails($reviewer, $revision, $value, $label);
    }

    $this->assertEqual(
      array(),
      $this->loadInlineTransactions($reviewer, $revision));
  }

  private function newRevision(PhabricatorUser $author) {
    $raw_diff = <<<EODIFF
diff --git a/fruit b/fruit
new file mode 100644
index 0000000..1c0f49d
--- /dev/null
+++ b/fruit
@@ -0,0 +1,2 @@
+apal
+banan
EODIFF;

    $changes = id(new ArcanistDiffParser())->parseDiff($raw_diff);

    $diff = DifferentialDiff::newFromRawChanges($author, $changes)
      ->setLintStatus(DifferentialLintStatus::LINT_AUTO_SKIP)
      ->setUnitStatus(DifferentialUnitStatus::UNIT_AUTO_SKIP)
      ->attachRevision(null)
      ->save();

    $result = id(new ConduitCall(
      'differential.revision.edit',
      array(
        'transactions' => array(
          array('type' => 'update', 'value' => $diff->getPHID()),
          array('type' => 'title', 'value' => 'Add fruit'),
          array('type' => 'testPlan', 'value' => 'None.'),
        ),
      )))
      ->setUser($author)
      ->execute();

    $revision = id(new DifferentialRevisionQuery())
      ->setViewer($author)
      ->withPHIDs(array($result['object']['phid']))
      ->executeOne();

    // New revisions start as drafts, which reviewers can not accept.
    $this->editRevision(
      $author,
      $revision,
      array(
        array('type' => 'request-review', 'value' => true),
      ));

    return $revision;
  }

  private function newInlineXaction(array $value) {
    return array(
      'type' => 'inline',
      'value' => $value,
    );
  }

  private function editRevision(
    PhabricatorUser $viewer,
    DifferentialRevision $revision,
    array $xactions) {

    return id(new ConduitCall(
      'differential.revision.edit',
      array(
        'objectIdentifier' => $revision->getPHID(),
        'transactions' => $xactions,
      )))
      ->setUser($viewer)
      ->execute();
  }

  private function assertEditFails(
    PhabricatorUser $viewer,
    DifferentialRevision $revision,
    array $value,
    $label = null) {

    $caught = null;
    try {
      $this->editRevision(
        $viewer,
        $revision,
        array($this->newInlineXaction($value)));
    } catch (Exception $ex) {
      $caught = $ex;
    } catch (Throwable $ex) {
      $caught = $ex;
    }

    // A rejected inline raises an "Exception". Runtime errors like a
    // "TypeError" are not, so they fail the test.
    $this->assertTrue($caught instanceof Exception, $label);
  }

  private function loadInlineTransactions(
    PhabricatorUser $viewer,
    DifferentialRevision $revision) {

    $result = id(new ConduitCall(
      'transaction.search',
      array(
        'objectIdentifier' => $revision->getPHID(),
      )))
      ->setUser($viewer)
      ->execute();

    $inlines = array();
    foreach ($result['data'] as $xaction) {
      if ($xaction['type'] === 'inline') {
        $inlines[] = $xaction;
      }
    }

    return $inlines;
  }

  private function loadTransactions(
    PhabricatorUser $viewer,
    DifferentialRevision $revision) {

    return id(new DifferentialTransactionQuery())
      ->setViewer($viewer)
      ->withObjectPHIDs(array($revision->getPHID()))
      ->execute();
  }

  private function getGroupID(array $xactions, $phid) {
    foreach ($xactions as $xaction) {
      if ($xaction->getPHID() === $phid) {
        return $xaction->getTransactionGroupID();
      }
    }

    return null;
  }

  private function loadInlineComment($phid) {
    return id(new DifferentialTransactionComment())->loadOneWhere(
      'phid = %s',
      $phid);
  }

}
