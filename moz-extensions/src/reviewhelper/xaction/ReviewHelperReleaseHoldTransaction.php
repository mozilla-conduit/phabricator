<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * Publishes a revision that was held in draft for AI review, and records why.
 *
 * Phabricator applies this itself, so it isn't exposed through Conduit. If the
 * revision is no longer held (for example, the author already published it)
 * the transaction has no effect.
 */
final class ReviewHelperReleaseHoldTransaction
  extends DifferentialRevisionTransactionType {

  const TRANSACTIONTYPE = 'reviewhelper.hold.release';

  const REASON_NO_FINDINGS = 'no-findings';
  const REASON_AUTHOR_UPDATED = 'author-updated';
  const REASON_ALREADY_REVIEWED = 'already-reviewed';
  const REASON_SERVICE_ERROR = 'service-error';
  const REASON_TIMEOUT = 'timeout';
  // Someone used "Request Review" before the hold was released. These are
  // recorded on the "Request Review" transaction rather than on this one.
  const REASON_SKIPPED_REVIEW = 'skipped-review';
  const REASON_SKIPPED_FINDINGS = 'skipped-findings';

  const METADATA_ERROR = 'reviewhelper.error';

  public static function newTransaction(
    string $reason,
    ?string $error = null): DifferentialTransaction {

    $xaction = id(new DifferentialTransaction())
      ->setTransactionType(self::TRANSACTIONTYPE)
      ->setNewValue($reason);

    if ($error !== null) {
      $xaction->setMetadataValue(self::METADATA_ERROR, $error);
    }

    return $xaction;
  }

  public function generateOldValue($object) {
    return null;
  }

  public function generateNewValue($object, $value) {
    // Leave the old and new values equal so the transaction is dropped as
    // having no effect.
    if (!ReviewHelperDraftHold::isHeld($object)) {
      return null;
    }

    return $value;
  }

  public function applyInternalEffects($object, $value) {
    $object
      ->setModernRevisionStatus(DifferentialRevisionStatus::NEEDS_REVIEW)
      ->setShouldBroadcast(true);

    ReviewHelperDraftHold::releaseHold($object, $value);
  }

  public function getRequiredCapabilities(
    $object,
    PhabricatorApplicationTransaction $xaction) {

    // Review Helper's bot account releases the hold when it posts a review with
    // no findings, and it may not be able to edit the revision.
    return null;
  }

  public function getColor() {
    return 'sky';
  }

  public function getIcon() {
    return 'fa-magic';
  }

  public function getActionName() {
    return pht('Published');
  }

  public function getTitle() {
    return self::renderTitle(
      $this->getNewValue(),
      $this->renderAuthor(),
      $this->getMetadataValue(self::METADATA_ERROR));
  }

  /**
   * Renders the timeline story for a hold released for `$reason`. Shared with
   * "Request Review", which records the reason when it ends a hold early.
   */
  public static function renderTitle(
    ?string $reason,
    $author,
    ?string $error = null) {

    switch ($reason) {
      case self::REASON_NO_FINDINGS:
        return pht(
          '%s published this revision for review because Review Helper '.
          'found no issues.',
          $author);
      case self::REASON_AUTHOR_UPDATED:
        return pht(
          '%s published this revision for review by updating it after '.
          'Review Helper\'s review.',
          $author);
      case self::REASON_ALREADY_REVIEWED:
        return pht(
          '%s published this revision for review because Review Helper had '.
          'already reviewed it.',
          $author);
      case self::REASON_SERVICE_ERROR:
        return pht(
          '%s published this revision for review because Review Helper could '.
          'not review it: %s',
          $author,
          $error);
      case self::REASON_TIMEOUT:
        return pht(
          '%s published this revision for review because Review Helper did '.
          'not finish reviewing it in time.',
          $author);
      case self::REASON_SKIPPED_REVIEW:
        return pht(
          '%s published this revision for review before Review Helper '.
          'finished reviewing it.',
          $author);
      case self::REASON_SKIPPED_FINDINGS:
        return pht(
          '%s published this revision for review without updating it after '.
          'Review Helper\'s review.',
          $author);
      default:
        return pht(
          '%s published this revision for review.',
          $author);
    }
  }

  public function getTitleForFeed() {
    return pht(
      '%s published %s for review.',
      $this->renderAuthor(),
      $this->renderObject());
  }

}
