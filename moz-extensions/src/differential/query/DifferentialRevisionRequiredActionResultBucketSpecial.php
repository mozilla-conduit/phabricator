<?php

final class DifferentialRevisionRequiredActionResultBucketSpecial
  extends DifferentialRevisionRequiredActionResultBucket {

  const BUCKETKEY = 'special';

  public function getResultBucketName() {
    return pht('Bucket by Required Action (Special)');
  }

  private function filterMustReview(array $phids) {
    $objects = $this->getRevisionsUnderReview($this->objects, $phids);

    $results = array();
    foreach ($objects as $key => $object) {
      $results[$key] = $object;
      unset($this->objects[$key]);
    }

    return $results;
  }

  private function filterShouldReview(array $phids) {
    $objects = $this->getRevisionsUnderReview($this->objects, $phids);

    $results = array();
    foreach ($objects as $key => $object) {
      $results[$key] = $object;
      unset($this->objects[$key]);
    }

    return $results;
  }
}
