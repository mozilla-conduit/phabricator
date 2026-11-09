<?php

final class AphrontAjaxResponse extends AphrontResponse {

  private $content;
  private $error;

  public function setContent($content) {
    $this->content = $content;
    return $this;
  }

  public function setError($error) {
    $this->error = $error;
    return $this;
  }

  public function buildResponseString() {
    $request = $this->getRequest();

    // Flatten the response first, so we initialize any behaviors and metadata
    // we need to.
    $content = array(
      'payload' => $this->content,
    );
    $this->encodeJSONForHTTPResponse($content);

    $response = CelerityAPI::getStaticResourceResponse();

    if ($request) {
      $viewer = $request->getViewer();
      if ($viewer) {
        $postprocessor_key = $viewer->getUserSetting(
          PhabricatorAccessibilitySetting::SETTINGKEY);
        if (phutil_nonempty_string($postprocessor_key)) {
          $response->setPostprocessorKey($postprocessor_key);
        }
      }
    }

    $object = $response->buildAjaxResponse(
      $content['payload'],
      $this->error);

    $response_json = $this->encodeJSONForHTTPResponse($object);
    return $this->addJSONShield($response_json);
  }

  public function getHeaders() {
    $headers = array(
      array('Content-Type', 'text/plain; charset=UTF-8'),
    );
    $headers = array_merge(parent::getHeaders(), $headers);
    return $headers;
  }

}
