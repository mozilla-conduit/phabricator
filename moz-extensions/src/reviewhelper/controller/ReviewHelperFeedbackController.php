<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

final class ReviewHelperFeedbackController extends ReviewHelperController {

  public function handleRequest(AphrontRequest $request) {
    $viewer = $request->getViewer();

    if (!$request->isAjax() || !$request->hasCSRF()) {
      return new Aphront400Response();
    }

    $comment_id = $request->getStr('commentID');
    $feedback_type = $request->getStr('feedbackType');

    if (!in_array($feedback_type, array('up', 'down'))) {
      return id(new AphrontAjaxResponse())
        ->setError(pht('Invalid feedback type.'));
    }

    if (!$comment_id) {
      return id(new AphrontAjaxResponse())
        ->setError(pht('Missing comment ID.'));
    }

    try {
      $data = $this->submitFeedback($viewer, $comment_id, $feedback_type);
    } catch (ReviewHelperServiceException $ex) {
      MozLogger::log(
        'Review Helper feedback failed',
        'reviewhelper.feedback.error',
        array('Fields' => array(
          'comment_id' => $comment_id,
          'feedback_type' => $feedback_type,
          'user_phid' => $viewer->getPHID(),
          'error' => $ex->getMessage(),
        ))
      );
      return id(new AphrontAjaxResponse())
        ->setError($ex->getMessage());
    }

    return id(new AphrontAjaxResponse())
      ->setContent($data);
  }

  private function submitFeedback(
    PhabricatorUser $viewer,
    $comment_id,
    $feedback_type
  ) {
    $comment = id(new DifferentialDiffInlineCommentQuery())
      ->setViewer($viewer)
      ->withIDs(array($comment_id))
      ->executeOne();

    $revision = id(new DifferentialRevisionQuery())
      ->setViewer($viewer)
      ->withPHIDs(array($comment->getRevisionPHID()))
      ->needReviewers(true)
      ->executeOne();

    $acting_capacity = $this->determineActingCapacity($viewer, $revision);

    $payload = array(
      'comment_id' => $comment_id,
      'feedback_type' => $feedback_type,
      'user_id' => $viewer->getID(),
      'user_name' => $viewer->getUsername(),
      'acting_capacity' => $acting_capacity,
    );

    return $this->makeServiceRequest('/feedback', $payload);
  }
}
