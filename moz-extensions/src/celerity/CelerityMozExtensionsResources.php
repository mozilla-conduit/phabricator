<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * Celerity resource source for moz-extensions.
 * This allows moz-extensions to provide its own JavaScript and CSS resources.
 */
final class CelerityMozExtensionsResources extends CelerityResourcesOnDisk {

  public function getName() {
    return 'moz-extensions';
  }

  public function getPathToResources() {
    return $this->getMozExtensionsPath('webroot/');
  }

  public function getPathToMap() {
    return $this->getMozExtensionsPath('resources/celerity/map.php');
  }

  private function getMozExtensionsPath($to_file = '') {
    $root = dirname(dirname(dirname(__FILE__)));
    return $root . '/' . $to_file;
  }
}
