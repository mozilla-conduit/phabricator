<?php

/*
 * Identical to feed.for_email.query, except that it pages off the sequential
 * feed_storydata.id instead of the chronologicalKey.
 *
 * feed.for_email.query cannot make progress past a story that has become
 * unloadable (no feed_storyreference row, a required object was deleted, or the
 * story type's class no longer exists): Phabricator validates the external
 * cursor by re-querying for it and throws PhabricatorInvalidQueryCursorException
 * when it resolves to nothing. Since chronologicalKeys are not sequential, the
 * caller cannot guess the next one and gets stuck until the database is fixed by
 * hand.
 *
 * This endpoint uses a "story.id > ?" range constraint instead of an external
 * cursor, so unloadable stories are just skipped. The returned cursor advances
 * past every row examined, including skipped ones. See
 * PhabricatorFeedEmailIDQuery.
 *
 * The response body is the same shape as feed.for_email.query, except that
 * "cursor.after" is an int.
 */

final class FeedForEmailQueryIDAPIMethod extends ConduitAPIMethod {
  private static int $DEFAULT_LIMIT = 100;

  public function getAPIMethodName(): string
  {
    return 'feed.for_email.query_id';
  }

  public function getMethodDescription(): string
  {
    return 'Query the feed for events that trigger email notifications, paging by feed story ID';
  }

  protected function defineParamTypes(): array
  {
    return array(
      'storyLimit' => 'optional int (default ' . self::$DEFAULT_LIMIT . ')',
      'after' => 'optional int',
    );
  }

  protected function defineReturnType(): string
  {
    return 'list';
  }

  public function getMethodStatus() {
    return self::METHOD_STATUS_UNSTABLE;
  }

  protected function execute(ConduitAPIRequest $request) {
    EmailAPIAuthorization::assert($request->getUser());

    $limit = $request->getValue('storyLimit') ?? self::$DEFAULT_LIMIT;
    $after = $request->getValue('after');
    $storyErrors = 0;

    $userStore = new PhabricatorUserStore();

    $result = PhabricatorStory::queryStoriesByID($userStore, $limit, $after);
    $emailEvents = (new EmailEventBuilder($userStore, true))->buildEvents($result->stories);

    $response = new EmailIDEndpointResponse(
      new EmailEndpointResponseData($emailEvents, $storyErrors),
      new EmailIDEndpointResponseCursor($limit, $result->lastID)
    );
    return json_encode($response);
  }
}
