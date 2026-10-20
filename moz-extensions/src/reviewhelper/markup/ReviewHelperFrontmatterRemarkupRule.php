<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * Renders the severity frontmatter that Review Helper adds to its comments.
 *
 * Review Helper starts each inline comment with a YAML block like:
 *
 *   ---
 *   reviewhelper:
 *     severity: critical
 *     category: security
 *     confidence: high
 *   ---
 *
 * This rule extracts the block, parses it into a `ReviewHelperFrontmatter`,
 * and replaces it with a short label of the severity and category. If the
 * block can't be parsed, nothing is rendered in its place. The label carries
 * the parsed values as JSON so the `reviewhelper-feedback` behavior can turn it
 * into a badge on comments written by the bot. This rule cannot see the
 * comment author, so the label itself is not proof that Review Helper wrote
 * the comment.
 */
final class ReviewHelperFrontmatterRemarkupRule
  extends PhabricatorRemarkupCustomBlockRule {

  const MAX_LINES = 12;

  /**
   * Run before `PhutilRemarkupHorizontalRuleBlockRule` (priority 300), which
   * would otherwise claim the opening `---` line.
   */
  public function getPriority() {
    return 250;
  }

  public function getMatchingLineCount(array $lines, $cursor) {
    // Only match at the start of a comment, or of a quoted comment.
    if ($cursor !== 0) {
      return 0;
    }

    $block = self::extractFrontmatterBlock($lines);
    if (!$block) {
      return 0;
    }

    $num_lines = $block['lineCount'];
    while (isset($lines[$num_lines]) && !strlen(trim($lines[$num_lines]))) {
      $num_lines++;
    }

    return $num_lines;
  }

  public function markupText($text, $children) {
    $block = self::extractFrontmatterBlock(phutil_split_lines($text, true));

    try {
      $frontmatter = ReviewHelperFrontmatter::newFromLines($block['lines']);
    } catch (ReviewHelperFrontmatterException $ex) {
      return '';
    }

    if ($this->getEngine()->isTextMode()) {
      return '['.$frontmatter->getLabel().']';
    }

    $label = array(
      phutil_tag('strong', array(), ucfirst($frontmatter->getSeverity())),
    );
    if ($frontmatter->getCategory() !== null) {
      $label[] = ReviewHelperFrontmatter::LABEL_SEPARATOR.
        ucfirst($frontmatter->getCategory());
    }

    return phutil_tag(
      'p',
      array(
        'class' => 'reviewhelper-severity-label',
        'data-reviewhelper-frontmatter' => phutil_json_encode(
          $frontmatter->toDictionary()),
      ),
      $label);
  }

  /**
   * Find a `reviewhelper:` frontmatter block at the start of `$lines`.
   *
   * This only checks the delimiters and the `reviewhelper:` key. The lines
   * inside are parsed by `ReviewHelperFrontmatter`.
   *
   * @return array|null The lines inside the block and the number of lines the
   *   block spans, or `null` if `$lines` does not start with a block.
   */
  public static function extractFrontmatterBlock(array $lines) {
    if (count($lines) < 3) {
      return null;
    }

    if (rtrim($lines[0]) !== '---' || rtrim($lines[1]) !== 'reviewhelper:') {
      return null;
    }

    $last_line = min(count($lines), self::MAX_LINES);
    for ($index = 2; $index < $last_line; $index++) {
      if (rtrim($lines[$index]) === '---') {
        return array(
          'lines' => array_slice($lines, 2, $index - 2),
          'lineCount' => $index + 1,
        );
      }
    }

    return null;
  }

}
