<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * The "Approve AI Change" field, whose value is the ID of the diff approved.
 *
 * The comment form offers one checkbox, keyed by the pending diff, so a stale
 * page can't approve a newer AI change than the one the reviewer saw.
 */
final class DifferentialAIChangeApproveEditField
  extends PhabricatorEditField {

  private $diffID;
  private $actionConflictKey;

  public function setDiffID($diff_id) {
    $this->diffID = $diff_id;
    return $this;
  }

  public function getDiffID() {
    return $this->diffID;
  }

  public function setActionConflictKey($action_conflict_key) {
    $this->actionConflictKey = $action_conflict_key;
    return $this;
  }

  public function getActionConflictKey() {
    return $this->actionConflictKey;
  }

  protected function newControl() {
    return null;
  }

  protected function newHTTPParameterType() {
    return new AphrontIntHTTPParameterType();
  }

  protected function newConduitParameterType() {
    return new ConduitIntParameterType();
  }

  public function shouldGenerateTransactionsFromSubmit() {
    // Like `PhabricatorApplyEditField`, this is only a comment action.
    return false;
  }

  protected function getValueFromComment($value) {
    // The comment form submits the ticked checkboxes' keys as a list of
    // strings, so a ticked box becomes the diff ID and an unticked one `null`.
    if (is_array($value)) {
      $value = head($value);
      if ($value === false) {
        return null;
      }
    }

    if (is_string($value) && ctype_digit($value)) {
      return (int)$value;
    }

    return $value;
  }

  protected function getValueForCommentAction($value) {
    // Start unticked, so approving takes a deliberate click.
    return array();
  }

  protected function newCommentAction() {
    $diff_id = $this->getDiffID();

    return id(new PhabricatorEditEngineCheckboxesCommentAction())
      ->setOptions(array(
        $diff_id => pht('I checked the AI change in Diff %d', $diff_id),
      ))
      ->setConflictKey($this->getActionConflictKey());
  }

}
