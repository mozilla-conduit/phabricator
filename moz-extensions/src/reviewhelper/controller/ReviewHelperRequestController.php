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
          pht('This revision is not open. Requesting a review for a closed ' .
            'revision may not be what you intend.')
        );
      return $this->newDialog()
        ->setTitle(pht('Review Helper'))
        ->appendChild($warning_box)
        ->appendParagraph(pht('Are you sure you want to proceed?'))
        ->addSubmitButton(pht('Request Review'))
        ->addCancelButton($revision_uri);
    }

    try {
      $data = $this->requestReview($viewer, $revision);
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
        ->addCancelButton($revision_uri);
    }

    return $this->newDialog()
      ->setTitle(pht('Review Helper'))
      ->appendParagraph($data['message'])
      ->addCancelButton($revision_uri);
  }

  private function requestReview(
    PhabricatorUser $viewer,
    DifferentialRevision $revision
  ) {
    $service_url = PhabricatorEnv::getEnvConfig('reviewhelper.url');
    $auth_key = PhabricatorEnv::getEnvConfig('reviewhelper.auth-key');
    $timeout = PhabricatorEnv::getEnvConfig('reviewhelper.timeout');

    $payload = array(
      'revision_id' => $revision->getID(),
      'revision_phid' => $revision->getPHID(),
      'user_id' => $viewer->getID(),
      'user_name' => $viewer->getUsername(),
    );

    $future = id(new HTTPSFuture($service_url))
      ->setMethod('POST')
      ->addHeader('Content-Type', 'application/json')
      ->addHeader('Authorization', 'Bearer ' . $auth_key)
      ->addHeader('User-Agent', 'Phabricator (ReviewHelper)')
      ->addHeader('Origin', rtrim(PhabricatorEnv::getAnyBaseURI(), '/'))
      ->setTimeout($timeout)
      ->setData(phutil_json_encode($payload));

    try {
      list($status, $body) = $future->resolve();
    } catch (HTTPFutureResponseStatus $ex) {
      throw new ReviewHelperServiceException(
        pht('The AI review service encountered a connection or unexpected response error (%s).', $ex->getStatusCode())
      );
    }

    if ($status->isTimeout()) {
      throw new ReviewHelperServiceException(
        pht('The AI review service request timed out. Please try again later.')
      );
    }

    if ($status->isError()) {
      throw new ReviewHelperServiceException(
        pht('The AI review service returned an HTTP error response (%s).', $status->getStatusCode())
      );
    }

    try {
      $data = phutil_json_decode($body);
    } catch (PhutilJSONParserException $ex) {
      throw new ReviewHelperServiceException(
        pht('The AI review service returned malformed JSON.')
      );
    }

    if (!is_array($data) || !array_key_exists('message', $data)) {
      throw new ReviewHelperServiceException(
        pht('The AI review service returned an unexpected or malformed response.')
      );
    }

    return $data;
  }
}
