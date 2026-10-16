<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

final class DifferentialRevisionEditInlineTestCase extends PhabricatorTestCase {

  protected function getPhabricatorTestCaseConfiguration() {
    return array(
      self::PHABRICATOR_TESTCONFIG_BUILD_STORAGE_FIXTURES => true,
    );
  }

  public function testPublishInlineComments() {
    $author = $this->generateNewTestUser();
    $reviewer = $this->generateNewTestUser();
    $revision = $this->newRevision($author);
    $diff = $revision->loadActiveDiff();

    $this->editRevision($reviewer, $revision, array(
      array(
        'type' => 'inline',
        'value' => array(
          'path' => 'fruit',
          'line' => 2,
          'length' => 2,
          'content' => 'Is this spelled right?',
          'suggestionText' => "banana\ncherry",
        ),
      ),
    ));

    $inlines = $this->loadInlines($author, $revision);
    $this->assertEqual(1, count($inlines));

    $inline = head($inlines);
    $fields = $inline['fields'];
    $this->assertEqual($reviewer->getPHID(), $inline['authorPHID']);
    $this->assertEqual(
      'Is this spelled right?',
      $inline['comments'][0]['content']['raw']);
    $this->assertEqual((int)$diff->getID(), $fields['diff']['id']);
    $this->assertEqual('fruit', $fields['path']);
    $this->assertEqual(2, $fields['line']);
    $this->assertEqual(2, $fields['length']);
    $this->assertEqual(true, $fields['isNewFile']);
    $this->assertEqual(null, $fields['replyToCommentPHID']);
    $this->assertEqual(false, $fields['isDone']);
    $this->assertEqual(true, $fields['hasSuggestion']);
    $this->assertEqual("banana\ncherry", $fields['suggestionText']);

    // A reply without a path goes on the same lines as its parent. The
    // author's own inlines start out as "Done".
    $parent_phid = $inline['comments'][0]['phid'];
    $this->editRevision($author, $revision, array(
      array(
        'type' => 'inline',
        'value' => array(
          'replyToCommentPHID' => $parent_phid,
          'content' => 'Fixed.',
        ),
      ),
    ));

    $inlines = $this->loadInlines($author, $revision);
    $this->assertEqual(2, count($inlines));

    // "transaction.search" returns the newest transactions first.
    $reply = head($inlines);
    $fields = $reply['fields'];
    $this->assertEqual($author->getPHID(), $reply['authorPHID']);
    $this->assertEqual('Fixed.', $reply['comments'][0]['content']['raw']);
    $this->assertEqual('fruit', $fields['path']);
    $this->assertEqual(2, $fields['line']);
    $this->assertEqual(2, $fields['length']);
    $this->assertEqual($parent_phid, $fields['replyToCommentPHID']);
    $this->assertEqual(true, $fields['isDone']);
    $this->assertEqual(false, $fields['hasSuggestion']);

    // Values from form-encoded calls arrive as strings.
    $this->editRevision($reviewer, $revision, array(
      array(
        'type' => 'inline',
        'value' => array(
          'diff' => (string)$diff->getID(),
          'path' => 'fruit',
          'line' => '1',
          'isNewFile' => 'false',
          'content' => 'Old side.',
        ),
      ),
    ));

    $inlines = $this->loadInlines($author, $revision);
    $fields = head($inlines)['fields'];
    $this->assertEqual(1, $fields['line']);
    $this->assertEqual(1, $fields['length']);
    $this->assertEqual(false, $fields['isNewFile']);
  }

  public function testRejectInvalidInlineComments() {
    $author = $this->generateNewTestUser();
    $revision = $this->newRevision($author);

    $cases = array(
      'not a dictionary' => array(array('fruit'), 'must be a dictionary'),
      'unknown key' => array(
        array(
          'path' => 'fruit',
          'line' => 1,
          'content' => 'x',
          'lineNumber' => 1,
        ),
        'unknown keys: lineNumber',
      ),
      'no path' => array(
        array('line' => 1, 'content' => 'x'),
        'must have a "path"',
      ),
      'unknown path' => array(
        array('path' => 'vegetable', 'line' => 1, 'content' => 'x'),
        'is not a file changed by diff',
      ),
      'no line' => array(
        array('path' => 'fruit', 'content' => 'x'),
        'must have a "line"',
      ),
      'zero line' => array(
        array('path' => 'fruit', 'line' => 0, 'content' => 'x'),
        '"line" must be a positive integer',
      ),
      'zero length' => array(
        array('path' => 'fruit', 'line' => 1, 'length' => 0, 'content' => 'x'),
        '"length" must be a positive integer',
      ),
      'bad isNewFile' => array(
        array(
          'path' => 'fruit',
          'line' => 1,
          'isNewFile' => 'yes',
          'content' => 'x',
        ),
        '"isNewFile" must be a boolean',
      ),
      'empty comment' => array(
        array('path' => 'fruit', 'line' => 1),
        'must have a nonempty',
      ),
      'empty suggestion only' => array(
        array('path' => 'fruit', 'line' => 1, 'suggestionText' => ''),
        'must have a nonempty',
      ),
      'unknown diff' => array(
        array(
          'diff' => 'PHID-DIFF-xxxxxxxxxxxxxxxxxxxx',
          'path' => 'fruit',
          'line' => 1,
          'content' => 'x',
        ),
        'is not a diff of this revision',
      ),
      'unknown reply' => array(
        array(
          'replyToCommentPHID' => 'PHID-XCMT-xxxxxxxxxxxxxxxxxxxx',
          'content' => 'x',
        ),
        'is not a published inline comment',
      ),
    );

    foreach ($cases as $label => $case) {
      list($value, $message) = $case;

      $caught = null;
      try {
        $this->editRevision($author, $revision, array(
          array(
            'type' => 'inline',
            'value' => $value,
          ),
        ));
      } catch (Exception $ex) {
        $caught = $ex;
      }
      $this->assertTrue($caught instanceof Exception, $label);
      $this->assertTrue(
        strpos($caught->getMessage(), $message) !== false,
        pht('%s: %s', $label, $caught->getMessage()));
    }

    $this->assertEqual(array(), $this->loadInlines($author, $revision));
  }

  private function newRevision(PhabricatorUser $author) {
    $raw_diff = <<<EODIFF
diff --git a/fruit b/fruit
new file mode 100644
index 0000000..1c0f49d
--- /dev/null
+++ b/fruit
@@ -0,0 +1,3 @@
+apple
+banan
+cherry
EODIFF;

    $parser = new ArcanistDiffParser();
    $changes = $parser->parseDiff($raw_diff);

    $diff = DifferentialDiff::newFromRawChanges($author, $changes)
      ->setLintStatus(DifferentialLintStatus::LINT_AUTO_SKIP)
      ->setUnitStatus(DifferentialUnitStatus::UNIT_AUTO_SKIP)
      ->attachRevision(null)
      ->save();

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

  private function loadInlines(
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

}
