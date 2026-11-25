<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * The "Approve AI Change" comment action.
 *
 * Anyone except a bot may approve a pending AI change, including the revision
 * author. The value is the ID of the diff being approved, which must be the
 * pending one.
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

    // The value is the approved diff's ID rather than `true`, so this can't
    // use the parent's `PhabricatorApplyEditField`.
    $diff_id = DifferentialAIChange::newFromRevision($revision)
      ->getChangeDiffID();

    $field = id(new DifferentialAIChangeApproveEditField())
      ->setKey($this->getRevisionActionKey())
      ->setTransactionType($this->getTransactionTypeConstant())
      ->setCanApplyWithoutEditCapability(true)
      ->setDiffID($diff_id)
      ->setValue($diff_id)
      ->setConduitDescription(
        pht('Approve the pending AI change in the given diff.'))
      ->setConduitTypeDescription(
        pht('ID of the diff holding the pending AI change.'));

    if ($this->isActionAvailable($revision, $viewer)) {
      // An approval is not a review action, so let it be combined with
      // "Accept Revision" or "Request Changes" in one submission.
      $field
        ->setCommentActionLabel(
          $this->getRevisionActionLabel($revision, $viewer))
        ->setCommentActionGroupKey($this->getRevisionActionGroupKey())
        ->setActionConflictKey('ai-change.approve');
    }

    return $field;
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
    return (int)$value;
  }

  public function applyInternalEffects($object, $value) {
    DifferentialAIChange::newFromRevision($object)
      ->writeApproval($this->getActingAsPHID());
  }

  public function validateTransactions($object, array $xactions) {
    $errors = parent::validateTransactions($object, $xactions);
    if ($errors) {
      return $errors;
    }

    $diff_id = DifferentialAIChange::newFromRevision($object)
      ->getChangeDiffID();
    foreach ($xactions as $xaction) {
      $value = $xaction->getNewValue();
      if ($value === null) {
        $errors[] = $this->newInvalidError(
          pht(
            'Tick "I checked the AI change in Diff %s" to approve it.',
            $diff_id),
          $xaction);
        continue;
      }

      if (!is_int($value) || $value !== (int)$diff_id) {
        $errors[] = $this->newInvalidError(
          pht(
            'The pending AI change is in diff %s. Reload the revision and '.
            'check that change before approving it.',
            $diff_id),
          $xaction);
      }
    }

    return $errors;
  }

  protected function validateAction($object, PhabricatorUser $viewer) {
    if (!$viewer->isLoggedIn()) {
      throw new Exception(
        pht('You must log in to approve an AI change.'));
    }

    // An approval is the human check of a tool's change, so a bot like
    // Lando can't approve its own request.
    if ($viewer->getIsSystemAgent() || $viewer->getIsMailingList()) {
      throw new Exception(
        pht('Bots can not approve an AI change.'));
    }

    // Nothing can land a closed revision, so there's nothing to approve.
    if ($object->isClosed()) {
      throw new Exception(
        pht(
          'This revision is closed, so its AI change can no longer be '.
          'approved.'));
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
