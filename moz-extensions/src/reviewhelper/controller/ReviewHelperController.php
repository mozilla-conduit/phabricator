<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

abstract class ReviewHelperController extends PhabricatorController {

  /**
   * Make a request to the Review Helper service.
   *
   * @param string $endpoint The endpoint path (e.g., '/request' or '/feedback')
   * @param array $payload The request payload
   * @return array The parsed JSON response
   * @throws ReviewHelperServiceException
   */
  protected function makeServiceRequest($endpoint, array $payload) {
    $service_url = PhabricatorEnv::getEnvConfig('reviewhelper.url');
    $auth_key = PhabricatorEnv::getEnvConfig('reviewhelper.auth-key');
    $timeout = PhabricatorEnv::getEnvConfig('reviewhelper.timeout');

    if (!$service_url) {
      throw new ReviewHelperServiceException(
        pht('Review Helper service is not configured.')
      );
    }

    $url = rtrim($service_url, '/') . $endpoint;

    $future = id(new HTTPSFuture($url))
      ->setMethod('POST')
      ->addHeader('Content-Type', 'application/json')
      ->addHeader('Authorization', 'Bearer ' . $auth_key)
      ->addHeader('User-Agent', 'Phabricator')
      ->addHeader('Origin', rtrim(PhabricatorEnv::getAnyBaseURI(), '/'))
      ->setTimeout($timeout)
      ->setData(phutil_json_encode($payload));

    try {
      list($status, $body) = $future->resolve();
    } catch (HTTPFutureResponseStatus $ex) {
      throw new ReviewHelperServiceException(
        pht(
          'Review Helper encountered a connection error (%s).',
          $ex->getStatusCode()
        )
      );
    }

    if ($status->isTimeout()) {
      throw new ReviewHelperServiceException(
        pht('Review Helper request timed out. Please try again later.')
      );
    }

    // On 422 errors, Review Helper will return user friendly error messages
    if ($status->getStatusCode() == 422) {
      try {
        $data = phutil_json_decode($body);
        if (is_array($data) && array_key_exists('error_message', $data)) {
          throw new ReviewHelperServiceException($data['error_message']);
        }
      } catch (PhutilJSONParserException $ex) {
        throw new ReviewHelperServiceException(
          pht('Review Helper cannot process the request (422).')
        );
      }
    }

    if ($status->isError()) {
      throw new ReviewHelperServiceException(
        pht(
          'Review Helper returned an HTTP error (%s).',
          $status->getStatusCode()
        )
      );
    }

    try {
      $data = phutil_json_decode($body);
    } catch (PhutilJSONParserException $ex) {
      throw new ReviewHelperServiceException(
        pht('Review Helper returned malformed JSON.')
      );
    }

    if (!is_array($data)) {
      throw new ReviewHelperServiceException(
        pht('Review Helper returned an unexpected response.')
      );
    }

    return $data;
  }
}
