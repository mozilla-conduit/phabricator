<?php

/*
 * Feed query used by feed.for_email.query_id.
 *
 * Unlike PhabricatorFeedQuery (and PhabricatorFeedIDQuery), this query does not
 * use an external cursor. Paging with an external cursor requires the cursor
 * value to identify a story that is still loadable: PhabricatorCursorPagedPolicy
 * AwareQuery::newInternalCursorFromExternalCursor() re-runs the query against
 * the cursor value alone and throws PhabricatorInvalidQueryCursorException when
 * nothing comes back. That happens whenever the story at the cursor has no
 * feed_storyreference row, references an object that has since been deleted, or
 * has a story type whose class no longer exists -- and it leaves the caller with
 * a cursor it can never page past.
 *
 * Because feed_storydata.id is a sequential integer, we can express "give me
 * what comes after this point" as a plain range constraint instead. Unloadable
 * stories are then simply dropped by willFilterPage() and the scan walks past
 * them, so no story can wedge the caller.
 */

final class PhabricatorFeedEmailIDQuery extends PhabricatorFeedQuery {

  private $minID;
  private $lastRawID;

  public function withMinID($id) {
    $this->minID = $id;
    return $this;
  }

  /**
   * Highest feed_storydata.id examined, including stories that were later
   * dropped as unloadable. Callers must advance their cursor to this value
   * rather than to the id of the last returned story, otherwise a page that is
   * entirely filtered leaves the cursor where it started.
   *
   * Null when the query examined no rows at all.
   */
  public function getLastRawID() {
    return $this->lastRawID;
  }

  // Hardcode the table to 'story' because we page off the ID value instead of
  // chronologicalKey. feed_storydata has an ID column; feed_storyreference does
  // not. This is only correct while withFilterPHIDs() is unused, which is the
  // case for the email endpoints.
  public function getOrderableColumns() {
    return array(
      'key' => array(
        'table' => 'story',
        'column' => 'id',
        'type' => 'int',
        'unique' => true,
      ),
    );
  }

  protected function buildWhereClauseParts(AphrontDatabaseConnection $conn) {
    $where = parent::buildWhereClauseParts($conn);

    if ($this->minID !== null) {
      $where[] = qsprintf($conn, 'story.id > %d', $this->minID);
    }

    return $where;
  }

  protected function willFilterPage(array $data) {
    // Record raw ids before any filtering happens. PhabricatorPolicyAwareQuery
    // ::execute() may loop over several internal pages to fill a single result
    // page, and each of them passes through here.
    foreach ($data as $row) {
      $id = (int)$row['id'];
      if ($this->lastRawID === null || $id > $this->lastRawID) {
        $this->lastRawID = $id;
      }
    }

    return parent::willFilterPage($data);
  }

  public function newPagingMapFromPartialObject($object) {
    // This query is unusual, and the "object" is a raw result row.
    return array(
      'key' => $object['id'],
    );
  }
}
