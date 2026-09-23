<?php


/**
 * Cursor returned by feed.for_email.query_id. "after" is a feed_storydata.id,
 * so it is an integer rather than the chronologicalKey string used by
 * EmailEndpointResponseCursor.
 */
class EmailIDEndpointResponseCursor {
  public int $limit;
  public int $after;

  public function __construct(int $limit, int $after) {
    $this->limit = $limit;
    $this->after = $after;
  }
}
