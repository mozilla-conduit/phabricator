<?php

final class DifferentialResponsibleDatasource
  extends PhabricatorTypeaheadCompositeDatasource {

  public function getBrowseTitle() {
    return pht('Browse Responsible Users');
  }

  public function getPlaceholderText() {
    return pht('Type a user or project name, or function...');
  }

  public function getDatasourceApplicationClass() {
    return 'PhabricatorDifferentialApplication';
  }

  public function getComponentDatasources() {
    return array(
      new DifferentialResponsibleUserDatasource(),
      new DifferentialResponsibleViewerFunctionDatasource(),
      new DifferentialExactUserFunctionDatasource(),
      new PhabricatorProjectDatasource(),
    );
  }

  public static function expandResponsibleUsers(
    PhabricatorUser $viewer,
    array $values) {

    $phids = array();
    foreach ($values as $value) {
      if (phid_get_type($value) == PhabricatorPeopleUserPHIDType::TYPECONST) {
        $phids[] = $value;
      }
    }

    if (!$phids) {
      return $values;
    }

    $projects = id(new PhabricatorProjectQuery())
       ->setViewer($viewer)
       ->withMemberPHIDs($phids)
       ->execute();
    foreach ($projects as $project) {
      $phids[] = $project->getPHID();
      $values[] = $project->getPHID();
    }

    return $values;
  }

}
