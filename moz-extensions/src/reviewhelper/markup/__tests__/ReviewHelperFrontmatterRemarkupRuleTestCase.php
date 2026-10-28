<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

final class ReviewHelperFrontmatterRemarkupRuleTestCase
  extends PhabricatorTestCase {

  const COMMENT =
    "---\n".
    "reviewhelper:\n".
    "  severity: critical\n".
    "  category: security\n".
    "  confidence: high\n".
    "---\n".
    "Fix the principal.";

  public function testTextModeRendersLabel() {
    $this->assertEqual(
      '['.$this->getLabel()."]\n\nFix the principal.",
      $this->renderText(self::COMMENT),
      pht('Text mode should replace the frontmatter with a bracketed label.'));
  }

  public function testHTMLModeRendersLabelWithData() {
    $html = $this->renderHTML(self::COMMENT);

    $this->assertTrue(
      strpos(
        $html,
        'data-reviewhelper-frontmatter="'.
          phutil_escape_html(phutil_json_encode(array(
            'severity' => 'critical',
            'category' => 'security',
            'confidence' => 'high',
          ))).'"') !== false,
      pht('The label should carry the parsed frontmatter as JSON.'));
    $this->assertTrue(
      strpos(
        $html,
        '<strong>Critical</strong>'.
          ReviewHelperFrontmatter::LABEL_SEPARATOR.'Security') !== false,
      pht('The label should show the severity and category.'));
    $this->assertFalse(
      strpos($html, 'reviewhelper:') !== false,
      pht('The raw YAML should not be rendered.'));
    $this->assertTrue(
      strpos($html, 'style="display: none;"') !== false,
      pht('The label should be hidden until the behavior turns it into a '.
        'badge.'));
  }

  public function testQuotedFrontmatterRendersLabel() {
    $quoted = '> '.str_replace("\n", "\n> ", self::COMMENT)."\n\nThanks!";

    $this->assertEqual(
      '> ['.$this->getLabel()."]\n>\n> Fix the principal.\n\nThanks!",
      $this->renderText($quoted),
      pht('Frontmatter quoted in a reply should also be replaced.'));
  }

  public function testFrontmatterOnlyMatchesAtStart() {
    $text = $this->renderText("Some text.\n\n".self::COMMENT);

    $this->assertTrue(
      strpos($text, 'severity: critical') !== false,
      pht('Frontmatter after other text should be left alone.'));
  }

  public function testInvalidFrontmatterIsNotRendered() {
    $comment = str_replace('critical', 'catastrophic', self::COMMENT);

    // The engine still joins the empty block to the next one with a blank
    // line, so leading whitespace is expected.
    $this->assertEqual(
      'Fix the principal.',
      ltrim($this->renderText($comment)),
      pht('A block that fails to parse should render nothing in its place.'));
  }

  public function testOtherYAMLIsNotMatched() {
    $comment = "---\nname: example\n---\nSome YAML.";

    $this->assertTrue(
      strpos($this->renderText($comment), 'name: example') !== false,
      pht('YAML without a `reviewhelper` key should be left alone.'));
  }

  public function testUnterminatedBlockIsNotMatched() {
    $comment = "---\nreviewhelper:\n  severity: nit\nNo closing delimiter.";

    $this->assertTrue(
      strpos($this->renderText($comment), 'severity: nit') !== false,
      pht('A block without a closing `---` should be left alone.'));
  }

  public function testExtractFrontmatterBlock() {
    $lines = phutil_split_lines("---\nreviewhelper:\n  severity: nit\n---\n");

    $this->assertEqual(
      array('lines' => array("  severity: nit\n"), 'lineCount' => 4),
      ReviewHelperFrontmatterRemarkupRule::extractFrontmatterBlock($lines),
      pht('Extraction should return the lines inside the block.'));
  }

  private function getLabel() {
    return 'Critical'.ReviewHelperFrontmatter::LABEL_SEPARATOR.'Security';
  }

  private function renderText($corpus) {
    return rtrim(
      $this->newEngine(PhutilRemarkupEngine::MODE_TEXT)->markupText($corpus));
  }

  private function renderHTML($corpus) {
    return (string)$this->newEngine(PhutilRemarkupEngine::MODE_DEFAULT)
      ->markupText($corpus);
  }

  private function newEngine($mode) {
    return PhabricatorMarkupEngine::newMarkupEngine(array())
      ->setConfig('viewer', new PhabricatorUser())
      ->setMode($mode);
  }

}
