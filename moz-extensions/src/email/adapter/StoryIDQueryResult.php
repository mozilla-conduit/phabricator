<?php


/**
 * Result of PhabricatorStory::queryStoriesByID(). The cursor is a
 * feed_storydata.id rather than a chronologicalKey.
 */
class StoryIDQueryResult {
  public int $lastID;
  /** @var PhabricatorStory[] */
  public array $stories;

  /**
   * @param int $lastID
   * @param PhabricatorStory[] $stories
   */
  public function __construct(int $lastID, array $stories) {
    $this->lastID = $lastID;
    $this->stories = $stories;
  }
}
