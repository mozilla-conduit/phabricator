<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

final class RevisionMergeConflictEngineTestCase extends PhabricatorTestCase {

  public function testParseGitVersion() {
    $cases = array(
      'git version 2.30.2' => '2.30.2',
      'git version 2.39.5 (Apple Git-154)' => '2.39.5',
      "git version 2.45.1\n" => '2.45.1',
      'unexpected output' => '0.0.0',
      '' => '0.0.0',
    );

    foreach ($cases as $input => $expected) {
      $this->assertEqual(
        $expected,
        RevisionMergeConflictEngine::parseGitVersion($input),
        pht('Parsing version from "%s".', $input));
    }
  }

  public function testMinimumGitVersion() {
    // `--write-tree` needs git 2.38 and `--merge-base` needs 2.40, so 2.40 is
    // the floor. Anything older cannot be checked at all.
    $modern = RevisionMergeConflictEngine::MINIMUM_GIT_VERSION;

    $this->assertTrue(
      version_compare('2.40.0', $modern, '>='),
      pht('git 2.40.0 meets the minimum.'));
    $this->assertTrue(
      version_compare('2.45.1', $modern, '>='),
      pht('git 2.45.1 meets the minimum.'));
    $this->assertFalse(
      version_compare('2.39.5', $modern, '>='),
      pht('git 2.39.5 is below the minimum and must be rejected.'));
    $this->assertFalse(
      version_compare('2.30.2', $modern, '>='),
      pht('git 2.30.2 is below the minimum and must be rejected.'));
    $this->assertFalse(
      version_compare('0.0.0', $modern, '>='),
      pht('An unparseable version must be rejected rather than assumed good.'));
  }

  public function testIsAncestorExitCode() {
    $this->assertTrue(
      RevisionMergeConflictEngine::isAncestorExitCode(0),
      pht('A zero exit proves the base is on the target branch.'));

    $this->assertFalse(
      RevisionMergeConflictEngine::isAncestorExitCode(1),
      pht('An exit code of `1` means the base is not on the target branch.'));

    $this->assertFalse(
      RevisionMergeConflictEngine::isAncestorExitCode(128),
      pht(
        'A git error means ancestry is unproven, which must be treated the '.
        'same as a base that is not on the target branch.'));
  }

}
