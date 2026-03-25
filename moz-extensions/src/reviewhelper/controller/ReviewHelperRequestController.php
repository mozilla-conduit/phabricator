<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

final class ReviewHelperRequestController extends PhabricatorController {

  public function handleRequest(AphrontRequest $request) {
    $viewer = $request->getViewer();
    $revision_id = $request->getURIData('revisionID');

    $revision = id(new DifferentialRevisionQuery())
      ->setViewer($viewer)
      ->withIDs(array($revision_id))
      ->needDiffIDs(true)
      ->needReviewers(true)
      ->executeOne();

    if (!$revision) {
      return new Aphront404Response();
    }

    $revision_uri = '/D' . $revision->getID();

    $status = $revision->getStatusObject();
    if ($status->isClosedStatus() && !$request->isFormPost()) {
      $warning_box = id(new PHUIInfoView())
        ->setSeverity(PHUIInfoView::SEVERITY_WARNING)
        ->setTitle(pht('Revision is ') . $status->getDisplayName())
        ->appendChild(
          pht('This revision is closed. Requesting a review for a closed ' .
            'revision may not be what you intended.')
        );
      return $this->newDialog()
        ->setTitle(pht('Review Helper'))
        ->appendChild($warning_box)
        ->appendParagraph(pht('Are you sure you want to proceed?'))
        ->addSubmitButton(pht('Request Review'))
        ->addCancelButton($revision_uri);
    }

    try {
      $data = ReviewHelperService::requestReview($viewer, $revision);
    } catch (ReviewHelperServiceException $ex) {
      MozLogger::log(
        'Review Helper request failed',
        'reviewhelper.request.error',
        array('Fields' => array(
          'revision_id' => $revision_id,
          'error' => $ex->getMessage(),
        ))
      );
      return $this->newDialog()
        ->setTitle(pht('Review Helper'))
        ->setErrors(array($ex->getMessage()))
        ->addCancelButton($revision_uri, pht('Okay'));
    }

    return $this->newDialog()
      ->setTitle(pht('Review Helper'))
      ->appendParagraph($data['message'])
      ->addCancelButton($revision_uri, pht('Okay'));
  }
}
