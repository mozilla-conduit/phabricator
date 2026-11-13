<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * Requests approval of an AI change on a revision.
 *
 * Lando sends this over Conduit after uploading an AI's change as the
 * active diff. It replaces any earlier request and clears its approval.
 */
final class DifferentialRevisionAIChangeRequestTransaction
  extends DifferentialRevisionTransactionType {

  const TRANSACTIONTYPE = 'mozilla.ai-change.request';
  const EDITKEY = 'ai-change.request';

  public function generateOldValue($object) {
    return null;
  }

  public function generateNewValue($object, $value) {
    $base_diff_id = idx($value, DifferentialAIChange::KEY_BASE_DIFF_ID);

    return array(
      DifferentialAIChange::KEY_KIND =>
        $value[DifferentialAIChange::KEY_KIND],
      DifferentialAIChange::KEY_CHANGE_DIFF_ID =>
        (int)$value[DifferentialAIChange::KEY_CHANGE_DIFF_ID],
      DifferentialAIChange::KEY_BASE_DIFF_ID =>
        ($base_diff_id === null) ? null : (int)$base_diff_id,
      DifferentialAIChange::KEY_SUMMARY =>
        idx($value, DifferentialAIChange::KEY_SUMMARY),
      DifferentialAIChange::KEY_CONFIDENCE =>
        idx($value, DifferentialAIChange::KEY_CONFIDENCE),
      DifferentialAIChange::KEY_DETAILS =>
        idx($value, DifferentialAIChange::KEY_DETAILS),
    );
  }

  public function applyInternalEffects($object, $value) {
    DifferentialAIChange::newFromRevision($object)
      ->writeRequest($value, $this->getActingAsPHID());
  }

  public function validateTransactions($object, array $xactions) {
    $errors = array();

    foreach ($xactions as $xaction) {
      $error = $this->validateRequest($object, $xaction->getNewValue());
      if ($error !== null) {
        $errors[] = $this->newInvalidError($error, $xaction);
      }
    }

    return $errors;
  }

  /**
   * Return an error message for an invalid request, or `null` if it's valid.
   */
  private function validateRequest($object, $value) {
    if ($this->isNewObject()) {
      return pht(
        'An AI change can only be requested on an existing revision. '.
        'Create the revision first, then request the approval.');
    }

    if (!is_array($value)) {
      return pht('An AI change request must be a map.');
    }

    $known_keys = array(
      DifferentialAIChange::KEY_KIND,
      DifferentialAIChange::KEY_CHANGE_DIFF_ID,
      DifferentialAIChange::KEY_BASE_DIFF_ID,
      DifferentialAIChange::KEY_SUMMARY,
      DifferentialAIChange::KEY_CONFIDENCE,
      DifferentialAIChange::KEY_DETAILS,
    );
    $unknown_keys = array_diff(array_keys($value), $known_keys);
    if ($unknown_keys) {
      return pht(
        'An AI change request has unknown keys: %s. Valid keys are: %s.',
        implode(', ', $unknown_keys),
        implode(', ', $known_keys));
    }

    $kind = idx($value, DifferentialAIChange::KEY_KIND);
    $kinds = array_keys(DifferentialAIChange::getKindNames());
    if (!in_array($kind, $kinds, true)) {
      return pht(
        '"%s" must be one of: %s.',
        DifferentialAIChange::KEY_KIND,
        implode(', ', $kinds));
    }

    $text_keys = array(
      DifferentialAIChange::KEY_SUMMARY,
      DifferentialAIChange::KEY_CONFIDENCE,
      DifferentialAIChange::KEY_DETAILS,
    );
    foreach ($text_keys as $text_key) {
      $text = idx($value, $text_key);
      if ($text !== null && !is_string($text)) {
        return pht('"%s" must be a string.', $text_key);
      }
    }

    $change_diff_id = idx(
      $value,
      DifferentialAIChange::KEY_CHANGE_DIFF_ID);
    if (!$this->isDiffID($change_diff_id)) {
      return pht(
        '"%s" must be the ID of the diff holding the AI change.',
        DifferentialAIChange::KEY_CHANGE_DIFF_ID);
    }

    $active_diff = $this->loadDiff($object->getActiveDiffPHID(), null);
    if (!$active_diff || (int)$active_diff->getID() !== (int)$change_diff_id) {
      return pht(
        'Diff %s is not the active diff of this revision. Request the '.
        'approval after uploading the AI change, and before any other '.
        'update.',
        $change_diff_id);
    }

    $base_diff_id = idx($value, DifferentialAIChange::KEY_BASE_DIFF_ID);
    if ($base_diff_id === null) {
      return null;
    }

    if (!$this->isDiffID($base_diff_id)) {
      return pht(
        '"%s" must be the ID of the diff to compare the change with.',
        DifferentialAIChange::KEY_BASE_DIFF_ID);
    }

    $base_diff = $this->loadDiff(null, (int)$base_diff_id);
    $is_base_on_revision = $base_diff &&
      ((int)$base_diff->getRevisionID() === (int)$object->getID());
    if (!$is_base_on_revision) {
      return pht(
        'Diff %s does not belong to this revision.',
        $base_diff_id);
    }

    if ((int)$base_diff_id === (int)$change_diff_id) {
      return pht(
        '"%s" and "%s" must be different diffs.',
        DifferentialAIChange::KEY_BASE_DIFF_ID,
        DifferentialAIChange::KEY_CHANGE_DIFF_ID);
    }

    return null;
  }

  private function isDiffID($value) {
    if (is_int($value)) {
      return ($value > 0);
    }

    // Values from form-encoded Conduit calls arrive as strings.
    return (is_string($value) && ctype_digit($value) && (int)$value > 0);
  }

  private function loadDiff($phid, $id) {
    $query = id(new DifferentialDiffQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser());

    if ($phid !== null) {
      $query->withPHIDs(array($phid));
    } else {
      $query->withIDs(array($id));
    }

    return $query->executeOne();
  }

  public function getIcon() {
    return 'fa-magic';
  }

  public function getColor() {
    return 'orange';
  }

  public function getTitle() {
    return pht(
      '%s requested approval of an %s in diff %s.',
      $this->renderAuthor(),
      $this->getKindName(),
      $this->renderValue($this->getChangeDiffID()));
  }

  public function getTitleForFeed() {
    return pht(
      '%s requested approval of an %s in %s.',
      $this->renderAuthor(),
      $this->getKindName(),
      $this->renderObject());
  }

  private function getKindName() {
    return DifferentialAIChange::getKindName(
      idx($this->getNewValue(), DifferentialAIChange::KEY_KIND));
  }

  private function getChangeDiffID() {
    return idx(
      $this->getNewValue(),
      DifferentialAIChange::KEY_CHANGE_DIFF_ID);
  }

  public function getTransactionTypeForConduit($xaction) {
    return 'ai-change.request';
  }

  public function getFieldValuesForConduit($xaction, $data) {
    return $xaction->getNewValue();
  }

}
