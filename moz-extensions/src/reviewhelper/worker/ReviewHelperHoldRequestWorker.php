<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * Asks Review Helper to review a revision that was just held in draft.
 *
 * The request is made on the author's behalf, since their submission triggered
 * it. If Review Helper rejects the request or can't be reached, the revision is
 * published right away rather than waiting for the timeout.
 *
 * Task data: `revisionPHID` (required).
 */
final class ReviewHelperHoldRequestWorker extends PhabricatorWorker {

  public function getMaximumRetryCount() {
    // `ReviewHelperHoldSweepWorker` publishes the revision if every attempt
    // fails, so a short retry budget is enough.
    return 2;
  }

  public static function queueRequest(DifferentialRevision $revision): void {
    self::scheduleTask(
      __CLASS__,
      array('revisionPHID' => $revision->getPHID()),
      array('objectPHID' => $revision->getPHID()));
  }

  protected function doWork() {
    $viewer = PhabricatorUser::getOmnipotentUser();

    $revision = id(new DifferentialRevisionQuery())
      ->setViewer($viewer)
      ->withPHIDs(array($this->getTaskDataValue('revisionPHID')))
      ->needDiffIDs(true)
      ->needReviewers(true)
      ->executeOne();
    if (!$revision) {
      return;
    }

    if (!ReviewHelperDraftHold::isWaitingForReview($revision)) {
      return;
    }

    $author = id(new PhabricatorPeopleQuery())
      ->setViewer($viewer)
      ->withPHIDs(array($revision->getAuthorPHID()))
      ->executeOne();
    if (!$author) {
      ReviewHelperDraftHold::publishHeldRevision(
        $revision,
        ReviewHelperReleaseHoldTransaction::REASON_SERVICE_ERROR,
        pht('The revision author could not be loaded.'));
      return;
    }

    try {
      $data = ReviewHelperService::requestReview($author, $revision);
    } catch (ReviewHelperServiceException $ex) {
      ReviewHelperDraftHold::publishHeldRevision(
        $revision,
        ReviewHelperReleaseHoldTransaction::REASON_SERVICE_ERROR,
        $ex->getMessage());
      return;
    }

    MozLogger::log(
      'Requested AI review for revision held in draft',
      'reviewhelper.draft-hold.request',
      array('Fields' => array(
        'revision_id' => $revision->getID(),
        'status' => idx($data, 'status'),
      )));

    // Review Helper answers a repeated request with the earlier request's
    // status. A review that already failed or was already posted will never
    // post again, so waiting for it would only delay the revision.
    switch (idx($data, 'status')) {
      case 'failed':
        ReviewHelperDraftHold::publishHeldRevision(
          $revision,
          ReviewHelperReleaseHoldTransaction::REASON_SERVICE_ERROR,
          $data['message']);
        break;
      case 'published':
        ReviewHelperDraftHold::publishHeldRevision(
          $revision,
          ReviewHelperReleaseHoldTransaction::REASON_ALREADY_REVIEWED);
        break;
    }
  }

}
