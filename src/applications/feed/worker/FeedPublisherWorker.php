<?php

final class FeedPublisherWorker extends FeedPushWorker {

  protected function doWork() {
    $story = $this->loadFeedStory();

    $uris = PhabricatorEnv::getEnvConfig('feed.http-hooks');

    if ($uris) {
      foreach ($uris as $uri) {
        $this->queueTask(
          'FeedPublisherHTTPWorker',
          array(
            'key' => $story->getChronologicalKey(),
            'uri' => $uri,
          ));
      }
    }
  }


}
