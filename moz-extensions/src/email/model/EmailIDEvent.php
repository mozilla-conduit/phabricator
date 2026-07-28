<?php


/**
 * An EmailEvent that also carries the feed story ID it came from.
 *
 * Only feed.for_email.query_id returns these. feed.for_email.query keeps
 * returning plain EmailEvents so that its response stays byte-for-byte what the
 * currently deployed phabricator-emails service already consumes.
 */
class EmailIDEvent extends EmailEvent {
  /** feed_storydata.id of the story this event came from */
  public int $feedId;

  /**
   * @param string $key
   * @param int $feedId
   * @param int $timestamp
   * @param bool $isSecure
   * @param MinimalEmailContext $minimalContext
   * @param PublicEmailContext|SecureEmailContext|null $context
   */
  public function __construct(string $key, int $feedId, int $timestamp, bool $isSecure, MinimalEmailContext $minimalContext, $context)
  {
    parent::__construct($key, $timestamp, $isSecure, $minimalContext, $context);
    $this->feedId = $feedId;
  }
}
