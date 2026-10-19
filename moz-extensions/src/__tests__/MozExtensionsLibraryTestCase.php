<?php

/**
 * Library-level checks for moz-extensions.
 *
 * XHPAST can not parse the PHP 7.4 syntax this library uses, so the library
 * map is built by `MozExtensionsLibraryMapBuilder` rather than `arc liberate`.
 */
final class MozExtensionsLibraryTestCase extends PhutilLibraryTestCase {

  public function testLibraryMap() {
    $library_map = PhutilBootloader::getInstance()
      ->getLibraryMapWithoutExtensions($this->getLibraryName());
    unset($library_map[PhutilLibraryMapBuilder::LIBRARY_MAP_VERSION_KEY]);

    $this->assertEqual(
      id(new MozExtensionsLibraryMapBuilder($this->getLibraryRoot()))
        ->buildMap(),
      $library_map,
      pht('The library map is out of date. Rebuild it with `just liberate`.'));
  }

}
