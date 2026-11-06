<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

final class ReviewHelperFrontmatterTestCase extends PhabricatorTestCase {

  public function testParsesAllFields() {
    $frontmatter = ReviewHelperFrontmatter::newFromLines(array(
      "  severity: critical\n",
      "  category: security\n",
    ));

    $this->assertEqual(
      array(
        'severity' => 'critical',
        'category' => 'security',
      ),
      $frontmatter->toDictionary(),
      pht('Every known field should be parsed.'));
    $this->assertEqual('Critical', $frontmatter->getSeverityLabel(),
      pht('The severity label should be capitalized.'));
    $this->assertEqual('Security', $frontmatter->getCategoryLabel(),
      pht('The category label should be capitalized.'));
    $this->assertEqual('fa-lock', $frontmatter->getCategoryIcon(),
      pht('Security should have a lock icon.'));
    $this->assertEqual('[Critical] [Security]', $frontmatter->getTextLabel(),
      pht('The text label should bracket the severity and category.'));
  }

  public function testOptionalFieldsAndUnknownKeys() {
    $frontmatter = ReviewHelperFrontmatter::newFromLines(array(
      '  severity: nit',
      '  future_field: anything',
    ));

    $this->assertEqual(null, $frontmatter->getCategory(),
      pht('The category should be optional.'));
    $this->assertEqual(null, $frontmatter->getCategoryLabel(),
      pht('Without a category, there should be no category label.'));
    $this->assertEqual(null, $frontmatter->getCategoryIcon(),
      pht('Without a category, there should be no category icon.'));
    $this->assertEqual('[Nit]', $frontmatter->getTextLabel(),
      pht('Without a category, the text label should only show the severity.'));
  }

  public function testRejectsInvalidFrontmatter() {
    $cases = array(
      'unknown severity' => array('  severity: catastrophic'),
      'missing severity' => array('  category: security'),
      'unknown category' => array('  severity: nit', '  category: vibes'),
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
