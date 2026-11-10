<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

final class DifferentialRevisionRequiredActionNotAcceptedResultBucketTestCase
  extends PhabricatorTestCase {

  const VIEWER = 'PHID-USER-viewer';
  const AUTHOR = 'PHID-USER-author';
  const OTHER = 'PHID-USER-other';
  const GROUP = 'PHID-PROJ-group';
  const DIFF_OLD = 'PHID-DIFF-old';
  const DIFF_NEW = 'PHID-DIFF-new';

  const MUST_REVIEW = 'Must Review';
  const SHOULD_REVIEW = 'Ready to Review';
  const WAITING_OTHERS = 'Waiting on Other Reviewers';

  public function testAcceptedWithPendingGroupIsHeldBack() {
    // The viewer accepted, but a blocking group they belong to is pending.
    // The stock bucket would put this in "Must Review".
    $revisions = array(
      1 => $this->newRevision(array(
        $this->newAccept(self::VIEWER, self::DIFF_NEW),
        $this->newReviewer(
          self::GROUP,
          DifferentialReviewerStatus::STATUS_BLOCKING),
      )),
    );

    $groups = $this->bucketRevisions($revisions);

    $this->assertEqual(array(), $groups[self::MUST_REVIEW]);
    $this->assertEqual(array(), $groups[self::SHOULD_REVIEW]);
    $this->assertEqual(array(1), $groups[self::WAITING_OTHERS]);
  }

  public function testVoidedAcceptIsReviewable() {
    // "Request Review" voids the accept, so the viewer needs to look again.
    $accept = $this->newAccept(self::VIEWER, self::DIFF_NEW)
      ->setVoidedPHID('PHID-XACT-void');

    $revisions = array(
      1 => $this->newRevision(array($accept)),
    );

    $groups = $this->bucketRevisions($revisions);

    $this->assertEqual(array(1), $groups[self::SHOULD_REVIEW]);
  }

  public function testStickyAcceptOfOlderDiffIsReviewable() {
    // With differential.sticky-accept the accept survives a new diff, but
    // the viewer has not seen the new changes yet.
    $revisions = array(
      1 => $this->newRevision(array(
        $this->newAccept(self::VIEWER, self::DIFF_OLD),
        $this->newReviewer(
          self::GROUP,
          DifferentialReviewerStatus::STATUS_BLOCKING),
      )),
    );

    $groups = $this->bucketRevisions($revisions);

    $this->assertEqual(array(1), $groups[self::MUST_REVIEW]);
  }

  public function testUnreviewedRevisionIsReviewable() {
    $revisions = array(
      1 => $this->newRevision(array(
        $this->newReviewer(
          self::VIEWER,
          DifferentialReviewerStatus::STATUS_ADDED),
      )),
    );

    $groups = $this->bucketRevisions($revisions);

    $this->assertEqual(array(1), $groups[self::SHOULD_REVIEW]);
  }

  public function testHeldRevisionsKeepTheirOrder() {
    // Revisions 1 and 3 are held back while the review groups are built.
    // Revision 2 never matches them. All three land in the same later
    // group and must stay in the original order.
    $revisions = array(
      1 => $this->newRevision(array(
        $this->newAccept(self::VIEWER, self::DIFF_NEW),
        $this->newReviewer(
          self::GROUP,
          DifferentialReviewerStatus::STATUS_ADDED),
      )),
      2 => $this->newRevision(array(
        $this->newReviewer(
          self::OTHER,
          DifferentialReviewerStatus::STATUS_ADDED),
      )),
      3 => $this->newRevision(array(
        $this->newAccept(self::VIEWER, self::DIFF_NEW),
        $this->newReviewer(
          self::GROUP,
          DifferentialReviewerStatus::STATUS_BLOCKING),
      )),
    );

    $groups = $this->bucketRevisions($revisions);

    $this->assertEqual(array(), $groups[self::MUST_REVIEW]);
    $this->assertEqual(array(), $groups[self::SHOULD_REVIEW]);
    $this->assertEqual(array(1, 2, 3), $groups[self::WAITING_OTHERS]);
  }

  /**
   * Run the bucket for the viewer and their group.
   *
   * @return map<string, list<int>> Group name to revision keys, in order.
   */
  private function bucketRevisions(array $revisions) {
    $viewer = id(new PhabricatorUser())->setPHID(self::VIEWER);

    $query = id(new PhabricatorSavedQuery())
      ->attachParameterMap(array(
        'responsiblePHIDs' => array(self::VIEWER, self::GROUP),
      ));

    $groups = id(new DifferentialRevisionRequiredActionNotAcceptedResultBucket())
      ->setViewer($viewer)
      ->newResultGroups($query, $revisions);

    $result = array();
    foreach ($groups as $group) {
      $result[$group->getName()] = array_keys($group->getObjects());
    }

    return $result;
  }

  private function newRevision(array $reviewers) {
    return id(new DifferentialRevision())
      ->setAuthorPHID(self::AUTHOR)
      ->setModernRevisionStatus(DifferentialRevisionStatus::NEEDS_REVIEW)
      ->setActiveDiffPHID(self::DIFF_NEW)
      ->attachReviewers($reviewers);
  }

  private function newReviewer($phid, $status) {
    return id(new DifferentialReviewer())
      ->setReviewerPHID($phid)
      ->setReviewerStatus($status);
  }

  private function newAccept($phid, $diff_phid) {
    return $this->newReviewer(
      $phid,
      DifferentialReviewerStatus::STATUS_ACCEPTED)
      ->setLastActionDiffPHID($diff_phid);
  }

}
