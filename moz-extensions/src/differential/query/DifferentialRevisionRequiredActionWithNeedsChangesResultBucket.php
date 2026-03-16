<?php

final class DifferentialRevisionRequiredActionWithNeedsChangesResultBucket
  extends DifferentialRevisionRequiredActionResultBucket {

  const BUCKETKEY = 'action-with-needs-changes';

  public function getResultBucketName() {
    return pht('Bucket by Required Action (Include Needs Changes)');
  }

  protected function filterShouldReview(array $phids) {
    $reviewing = array(
      DifferentialReviewerStatus::STATUS_ADDED,
      DifferentialReviewerStatus::STATUS_COMMENTED,
      DifferentialReviewerStatus::STATUS_ACCEPTED,
    );
    $reviewing = array_fuse($reviewing);

    $inactive = array(
      DifferentialReviewerStatus::STATUS_ADDED,
      DifferentialReviewerStatus::STATUS_COMMENTED,
    );
    $inactive = array_fuse($inactive);

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
        if (!$this->hasReviewersWithStatus($object, $phids, $reviewing, true)) {
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
