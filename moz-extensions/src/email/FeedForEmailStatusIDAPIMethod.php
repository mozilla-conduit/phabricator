<?php

/*
 * feed.for_email.status equivalent for feed.for_email.query_id: returns the
 * feed story ID to start paging from rather than a chronologicalKey.
 */

final class FeedForEmailStatusIDAPIMethod extends ConduitAPIMethod {
  public function getAPIMethodName(): string
  {
    return 'feed.for_email.status_id';
  }

  public function getMethodDescription(): string
  {
    return 'Provides the feed story ID of the most recent feed story';
  }

  protected function defineParamTypes(): array
  {
    return array();
  }

  protected function defineReturnType(): string
  {
    return 'int';
  }

  public function getMethodStatus() {
    return self::METHOD_STATUS_UNSTABLE;
  }

  protected function execute(ConduitAPIRequest $request) {
    EmailAPIAuthorization::assert($request->getUser());

    // We want the newest story ID whether or not that story is still loadable,
    // so read it out of getLastRawID() instead of from a returned story. This
    // also means we don't have to care about the query coming back empty.
    $query = (new PhabricatorFeedEmailIDQuery())
      ->setOrder('newest')
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->setLimit(1)
      // Overheating would throw before we could read getLastRawID(). We only
      // need the raw ID, so a truncated scan is fine.
      ->setReturnPartialResultsOnOverheat(true);

    $query->execute();

    return $query->getLastRawID() ?? 0;
  }
}
