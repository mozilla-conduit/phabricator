<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

final class ReviewHelperFrontmatterTestCase extends PhabricatorTestCase {

  public function testParsesAllFields() {
    $frontmatter = ReviewHelperFrontmatter::newFromLines(array(
      "  severity: critical\n",
      "  category: security\n",
      "  confidence: high\n",
    ));

    $this->assertEqual(
      array(
        'severity' => 'critical',
        'category' => 'security',
        'confidence' => 'high',
      ),
      $frontmatter->toDictionary(),
      pht('Every known field should be parsed.'));
    $this->assertEqual(
      'Critical'.ReviewHelperFrontmatter::LABEL_SEPARATOR.'Security',
      $frontmatter->getLabel(),
      pht('The label should show the severity and category.'));
  }

  public function testOptionalFieldsAndUnknownKeys() {
    $frontmatter = ReviewHelperFrontmatter::newFromLines(array(
      '  severity: nit',
      '  future_field: anything',
    ));

    $this->assertEqual(null, $frontmatter->getCategory(),
      pht('The category should be optional.'));
    $this->assertEqual(null, $frontmatter->getConfidence(),
      pht('The confidence should be optional.'));
    $this->assertEqual('Nit', $frontmatter->getLabel(),
      pht('Without a category, the label should only show the severity.'));
  }

  public function testRejectsInvalidFrontmatter() {
    $cases = array(
      'unknown severity' => array('  severity: catastrophic'),
      'missing severity' => array('  category: security'),
      'unknown category' => array('  severity: nit', '  category: vibes'),
      'unknown confidence' => array('  severity: nit', '  confidence: low'),
      'unindented line' => array('severity: nit'),
      'non key-value line' => array('  severity: nit', '  - item'),
    );

    foreach ($cases as $name => $lines) {
      $caught = null;
      try {
        ReviewHelperFrontmatter::newFromLines($lines);
      } catch (ReviewHelperFrontmatterException $ex) {
        $caught = $ex;
      }

      $this->assertTrue(
        $caught instanceof ReviewHelperFrontmatterException,
        pht('Frontmatter with a %s should be rejected.', $name));
    }
  }

}
