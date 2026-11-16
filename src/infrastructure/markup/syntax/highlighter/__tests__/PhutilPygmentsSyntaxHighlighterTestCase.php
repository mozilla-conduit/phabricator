<?php

final class PhutilPygmentsSyntaxHighlighterTestCase extends PhutilTestCase {

  public function testPHPFragmentWithoutOpenTag() {
    $html = $this->highlight('php', "\$x = 1;\n");

    $this->assertTrue(
      strpos($html, '<span class="nv">$x</span>') !== false,
      pht('PHP fragments without an open tag should be lexed as PHP.'));
    $this->assertTrue(
      strpos($html, '&lt;?php') === false,
      pht('PHP fragments should not gain an open tag.'));
  }

  public function testPHPFragmentContainingOpenTagCharacters() {
    $html = $this->highlight('php', "\$x = \"<?xml version=1.0?>\";\n");

    $this->assertTrue(
      strpos($html, '<span class="nv">$x</span>') !== false,
      pht(
        'PHP fragments containing `<?` in a string should be lexed as PHP.'));
  }

  public function testPHPFileWithOpenTag() {
    $html = $this->highlight('php', "<?php\n\$x = 1;\n");

    $this->assertTrue(
      strpos($html, '<span class="cp">&lt;?php</span>') !== false,
      pht('The `<?php` open tag should be highlighted as a single token.'));
    $this->assertTrue(
      strpos($html, '<span class="nv">$x</span>') !== false,
      pht('PHP after the open tag should be lexed as PHP.'));
  }

  private function highlight($language, $source) {
    if (!Filesystem::binaryExists('pygmentize')) {
      $this->assertSkipped(pht('Pygments is not installed.'));
    }

    return (string)id(new PhutilPygmentsSyntaxHighlighter())
      ->setConfig('language', $language)
      ->getHighlightFuture($source)
      ->resolve();
  }

}
