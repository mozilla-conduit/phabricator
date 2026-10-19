<?php

final class MozExtensionsLibraryMapBuilderTestCase extends PhabricatorTestCase {

  public function testDuplicateClassThrows() {
    $this->assertDuplicateThrows('<?php final class Duplicate {}');
  }

  public function testDuplicateFunctionThrows() {
    $this->assertDuplicateThrows('<?php function duplicate() {}');
  }

  private function assertDuplicateThrows($source) {
    $root = Filesystem::createTemporaryDirectory();
    Filesystem::createDirectory($root.'/first');
    Filesystem::createDirectory($root.'/second');
    Filesystem::writeFile($root.'/first/Duplicate.php', $source);
    Filesystem::writeFile($root.'/second/Duplicate.php', $source);

    $caught = null;
    try {
      id(new MozExtensionsLibraryMapBuilder($root))->buildMap();
    } catch (Exception $ex) {
      $caught = $ex;
    }

    Filesystem::remove($root);

    $this->assertTrue(
      $caught instanceof Exception,
      pht('Declaring a symbol in two files should throw.'));
  }

}
