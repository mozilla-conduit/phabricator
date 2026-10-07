<?php

final class DifferentialReviewerFunctionDatasource
  extends PhabricatorTypeaheadCompositeDatasource {

  public function getBrowseTitle() {
    return pht('Browse Reviewers');
  }

  public function getPlaceholderText() {
    return pht('Type a user, project name or function...');
  }

  public function getDatasourceApplicationClass() {
    return 'PhabricatorDifferentialApplication';
  }

  public function getComponentDatasources() {
    return array(
      new PhabricatorProjectOrUserFunctionDatasource(),
      new DifferentialNoReviewersDatasource(),
    );
  }

}
