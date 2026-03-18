<?php

final class DifferentialRevisionRequiredActionWithNeedsChangesResultBucket
  extends DifferentialRevisionRequiredActionResultBucket {

  const BUCKETKEY = 'action-with-needs-changes';

  public function getResultBucketName() {
    return pht('Bucket by Required Action (Include Needs Changes)');
  }

  protected function filterMustReview(array $phids) {
    $needs_review_statuses = array_fuse(array(
      DifferentialReviewerStatus::STATUS_BLOCKING,
      DifferentialReviewerStatus::STATUS_REJECTED,
      DifferentialReviewerStatus::STATUS_REJECTED_OLDER,
    ));

    return $this->filterWithNeedsRevision($phids, $needs_review_statuses);
  }

  protected function filterShouldReview(array $phids) {
    $needs_review_statuses = array_fuse(array(
      DifferentialReviewerStatus::STATUS_ADDED,
      DifferentialReviewerStatus::STATUS_COMMENTED,
      DifferentialReviewerStatus::STATUS_ACCEPTED,
    ));

    return $this->filterWithNeedsRevision($phids, $needs_review_statuses);
  }

  private function filterWithNeedsRevision(array $phids, array $needs_review_statuses) {
    $inactive = array_fuse(array(
      DifferentialReviewerStatus::STATUS_ADDED,
      DifferentialReviewerStatus::STATUS_COMMENTED,
    ));

    // Standard NEEDS_REVIEW revisions (same as parent).
    $objects = $this->getRevisionsUnderReview($this->objects, $phids);

    // Also pick up NEEDS_REVISION revisions where the viewer hasn't acted yet.
    foreach ($this->getRevisionsNotAuthored($this->objects, $phids) as $key => $object) {
      if ($object->isNeedsRevision()) {
        $objects[$key] = $object;
      }
    }

    $results = array();
    foreach ($objects as $key => $object) {
      if ($object->isNeedsReview()) {
        // Same as parent — check voided accepts too.
        if (!$this->hasReviewersWithStatus($object, $phids, $needs_review_statuses, true)) {
          continue;
        }
      } else {
        // NEEDS_REVISION — only show if this reviewer hasn't acted yet.
        if (!$this->hasReviewersWithStatus($object, $phids, $inactive)) {
          continue;
        }
      }

      $results[$key] = $object;
      unset($this->objects[$key]);
    }

    return $results;
  }
}
