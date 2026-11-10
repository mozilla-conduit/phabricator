<?php

/**
 * Same as the "Include Needs Changes" bucket, but revisions the viewer has
 * personally accepted are kept out of "Must Review" and "Ready to Review".
 *
 * The default buckets match on every responsible PHID (the viewer plus their
 * projects and packages), so a revision the viewer already accepted still
 * shows up for review while a group reviewer they belong to is pending.
 *
 * Held-back revisions fall through to the later groups ("Waiting on Other
 * Reviewers" / "Waiting on Authors"). They return to the review groups once
 * the accept is removed, for example when the author uses "Request Review",
 * or when a new diff is uploaded after the accept (even if
 * differential.sticky-accept keeps the revision "Accepted").
 */
final class DifferentialRevisionRequiredActionNotAcceptedResultBucket
  extends DifferentialRevisionRequiredActionWithNeedsChangesResultBucket {

  const BUCKETKEY = 'action-not-accepted';

  public function getResultBucketName() {
    return pht('Bucket by Required Action (Exclude Accepted)');
  }

  protected function filterMustReview(array $phids) {
    $held = $this->holdAcceptedByViewer();
    $results = parent::filterMustReview($phids);
    $this->restoreHeld($held);

    return $results;
  }

  protected function filterShouldReview(array $phids) {
    $held = $this->holdAcceptedByViewer();
    $results = parent::filterShouldReview($phids);
    $this->restoreHeld($held);

    return $results;
  }

  /**
   * Temporarily remove revisions the viewer has accepted from the pool.
   *
   * @return array Map with the original object order and the held revisions.
   */
  private function holdAcceptedByViewer() {
    $viewer_phid = $this->getViewer()->getPHID();
    $order = $this->objects;

    $held = array();
    foreach ($this->objects as $key => $revision) {
      if ($this->isAcceptedBy($revision, $viewer_phid)) {
        $held[$key] = $revision;
        unset($this->objects[$key]);
      }
    }

    return array($order, $held);
  }

  /**
   * Put held revisions back, preserving the original result order.
   */
  private function restoreHeld(array $state) {
    list($order, $held) = $state;

    $this->objects = array_intersect_key($order, $this->objects + $held);
  }

  private function isAcceptedBy(DifferentialRevision $revision, $viewer_phid) {
    $diff_phid = $revision->getActiveDiffPHID();

    foreach ($revision->getReviewers() as $reviewer) {
      if ($reviewer->getReviewerPHID() !== $viewer_phid) {
        continue;
      }

      // Handles voided accepts ("Request Review").
      if (!$reviewer->isAccepted($diff_phid)) {
        return false;
      }

      // isAccepted() also honors differential.sticky-accept, but an accept
      // carried over from an older diff should not hide the new changes
      // from the viewer. Same check as DifferentialRevisionReviewTransaction.
      $action_phid = $reviewer->getLastActionDiffPHID();
      return (!$diff_phid || !$action_phid || $action_phid == $diff_phid);
    }

    return false;
  }
}
