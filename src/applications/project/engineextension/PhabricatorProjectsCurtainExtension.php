<?php

final class PhabricatorProjectsCurtainExtension
  extends PHUICurtainExtension {

  const EXTENSIONKEY = 'projects.projects';

  public function shouldEnableForObject($object) {
    return ($object instanceof PhabricatorProjectInterface);
  }

  public function getExtensionApplication() {
    return new PhabricatorProjectApplication();
  }

  public function buildCurtainPanel($object) {
    $viewer = $this->getViewer();

    $project_phids = PhabricatorEdgeQuery::loadDestinationPHIDs(
      $object->getPHID(),
      PhabricatorProjectObjectHasProjectEdgeType::EDGECONST);

    $has_projects = (bool)$project_phids;
    $project_phids = array_reverse($project_phids);
    $handles = $viewer->loadHandles($project_phids);

    if ($has_projects) {
      $list = id(new PHUIHandleTagListView())
        ->setHandles($handles)
        ->setShowHovercards(true);
    } else {
      $list = phutil_tag('em', array(), pht('None'));
    }

    return $this->newPanel()
      ->setHeaderText(pht('Tags'))
      ->setOrder(10000)
      ->appendChild($list);
  }

}
