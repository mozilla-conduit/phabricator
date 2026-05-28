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

  public function testModernMergeTreeVersionThreshold() {
    // The modern `--write-tree` form requires git >= 2.38; anything older must
    // use the legacy path.
    $modern = RevisionMergeConflictEngine::MODERN_MERGE_TREE_VERSION;

    $this->assertTrue(
      version_compare('2.38.0', $modern, '>='),
      pht('git 2.38.0 should use the modern merge-tree form.'));
    $this->assertTrue(
      version_compare('2.45.1', $modern, '>='),
      pht('git 2.45.1 should use the modern merge-tree form.'));
    $this->assertFalse(
      version_compare('2.30.2', $modern, '>='),
      pht('git 2.30.2 should fall back to the legacy merge-tree form.'));
    $this->assertFalse(
      version_compare('0.0.0', $modern, '>='),
      pht('Unparseable versions should fall back to the legacy form.'));
  }

  public function testLegacyOutputConflictDetection() {
    $conflicting = <<<EOTEXT
changed in both
  base   100644 1111111 file.txt
  our    100644 2222222 file.txt
  their  100644 3333333 file.txt
@@ -1,1 +1,5 @@
+<<<<<<< .our
+ours
+=======
+theirs
+>>>>>>> .their
EOTEXT;

    $this->assertTrue(
      RevisionMergeConflictEngine::legacyOutputHasConflict($conflicting),
      pht('Output with conflict markers should be detected as a conflict.'));

    $clean = "merged\n  result 100644 4444444 file.txt\n";
    $this->assertFalse(
      RevisionMergeConflictEngine::legacyOutputHasConflict($clean),
      pht('Output without conflict markers should be treated as clean.'));

    $this->assertFalse(
      RevisionMergeConflictEngine::legacyOutputHasConflict(''),
      pht('Empty output should be treated as clean.'));
  }

}
