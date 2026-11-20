<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * Lets tools like Lando request an AI change approval with `differential.revision.edit`.
 */
final class DifferentialAIChangeEditEngineExtension
  extends PhabricatorEditEngineExtension {

  const EXTENSIONKEY = 'mozilla.ai-change';

  public function isExtensionEnabled() {
    return true;
  }

  public function getExtensionName() {
    return pht('AI Change Approval');
  }

  public function supportsObject(
    PhabricatorEditEngine $engine,
    PhabricatorApplicationTransactionInterface $object) {
    return ($object instanceof DifferentialRevision);
  }

  public function buildCustomEditFields(
    PhabricatorEditEngine $engine,
    PhabricatorApplicationTransactionInterface $object) {

    return array(
      id(new PhabricatorConduitEditField())
        ->setKey(
          DifferentialRevisionAIChangeRequestTransaction::EDITKEY)
        ->setLabel(pht('Request AI Change Approval'))
        ->setIsFormField(false)
        ->setIsReorderable(false)
        ->setIsDefaultable(false)
        ->setIsLockable(false)
        ->setTransactionType(
          DifferentialRevisionAIChangeRequestTransaction::TRANSACTIONTYPE)
        ->setDescription(
          pht('Request approval of an AI change.'))
        ->setConduitDescription(
          pht(
            'Request approval of an AI change in the '.
            'active diff. Send it in its own call, after the update.'))
        ->setConduitTypeDescription(
          pht(
            'Map with "kind" ("merge-conflict" or "requested-edit"), '.
            '"changeDiffID" (the active diff), and optional '.
            '"baseDiffID" (the diff to compare with), "summary", '.
            '"confidence" and "details" (remarkup).')),
    );
  }

}
