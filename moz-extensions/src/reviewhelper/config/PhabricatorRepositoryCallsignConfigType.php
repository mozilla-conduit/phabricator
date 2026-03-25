<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * Custom config type that accepts repository callsigns (one per line)
 * and stores their PHIDs for efficient runtime lookups.
 */
final class PhabricatorRepositoryCallsignConfigType
  extends PhabricatorConfigOptionType {

  public function validateOption(PhabricatorConfigOption $option, $value) {
    if (!is_array($value)) {
      throw new Exception(
        pht('Repository configuration must be a list of PHIDs.'));
    }

    foreach ($value as $phid) {
      if (!is_string($phid)) {
        throw new Exception(
          pht('Each repository entry must be a PHID string.'));
      }
    }
  }

  public function readRequest(
    PhabricatorConfigOption $option,
    AphrontRequest $request) {

    $e_value = null;
    $errors = array();
    $display_value = $request->getStr('value');

    $callsigns = $this->parseCallsigns($display_value);

    if (!$callsigns) {
      return array($e_value, $errors, array(), $display_value);
    }

    $repositories = id(new PhabricatorRepositoryQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withCallsigns($callsigns)
      ->execute();

    $found = array();
    foreach ($repositories as $repository) {
      $found[$repository->getCallsign()] = $repository->getPHID();
    }

    $phids = array();
    foreach ($callsigns as $callsign) {
      if (!isset($found[$callsign])) {
        $e_value = pht('Invalid');
        $errors[] = pht(
          'Repository callsign "%s" does not match any existing repository.',
          $callsign);
      } else {
        $phids[] = $found[$callsign];
      }
    }

    if ($errors) {
      return array($e_value, $errors, array(), $display_value);
    }

    return array($e_value, $errors, $phids, $display_value);
  }

  public function getDisplayValue(
    PhabricatorConfigOption $option,
    PhabricatorConfigEntry $entry,
    $value) {

    if (!is_array($value) || !$value) {
      return '';
    }

    $repositories = id(new PhabricatorRepositoryQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs($value)
      ->execute();

    $callsigns = array();
    foreach ($repositories as $repository) {
      $callsigns[] = $repository->getCallsign();
    }

    return implode("\n", $callsigns);
  }

  public function renderControl(
    PhabricatorConfigOption $option,
    $display_value,
    $e_value) {

    return id(new AphrontFormTextAreaControl())
      ->setHeight(AphrontFormTextAreaControl::HEIGHT_SHORT)
      ->setName('value')
      ->setLabel(pht('Repository Callsigns'))
      ->setCaption(pht('Enter one repository callsign per line.'))
      ->setValue($display_value)
      ->setError($e_value);
  }

  private function parseCallsigns($input) {
    $lines = phutil_split_lines($input, false);
    $callsigns = array();
    foreach ($lines as $line) {
      $line = trim($line);
      if (strlen($line)) {
        $callsigns[] = $line;
      }
    }
    return $callsigns;
  }

}
