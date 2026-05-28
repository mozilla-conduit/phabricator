<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * Read-only field holding whether a revision's active diff merges cleanly into
 * its target branch. The value is computed by `RevisionMergeConflictWorker` and
 * written directly to storage (never via a transaction), then exposed in the
 * revision "Details" section and over Conduit so Lando can surface its own
 * warning.
 */
final class DifferentialMergeConflictStatusField
  extends DifferentialStoredCustomField {

  const STATUS_CLEAN = 'clean';
  const STATUS_CONFLICT = 'conflict';
  const STATUS_UNKNOWN = 'unknown';

  // Keys used in the stored JSON payload.
  const KEY_STATUS = 'status';
  const KEY_REASON = 'reason';
  const KEY_TARGET_COMMIT = 'checkedAgainstCommit';
  const KEY_DIFF_PHID = 'checkedAgainstDiffPHID';
  const KEY_DIFF_ID = 'checkedAgainstDiffID';
  const KEY_EPOCH = 'epoch';

/* -(  Core Properties and Field Identity  )--------------------------------- */

  public function getFieldKey() {
    return 'differential:merge-conflict-status';
  }

  public function getFieldKeyForConduit() {
    return 'merge.conflict.status';
  }

  public function getFieldName() {
    return pht('Merge Conflict Status');
  }

  public function getFieldDescription() {
    return pht(
      'Indicates whether the active diff merges cleanly into the target '.
      'branch.');
  }

  public function isFieldEnabled() {
    return true;
  }

  public function canDisableField() {
    // The field is managed automatically, so don't allow it to be switched off.
    return false;
  }

/* -(  Read-only: not user-editable  )--------------------------------------- */

  public function isFieldEditable() {
    return false;
  }

  public function shouldAppearInApplicationTransactions() {
    return false;
  }

  public function shouldAppearInEditView() {
    return false;
  }

  public function renderEditControl(array $handles) {
    return null;
  }

  public function newCommentAction() {
    return null;
  }

/* -(  Storage  )------------------------------------------------------------ */

  public function getValueForStorage() {
    return phutil_json_encode($this->getValue());
  }

  public function setValueFromStorage($value) {
    try {
      $this->setValue(phutil_json_decode($value));
    } catch (PhutilJSONParserException $ex) {
      $this->setValue(array());
    }
    return $this;
  }

  /**
   * Persist a computed status for a revision directly to field storage,
   * bypassing the transaction editor so routine rechecks don't generate feed
   * stories, mail, or Herald evaluation (which would also risk re-triggering
   * the recompute). Mirrors the upsert in
   * `PhabricatorCustomField::applyApplicationTransactionExternalEffects()`.
   */
  public function writeStatusForObject($object_phid, array $value) {
    $table = $this->newStorageObject();
    $conn_w = $table->establishConnection('w');

    queryfx(
      $conn_w,
      'INSERT INTO %T (objectPHID, fieldIndex, fieldValue)
        VALUES (%s, %s, %s)
        ON DUPLICATE KEY UPDATE fieldValue = VALUES(fieldValue)',
      $table->getTableName(),
      $object_phid,
      $this->getFieldIndex(),
      phutil_json_encode($value));

    return $this;
  }

/* -(  Property View  )------------------------------------------------------ */

  public function shouldAppearInPropertyView() {
    return true;
  }

  public function renderPropertyViewValue(array $handles) {
    $value = $this->getValue();
    if (!is_array($value) || empty($value[self::KEY_STATUS])) {
      return null;
    }

    $status = $value[self::KEY_STATUS];

    if ($this->isStatusStale($value)) {
      return phutil_tag(
        'em',
        array(),
        pht('Recomputing for the latest diff…'));
    }

    switch ($status) {
      case self::STATUS_CONFLICT:
        return phutil_tag(
          'strong',
          array('style' => 'color: #c0392b;'),
          pht('Does not merge cleanly into the target branch.'));
      case self::STATUS_CLEAN:
        return phutil_tag(
          'span',
          array('style' => 'color: #27ae60;'),
          pht('Merges cleanly into the target branch.'));
      case self::STATUS_UNKNOWN:
      default:
        return phutil_tag(
          'span',
          array(),
          pht('Mergeability could not be determined.'));
    }
  }

/* -(  Conduit  )------------------------------------------------------------ */

  public function shouldAppearInConduitDictionary() {
    return true;
  }

  public function getConduitDictionaryValue() {
    $value = $this->getValue();
    if (!is_array($value)) {
      return null;
    }
    return $value;
  }

/* -(  Helpers  )------------------------------------------------------------ */

  /**
   * A stored status is stale if it was computed against a diff other than the
   * revision's current active diff. We suppress display in that case because a
   * fresh check is already queued.
   */
  private function isStatusStale(array $value) {
    $checked_phid = idx($value, self::KEY_DIFF_PHID);
    if (!$checked_phid) {
      return false;
    }

    $object = $this->getObject();
    if (!($object instanceof DifferentialRevision)) {
      return false;
    }

    $active_diff = $object->getActiveDiff();
    if (!$active_diff) {
      return false;
    }

    return ($active_diff->getPHID() !== $checked_phid);
  }

}
