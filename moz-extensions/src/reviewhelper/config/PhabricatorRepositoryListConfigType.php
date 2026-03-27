<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * Custom config type that provides a searchable repository selector
 * and stores repository PHIDs for efficient runtime lookups.
 */
final class PhabricatorRepositoryListConfigType
extends PhabricatorConfigOptionType {

  public function readRequest(
    PhabricatorConfigOption $option,
    AphrontRequest $request
  ) {
    $e_value = null;
    $errors = array();
    $phids = $request->getArr('value');

    return array($e_value, $errors, $phids, $phids);
  }

  public function getDisplayValue(
    PhabricatorConfigOption $option,
    PhabricatorConfigEntry $entry,
    $value
  ) {
    if (!is_array($value) || !$value) {
      return '';
    }
    return implode("\n", $value);
  }

  public function renderControl(
    PhabricatorConfigOption $option,
    $display_value,
    $e_value
  ) {
    if (is_string($display_value) && strlen($display_value)) {
      $phids = explode("\n", $display_value);
    } else {
      $phids = array();
    }

    return id(new AphrontFormTokenizerControl())
      ->setName('value')
      ->setLabel(pht('Repositories'))
      ->setDatasource(new DiffusionRepositoryDatasource())
      ->setValue($phids)
      ->setError($e_value);
  }
}
