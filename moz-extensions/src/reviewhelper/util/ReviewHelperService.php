<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

final class ReviewHelperService extends Phobject {

  /**
   * Maximum age (in seconds) of a revision for it to be considered as
   * having pending visibility. Revisions older than this are assumed to
   * have already been processed by phab-bot.
   */
  const PENDING_VISIBILITY_WINDOW = 300; // 5 minutes

  /**
   * Determine the acting capacity of a user for a revision.
   *
   * @param PhabricatorUser $viewer
   * @param DifferentialRevision $revision Must have reviewers loaded.
   * @return string|null
   */
  public static function determineActingCapacity(
    PhabricatorUser $viewer,
    DifferentialRevision $revision
  ) {
    if ($viewer->getPHID() === $revision->getAuthorPHID()) {
      return 'author';
    }

    foreach ($revision->getReviewers() as $reviewer) {
      if ($reviewer->getReviewerPHID() === $viewer->getPHID()) {
        return 'reviewer:' . $reviewer->getReviewerStatus();
      }
    }

    $subscribers = id(new PhabricatorSubscribersQuery())
      ->withObjectPHIDs(array($revision->getPHID()))
      ->withSubscriberPHIDs(array($viewer->getPHID()))
      ->execute();

    if (!empty($subscribers[$revision->getPHID()])) {
      return 'participant';
    }

    return null;
  }

  /**
   * Make a request to the Review Helper service.
   *
   * @param string $endpoint The endpoint path (e.g., '/request' or '/feedback')
   * @param array $payload The request payload
   * @return array The parsed JSON response
   * @throws ReviewHelperServiceException
   */
  public static function makeServiceRequest($endpoint, array $payload) {
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
        // Fall through to generic error below
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

  /**
   * Request an AI review for a revision.
   *
   * @param PhabricatorUser $viewer
   * @param DifferentialRevision $revision Must have diff IDs and reviewers loaded.
   * @return array The parsed service response (contains 'message' key)
   * @throws ReviewHelperServiceException
   */
  public static function requestReview(
    PhabricatorUser $viewer,
    DifferentialRevision $revision
  ) {
    $allow_pending_visibility = ($viewer->getPHID() === $revision->getAuthorPHID());
    if (!self::isEligibleForReview($revision, $allow_pending_visibility)) {
      throw new ReviewHelperIneligibleRevisionException(
        pht('This revision is not eligible for AI review.')
      );
    }

    $acting_capacity = self::determineActingCapacity($viewer, $revision);

    $payload = array(
      'revision_id' => $revision->getID(),
      'diff_id' => max($revision->getDiffIDs()),
      'user_id' => $viewer->getID(),
      'user_name' => $viewer->getUsername(),
      'acting_capacity' => $acting_capacity,
      'revision_created_at' => date('c', $revision->getDateCreated()),
    );

    $data = self::makeServiceRequest('/request', $payload);

    if (!array_key_exists('message', $data)) {
      throw new ReviewHelperServiceException(
        pht('The AI review service returned an unexpected or malformed response.')
      );
    }

    return $data;
  }

  /**
   * Check if a revision is eligible for AI review.
   *
   * @param DifferentialRevision $revision When $allow_pending_visibility is
   *   true, must have diff IDs attached (loaded with needDiffIDs(true)).
   * @param bool $allow_pending_visibility Allow new private revisions through.
   * @return bool
   */
  public static function isEligibleForReview(
    DifferentialRevision $revision,
    $allow_pending_visibility = false
  ) {
    $allow_private = PhabricatorEnv::getEnvConfig('reviewhelper.allow-private-revisions');
    if (!$allow_private) {
      $view_policy = $revision->getViewPolicy();
      $is_private = !in_array($view_policy, array(
        PhabricatorPolicies::POLICY_PUBLIC,
        PhabricatorPolicies::POLICY_USER,
      ));
      if ($is_private) {
        // Allow new private revisions through since phab-bot may not
        // have finalized visibility yet.
        if (
          !$allow_pending_visibility
          || !self::hasPotentialPendingVisibility($revision)
        ) {
          return false;
        }
      }
    }

    $allowed_repos = PhabricatorEnv::getEnvConfig('reviewhelper.repository-phids');
    if ($allowed_repos && !in_array($revision->getRepositoryPHID(), $allowed_repos, true)) {
      return false;
    }

    return true;
  }

  /**
   * Check if a private revision's visibility may not yet be finalized.
   *
   * Returns true for new revisions (first diff, created recently) where
   * phab-bot likely hasn't processed the visibility yet.
   *
   * @param DifferentialRevision $revision Must have diff IDs attached
   *   (loaded with needDiffIDs(true)).
   * @return bool
   */
  public static function hasPotentialPendingVisibility(
    DifferentialRevision $revision
  ) {
    $diff_ids = $revision->getDiffIDs();
    $is_first_diff = (count($diff_ids) === 1);
    if (!$is_first_diff) {
      return false;
    }

    $age = PhabricatorTime::getNow() - $revision->getDateCreated();
    $is_recent = $age < self::PENDING_VISIBILITY_WINDOW;
    if (!$is_recent) {
      return false;
    }

    return true;
  }
}
