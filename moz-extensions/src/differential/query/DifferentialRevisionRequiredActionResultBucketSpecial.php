<?php

final class DifferentialRevisionRequiredActionResultBucketSpecial
  extends DifferentialRevisionRequiredActionResultBucket {

  const BUCKETKEY = 'special';

  public function getResultBucketName() {
    return pht('Bucket by Required Action (Special)');
  }

  private function filterMustReview(array $phids) {
    $blocking = array(
      DifferentialReviewerStatus::STATUS_BLOCKING,
      DifferentialReviewerStatus::STATUS_REJECTED,
      DifferentialReviewerStatus::STATUS_REJECTED_OLDER,
    );
    $blocking = array_fuse($blocking);

    $objects = $this->getRevisionsUnderReview($this->objects, $phids);

    $results = array();
    foreach ($objects as $key => $object) {
      $results[$key] = $object;
      unset($this->objects[$key]);
    }

    return $results;
  }

  private function filterShouldReview(array $phids) {
    $reviewing = array(
      DifferentialReviewerStatus::STATUS_ADDED,
      DifferentialReviewerStatus::STATUS_COMMENTED,
      DifferentialReviewerStatus::STATUS_ACCEPTED,
    );
    $reviewing = array_fuse($reviewing);

    $objects = $this->getRevisionsUnderReview($this->objects, $phids);

    $results = array();
    foreach ($objects as $key => $object) {
      $results[$key] = $object;
      unset($this->objects[$key]);
    }

    return $results;
  }
}
