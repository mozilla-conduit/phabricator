<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * Rejects an AI change request sent in the same edit as a diff update.
 *
 * A request is checked against the revision's active diff before the edit
 * applies, so an `update` in the same edit would replace that diff and leave
 * the request stored already lapsed, with no approval required. The request has
 * to come in its own edit, after the AI change's diff is uploaded.
 */
final class DifferentialAIChangeEditorExtension
  extends PhabricatorEditorExtension {

  const EXTENSIONKEY = 'mozilla.ai-change';

  public function getExtensionName() {
    return pht('AI Change Requests');
  }

  public function supportsObject(
    PhabricatorApplicationTransactionEditor $editor,
    PhabricatorApplicationTransactionInterface $object) {
    return ($object instanceof DifferentialRevision);
  }

  public function validateTransactions($object, array $xactions) {
    $request_type =
      DifferentialRevisionAIChangeRequestTransaction::TRANSACTIONTYPE;
    $update_type = DifferentialRevisionUpdateTransaction::TRANSACTIONTYPE;

    $requests = array();
    $has_update = false;
    foreach ($xactions as $xaction) {
      $type = $xaction->getTransactionType();
      if ($type === $request_type) {
        $requests[] = $xaction;
      } else if ($type === $update_type) {
        $has_update = true;
      }
    }

    if (!$has_update) {
      return array();
    }

    $errors = array();
    foreach ($requests as $request) {
      $errors[] = $this->newInvalidTransactionError(
        $request,
        pht(
          'An AI change approval can\'t be requested in the same edit as a '.
          'diff update. Upload the AI change\'s diff first, then request '.
          'the approval in its own edit.'));
    }

    return $errors;
  }

}
