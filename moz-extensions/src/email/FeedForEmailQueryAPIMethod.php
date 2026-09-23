<?php

final class FeedForEmailQueryAPIMethod extends ConduitAPIMethod {
  private static int $DEFAULT_LIMIT = 100;

  public function getAPIMethodName(): string
  {
    return 'feed.for_email.query';
  }

  public function getMethodDescription(): string
  {
    return 'Query the feed for events that trigger email notifications';
  }

  protected function defineParamTypes(): array
  {
    return array(
      'storyLimit' => 'optional int (default ' . self::$DEFAULT_LIMIT . ')',
      'after' => 'string',
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

    $result = PhabricatorStory::queryStories($userStore, $limit, $after);
    $emailEvents = (new EmailEventBuilder($userStore))->buildEvents($result->stories);

    $response = new EmailEndpointResponse(
      new EmailEndpointResponseData($emailEvents, $storyErrors),
      new EmailEndpointResponseCursor($limit, $result->lastKey)
    );
    return json_encode($response);
  }


}
