<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * The "Approve AI Change" comment action.
 *
 * Anyone may approve a pending AI change, including the
 * revision author. The transaction's new value is the approved diff's ID.
 */
final class DifferentialRevisionAIChangeApproveTransaction
  extends DifferentialRevisionActionTransaction {

  const TRANSACTIONTYPE = 'mozilla.ai-change.approve';
  const ACTIONKEY = 'ai-change.approve';

  protected function getRevisionActionLabel(
    DifferentialRevision $revision,
    PhabricatorUser $viewer) {
    return pht('Approve AI Change');
  }

  protected function getRevisionActionDescription(
    DifferentialRevision $revision,
    PhabricatorUser $viewer) {
    return pht(
      'You have checked the AI change in this diff. '.
      'This is separate from accepting the revision.');
  }

  protected function getRevisionActionGroupKey() {
    // Approving doesn't require being able to edit the revision.
    return DifferentialRevisionEditEngine::ACTIONGROUP_REVIEW;
  }

  protected function getRevisionActionOrder() {
    return 400;
  }

  public function newEditField(
    DifferentialRevision $revision,
    PhabricatorUser $viewer) {

    // An approval is not a review action, so let it be combined with
    // "Accept Revision" or "Request Changes" in one submission.
    return parent::newEditField($revision, $viewer)
      ->setActionConflictKey('ai-change.approve');
  }

  public function getIcon() {
    return 'fa-magic';
  }

  public function getColor() {
    return 'green';
  }

  public function getActionName() {
    return pht('Approved AI Change');
  }

  public function generateOldValue($object) {
    return null;
  }

  public function generateNewValue($object, $value) {
    return DifferentialAIChange::newFromRevision($object)
      ->getChangeDiffID();
  }

  public function applyInternalEffects($object, $value) {
    DifferentialAIChange::newFromRevision($object)
      ->writeApproval($this->getActingAsPHID());
  }

  protected function validateAction($object, PhabricatorUser $viewer) {
    if (!$viewer->isLoggedIn()) {
      throw new Exception(
        pht('You must log in to approve an AI change.'));
    }

    $status = DifferentialAIChange::newFromRevision($object)->getStatus();
    switch ($status) {
      case DifferentialAIChange::STATUS_PENDING:
        return;
      case DifferentialAIChange::STATUS_APPROVED:
        throw new Exception(
          pht(
            'The AI change in this revision has already '.
            'been approved.'));
      case DifferentialAIChange::STATUS_LAPSED:
        throw new Exception(
          pht(
            'This revision has been updated since the AI change, so it no '.
            'longer needs an approval.'));
      default:
        throw new Exception(
          pht(
            'This revision has no AI change to approve.'));
    }
  }

  public function getTitle() {
    return pht(
      '%s approved the AI change in diff %s.',
      $this->renderAuthor(),
      $this->renderValue($this->getNewValue()));
  }

  public function getTitleForFeed() {
    return pht(
      '%s approved the AI change in %s.',
      $this->renderAuthor(),
      $this->renderObject());
  }

  public function getTransactionTypeForConduit($xaction) {
    return 'ai-change.approve';
  }

  public function getFieldValuesForConduit($xaction, $data) {
    return array(
      'diffID' => $xaction->getNewValue(),
    );
  }

}
