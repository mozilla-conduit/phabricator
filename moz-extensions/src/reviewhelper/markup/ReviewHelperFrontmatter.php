<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * The severity metadata Review Helper attaches to one of its inline comments.
 *
 * This is parsed from the lines inside a `reviewhelper:` frontmatter block,
 * such as `  severity: critical`, and rejects anything it does not expect.
 */
final class ReviewHelperFrontmatter extends Phobject {

  // A middle dot with spaces around it, escaped to keep this file ASCII.
  const LABEL_SEPARATOR = " \xC2\xB7 ";

  private static $severities = array('critical', 'major', 'minor', 'nit');
  private static $categories = array(
    'security',
    'correctness',
    'performance',
    'maintainability',
    'style',
    'scope',
  );
  private static $confidences = array('high', 'medium');

  private $severity;
  private $category;
  private $confidence;

  /**
   * Parse the `key: value` lines of a `reviewhelper:` frontmatter block.
   *
   * Unknown keys are ignored, so Review Helper can add fields before
   * Phabricator knows about them.
   *
   * @param list<string> $lines The lines between `reviewhelper:` and the
   *   closing `---`.
   * @return ReviewHelperFrontmatter
   * @throws ReviewHelperFrontmatterException If a line is malformed or a value
   *   is missing or unknown.
   */
  public static function newFromLines(array $lines) {
    $values = array();
    foreach ($lines as $line) {
      // Each line must be an indented `key: value` pair, such as
      // `  severity: critical`, where both the key and value are lowercase
      // letters or underscores.
      $matches = null;
      $line = rtrim($line);
      if (!preg_match('/^\s+([a-z_]+):\s*([a-z_]+)$/', $line, $matches)) {
        throw new ReviewHelperFrontmatterException(
          pht('Malformed frontmatter line: %s', $line));
      }
      $values[$matches[1]] = $matches[2];
    }

    $frontmatter = new self();
    $frontmatter->severity = self::readValue(
      $values, 'severity', self::$severities, true);
    $frontmatter->category = self::readValue(
      $values, 'category', self::$categories, false);
    $frontmatter->confidence = self::readValue(
      $values, 'confidence', self::$confidences, false);

    return $frontmatter;
  }

  public function getSeverity() {
    return $this->severity;
  }

  public function getCategory() {
    return $this->category;
  }

  public function getConfidence() {
    return $this->confidence;
  }

  /**
   * A short human-readable label: the severity and category joined by
   * `LABEL_SEPARATOR`, or only the severity when there is no category.
   */
  public function getLabel() {
    if ($this->category === null) {
      return ucfirst($this->severity);
    }

    return ucfirst($this->severity).self::LABEL_SEPARATOR.
      ucfirst($this->category);
  }

  /**
   * The values as a dictionary, for passing to the `reviewhelper-feedback`
   * behavior.
   */
  public function toDictionary() {
    return array(
      'severity' => $this->severity,
      'category' => $this->category,
      'confidence' => $this->confidence,
    );
  }

  private static function readValue(
    array $values,
    $key,
    array $allowed,
    $is_required) {

    $value = idx($values, $key);
    if ($value === null) {
      if ($is_required) {
        throw new ReviewHelperFrontmatterException(
          pht('Frontmatter is missing "%s".', $key));
      }
      return null;
    }

    if (!in_array($value, $allowed, true)) {
      throw new ReviewHelperFrontmatterException(
        pht('Unknown frontmatter %s "%s".', $key, $value));
    }

    return $value;
  }

}
