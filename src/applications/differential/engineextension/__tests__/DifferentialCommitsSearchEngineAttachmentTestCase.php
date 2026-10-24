<?php

final class DifferentialCommitsSearchEngineAttachmentTestCase
  extends PhabricatorTestCase {

  public function testFirstPublicParent() {
    $commit = array(
      'commit' => 'a0b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e6f7a8b9',
      'parents' => array('b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e6f7a8b9c0'),
      'author' => 'Author',
      'authorEmail' => 'author@example.com',
      'time' => '1700000000',
      'message' => 'Bug 1 - Fix things.',
    );
    $public_parent = 'c2d3e4f5a6b7c8d9e0f1a2b3c4d5e6f7a8b9c0d1';

    $cases = array(
      'present' => array(
        $commit + array('firstPublicParent' => $public_parent),
        $public_parent,
      ),
      'missing' => array($commit, null),
    );

    foreach ($cases as $case => $spec) {
      list($local_commit, $expect) = $spec;

      $diff = id(new DifferentialDiff())->setID(1);
      $data = array(1 => array($local_commit['commit'] => $local_commit));

      $result = id(new DifferentialCommitsSearchEngineAttachment())
        ->getAttachmentForObject($diff, $data, null);

      $this->assertEqual(
        $expect,
        $result['commits'][0]['firstPublicParent'],
        pht(
          'The `firstPublicParent` of the `commits` attachment should be '.
          'passed through from `local:commits` when %s.',
          $case));
    }
  }

}
