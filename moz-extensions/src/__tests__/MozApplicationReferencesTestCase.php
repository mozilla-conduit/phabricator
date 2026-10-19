<?php

/**
 * Check that strings naming application classes name real applications.
 *
 * Code often names applications in strings, as in `isClassInstalled()` or
 * `getQueryApplicationClass()`, which PHPStan can not follow. Deleting an
 * application leaves these behind, and they only fail at runtime.
 */
final class MozApplicationReferencesTestCase extends PhabricatorTestCase {

  public function testApplicationClassNamesExist() {
    $symbols = id(new PhutilSymbolLoader())
      ->setAncestorClass('PhabricatorApplication')
      ->selectSymbolsWithoutLoading();
    $applications = ipull($symbols, 'name', 'name');

    $unknown = array();
    foreach (array('phabricator', 'moz-extensions') as $library) {
      $root = phutil_get_library_root($library);
      foreach ($this->findApplicationClassNames($root) as $reference) {
        list($name, $location) = $reference;
        if (empty($applications[$name])) {
          $unknown[] = pht('"%s" in %s', $name, $location);
        }
      }
    }

    $this->assertEqual(
      array(),
      $unknown,
      pht('These strings name application classes that do not exist.'));
  }

  /**
   * Find quoted `Phabricator...Application` names in a library's source.
   *
   * @return list<pair<string, string>> Each name with its file and line.
   */
  private function findApplicationClassNames($root) {
    $files = id(new FileFinder($root))
      ->withType('f')
      ->withSuffix('php')
      ->find();

    $references = array();
    foreach ($files as $file) {
      $path = ltrim(Filesystem::readablePath($file, $root), '/');
      $source = Filesystem::readFile($root.'/'.$path);

      $matches = null;
      preg_match_all(
        '/([\'"])(Phabricator[A-Za-z0-9]+Application)\1/',
        $source,
        $matches,
        PREG_OFFSET_CAPTURE);

      foreach ($matches[2] as $match) {
        list($name, $offset) = $match;
        $line = substr_count($source, "\n", 0, $offset) + 1;
        $references[] = array($name, $path.':'.$line);
      }
    }

    return $references;
  }

}
