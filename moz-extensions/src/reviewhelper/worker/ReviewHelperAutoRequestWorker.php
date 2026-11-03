<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * Asks Review Helper to review a revision without anyone pressing the button.
 *
 * The request is made on the author's behalf. Nothing waits on the review, so
 * if Review Helper rejects the request or can't be reached, the failure is
 * logged and the revision carries on as if no review had been requested.
 *
 * Task data: `revisionPHID` (required).
 */
final class ReviewHelperAutoRequestWorker extends PhabricatorWorker {

  public function getMaximumRetryCount() {
    // Service errors are logged rather than thrown, so only unexpected errors
    // are retried.
    return 2;
  }

  public static function queueRequest(DifferentialRevision $revision): void {
    self::scheduleTask(
      __CLASS__,
      array('revisionPHID' => $revision->getPHID()),
      array('objectPHID' => $revision->getPHID()));
  }

  /**
   * Whether a revision may be sent to Review Helper without anyone asking.
   *
   * `requestReview` lets authors through on new private revisions whose view
   * policy phab-bot may not have set yet. An automatic request must never send
   * a secure revision, so this ignores that exception.
   */
  public static function shouldRequestReview(
    DifferentialRevision $revision): bool {
    return ReviewHelperService::isEligibleForReview($revision);
  }

  protected function doWork() {
    $omnipotent_viewer = PhabricatorUser::getOmnipotentUser();
    $revision_phid = $this->getTaskDataValue('revisionPHID');

    $revision = id(new DifferentialRevisionQuery())
      ->setViewer($omnipotent_viewer)
      ->withPHIDs(array($revision_phid))
      ->needDiffIDs(true)
      ->needReviewers(true)
      ->executeOne();
    if (!$revision) {
      return;
    }

    $author = id(new PhabricatorPeopleQuery())
      ->setViewer($omnipotent_viewer)
      ->withPHIDs(array($revision->getAuthorPHID()))
      ->executeOne();
    if (!$author) {
      $this->logSkipped(
        $revision,
        pht('The revision author could not be loaded.'));
      return;
    }

    // Check again just before the request, since the view policy may have
    // changed since the request was queued. `requestReview` lets authors
    // through on new private revisions, so this stricter check must come first.
    if (!self::shouldRequestReview($revision)) {
      $this->logSkipped($revision, pht('The revision is no longer eligible.'));
      return;
    }

    try {
      $data = ReviewHelperService::requestReview($author, $revision);
    } catch (ReviewHelperServiceException $ex) {
      MozLogger::log(
        'Automatic AI review request failed',
        'reviewhelper.auto-request.error',
        array('Fields' => array(
          'revision_id' => $revision->getID(),
          'error' => $ex->getMessage(),
        )));
      return;
    }

    MozLogger::log(
      'Requested automatic AI review',
      'reviewhelper.auto-request',
      array('Fields' => array(
        'revision_id' => $revision->getID(),
        'status' => idx($data, 'status'),
      )));
  }

  private function logSkipped(
    DifferentialRevision $revision,
    string $reason): void {

    MozLogger::log(
      'Skipped automatic AI review request',
      'reviewhelper.auto-request.skipped',
      array('Fields' => array(
        'revision_id' => $revision->getID(),
        'reason' => $reason,
      )));
  }

}
