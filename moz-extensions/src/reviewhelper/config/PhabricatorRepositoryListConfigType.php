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
    return $value;
  }

  public function renderControl(
    PhabricatorConfigOption $option,
    $display_value,
    $e_value
  ) {
    return id(new AphrontFormTokenizerControl())
      ->setName('value')
      ->setLabel(pht('Repositories'))
      ->setDatasource(new DiffusionRepositoryDatasource())
      ->setValue($display_value)
      ->setError($e_value);
  }
}
