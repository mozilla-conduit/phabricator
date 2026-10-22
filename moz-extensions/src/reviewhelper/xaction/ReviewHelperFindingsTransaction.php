<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * Records that Review Helper posted findings on a revision held in draft, so
 * the revision now waits on its author instead of on Review Helper.
 *
 * Phabricator adds this to Review Helper's own comment edit, so it isn't
 * exposed through Conduit. The value is the number of inline findings.
 */
final class ReviewHelperFindingsTransaction
  extends DifferentialRevisionTransactionType {

  const TRANSACTIONTYPE = 'reviewhelper.hold.findings';

  public static function newTransaction(int $count): DifferentialTransaction {
    return id(new DifferentialTransaction())
      ->setTransactionType(self::TRANSACTIONTYPE)
      ->setNewValue($count);
  }

  public function generateOldValue($object) {
    return null;
  }

  public function generateNewValue($object, $value) {
    // Leave the old and new values equal so the transaction is dropped as
    // having no effect.
    if (!ReviewHelperDraftHold::isWaitingForReview($object)) {
      return null;
    }

    return (int)$value;
  }

  public function applyInternalEffects($object, $value) {
    ReviewHelperDraftHold::recordFindings($object);
  }

  public function getRequiredCapabilities(
    $object,
    PhabricatorApplicationTransaction $xaction) {

    // Review Helper's bot account may not be able to edit the revision.
    return null;
  }

  public function getColor() {
    return 'orange';
  }

  public function getIcon() {
    return 'fa-magic';
  }

  public function getActionName() {
    return pht('AI Review Findings');
  }

  public function getTitle() {
    return pht(
      'Review Helper found %s issue(s). This revision will stay in draft '.
      'until its author updates the diff or publishes it.',
      new PhutilNumber($this->getNewValue()));
  }

  public function getTitleForFeed() {
    return pht(
      'Review Helper found %s issue(s) on %s.',
      new PhutilNumber($this->getNewValue()),
      $this->renderObject());
  }

}
