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

  private static $severities = array('critical', 'major', 'minor', 'nit');
  // Each category and the emoji shown on its badge, escaped to keep this file
  // ASCII.
  private static $categories = array(
    'security' => "\u{1F512}",
    'correctness' => "\u{1F41B}",
    'performance' => "\u{26A1}",
    'maintainability' => "\u{1F527}",
    'style' => "\u{1F3A8}",
    'scope' => "\u{1F3AF}",
  );

  private $severity;
  private $category;

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
      $values, 'category', array_keys(self::$categories), false);

    return $frontmatter;
  }

  public function getSeverity() {
    return $this->severity;
  }

  public function getCategory() {
    return $this->category;
  }

  public function getSeverityLabel() {
    return ucfirst($this->severity);
  }

  /**
   * The human-readable category, or `null` when there is no category.
   */
  public function getCategoryLabel() {
    if ($this->category === null) {
      return null;
    }

    return ucfirst($this->category);
  }

  /**
   * The emoji for the category, or `null` when there is no category.
   */
  public function getCategoryEmoji() {
    if ($this->category === null) {
      return null;
    }

    return self::$categories[$this->category];
  }

  /**
   * The severity and category as bracketed labels, such as
   * `[Critical] [Security]`, for plain-text contexts like email.
   */
  public function getTextLabel() {
    $text = '['.$this->getSeverityLabel().']';
    if ($this->category !== null) {
      $text .= ' ['.$this->getCategoryLabel().']';
    }

    return $text;
  }

  /**
   * The values as a dictionary, for passing to the `reviewhelper-feedback`
   * behavior.
   */
  public function toDictionary() {
    return array(
      'severity' => $this->severity,
      'category' => $this->category,
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
