<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * Tracks revisions held in draft while Review Helper reviews them.
 *
 * A new revision in an allow-listed repository stays in draft until the author
 * publishes it, Review Helper finds nothing, or the review fails or times out.
 * The hold always fails open: anything unexpected publishes the revision.
 *
 * The hold state is stored as a revision property, and a revision is only held
 * while it is also still an unbroadcast draft, so publishing it by any route
 * (including "Request Review") ends the hold.
 */
final class ReviewHelperDraftHold extends Phobject {

  const PROPERTY_HOLD = 'reviewhelper.draft-hold';

  // Waiting for Review Helper to post its review.
  const STATUS_REVIEWING = 'reviewing';
  // Review Helper posted findings, and the author has not responded yet.
  const STATUS_FINDINGS = 'findings';
  // The hold ended and the revision was published.
  const STATUS_RELEASED = 'released';

/* -(  Eligibility  )-------------------------------------------------------- */

  /**
   * Whether a new revision should be held in draft for AI review.
   *
   * Call this after the revision's view policy has been set, since private
   * revisions are never held.
   */
  public static function shouldHoldRevision(
    DifferentialRevision $revision): bool {

    if (!self::isConfigured()) {
      return false;
    }

    if (!$revision->isDraft() || $revision->getShouldBroadcast()) {
      return false;
    }

    if ($revision->getHoldAsDraft()) {
      return false;
    }

    $allowed_phids = PhabricatorEnv::getEnvConfig(
      'reviewhelper.draft-hold.repository-phids');
    if (!$allowed_phids) {
      return false;
    }

    if (!in_array($revision->getRepositoryPHID(), $allowed_phids, true)) {
      return false;
    }

    return ReviewHelperService::isEligibleForReview($revision);
  }

  /**
   * Whether the hold is switched on and Review Helper is set up well enough to
   * release it. Without a bot username Phabricator can't tell when Review
   * Helper has finished, so every held revision would wait for the timeout.
   */
  public static function isConfigured(): bool {
    if (!PhabricatorEnv::getEnvConfig('reviewhelper.draft-hold.enabled')) {
      return false;
    }

    if (!PhabricatorEnv::getEnvConfig('reviewhelper.url')) {
      return false;
    }

    if (!PhabricatorEnv::getEnvConfig('reviewhelper.bot-username')) {
      return false;
    }

    return true;
  }

/* -(  Hold State  )--------------------------------------------------------- */

  /**
   * The hold's status, or `null` if the revision is not currently held.
   */
  public static function getHoldStatus(
    DifferentialRevision $revision): ?string {

    if (!$revision->isDraft() || $revision->getShouldBroadcast()) {
      return null;
    }

    $hold = $revision->getProperty(self::PROPERTY_HOLD);
    if (!is_array($hold)) {
      return null;
    }

    $status = idx($hold, 'status');
    $held_statuses = array(self::STATUS_REVIEWING, self::STATUS_FINDINGS);
    if (in_array($status, $held_statuses, true)) {
      return $status;
    }

    return null;
  }

  public static function isHeld(DifferentialRevision $revision): bool {
    return self::getHoldStatus($revision) !== null;
  }

  /**
   * Whether the revision was ever held, including holds that have ended.
   */
  public static function wasEverHeld(DifferentialRevision $revision): bool {
    return self::getHeldAt($revision) !== null;
  }

  public static function isWaitingForReview(
    DifferentialRevision $revision): bool {
    return self::getHoldStatus($revision) === self::STATUS_REVIEWING;
  }

  public static function hasFindings(DifferentialRevision $revision): bool {
    return self::getHoldStatus($revision) === self::STATUS_FINDINGS;
  }

  public static function getHeldAt(DifferentialRevision $revision): ?int {
    $hold = $revision->getProperty(self::PROPERTY_HOLD);
    if (!is_array($hold)) {
      return null;
    }

    return idx($hold, 'heldAt');
  }

  public static function startHold(DifferentialRevision $revision): void {
    $revision->setProperty(
      self::PROPERTY_HOLD,
      array(
        'status' => self::STATUS_REVIEWING,
        'heldAt' => PhabricatorTime::getNow(),
      ));
  }

  public static function recordFindings(DifferentialRevision $revision): void {
    self::updateHold($revision, array('status' => self::STATUS_FINDINGS));
  }

  public static function releaseHold(
    DifferentialRevision $revision,
    string $reason): void {

    self::updateHold(
      $revision,
      array(
        'status' => self::STATUS_RELEASED,
        'reason' => $reason,
        'releasedAt' => PhabricatorTime::getNow(),
      ));
  }

/* -(  Responding to Edits  )------------------------------------------------ */

  /**
   * Builds the transactions that move a held revision along in response to an
   * edit, for the editor to add to that edit.
   *
   * When Review Helper posts its review, the revision is published if there
   * are no inline findings, and otherwise waits on the author. Once it is
   * waiting, the author updating the diff publishes it.
   */
  public static function newTransactionsForEdit(
    PhabricatorUser $actor,
    DifferentialRevision $revision,
    array $xactions): array {

    if (self::isWaitingForReview($revision) && self::isBotUser($actor)) {
      return self::newTransactionsForReview($xactions);
    }

    $is_author = ($actor->getPHID() === $revision->getAuthorPHID());
    if (self::hasFindings($revision) && $is_author) {
      return self::newTransactionsForAuthorEdit($xactions);
    }

    return array();
  }

  private static function newTransactionsForReview(array $xactions): array {
    $types = mpull($xactions, 'getTransactionType');

    $finding_count = count(
      array_keys($types, DifferentialTransaction::TYPE_INLINE));
    if ($finding_count) {
      return array(
        ReviewHelperFindingsTransaction::newTransaction($finding_count),
      );
    }

    if (in_array(PhabricatorTransactions::TYPE_COMMENT, $types, true)) {
      return array(
        ReviewHelperReleaseHoldTransaction::newTransaction(
          ReviewHelperReleaseHoldTransaction::REASON_NO_FINDINGS),
      );
    }

    return array();
  }

  private static function newTransactionsForAuthorEdit(array $xactions): array {
    $types = mpull($xactions, 'getTransactionType');

    // A "Request Review" in the same edit, which `moz-phab` sends when it
    // updates a draft, already publishes the revision.
    $requests_review = in_array(
      DifferentialRevisionRequestReviewTransaction::TRANSACTIONTYPE,
      $types,
      true);
    $updates_diff = in_array(
      DifferentialRevisionUpdateTransaction::TRANSACTIONTYPE,
      $types,
      true);

    if ($updates_diff && !$requests_review) {
      return array(
        ReviewHelperReleaseHoldTransaction::newTransaction(
          ReviewHelperReleaseHoldTransaction::REASON_AUTHOR_UPDATED),
      );
    }

    return array();
  }

  private static function isBotUser(PhabricatorUser $user): bool {
    $bot_username = PhabricatorEnv::getEnvConfig('reviewhelper.bot-username');
    if (!$bot_username) {
      return false;
    }

    return $user->getUsername() === $bot_username;
  }

/* -(  Publishing  )--------------------------------------------------------- */

  /**
   * Publishes a held revision on behalf of the Review Helper application. Has
   * no effect if the revision is no longer held.
   */
  public static function publishHeldRevision(
    DifferentialRevision $revision,
    string $reason,
    ?string $error = null): void {

    MozLogger::log(
      'Publishing revision held for AI review',
      'reviewhelper.draft-hold.publish',
      array('Fields' => array(
        'revision_id' => $revision->getID(),
        'reason' => $reason,
        'error' => $error,
      )));

    $xactions = array(
      ReviewHelperReleaseHoldTransaction::newTransaction($reason, $error),
    );

    $content_source = PhabricatorContentSource::newForSource(
      PhabricatorDaemonContentSource::SOURCECONST);

    id(new DifferentialTransactionEditor())
      ->setActor(PhabricatorUser::getOmnipotentUser())
      ->setActingAsPHID(id(new PhabricatorReviewHelperApplication())->getPHID())
      ->setContentSource($content_source)
      ->setContinueOnNoEffect(true)
      ->setContinueOnMissingFields(true)
      ->applyTransactions($revision, $xactions);
  }

  private static function updateHold(
    DifferentialRevision $revision,
    array $changes): void {

    $hold = $revision->getProperty(self::PROPERTY_HOLD);
    if (!is_array($hold)) {
      $hold = array();
    }

    $revision->setProperty(self::PROPERTY_HOLD, $changes + $hold);
  }

}
