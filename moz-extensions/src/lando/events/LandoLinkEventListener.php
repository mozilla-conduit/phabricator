<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * Adds a `View in Lando` link on the revision page
 */

final class LandoLinkEventListener extends PhabricatorEventListener {

  public function register() {
    $this->listen(PhabricatorEventType::TYPE_UI_DIDRENDERACTIONS);
  }

  public function handleEvent(PhutilEvent $event) {
    if ($event->getType() == PhabricatorEventType::TYPE_UI_DIDRENDERACTIONS) {
        $this->handleActionEvent($event);
    }
  }

  private function handleActionEvent($event) {
    $object = $event->getValue('object');

    if (!($object && $object->getPHID() && $object instanceof DifferentialRevision)) {
      return;
    }

    $legacy_lando_uri = PhabricatorEnv::getEnvConfig('lando-ui.url');
    $new_lando_uri = PhabricatorEnv::getEnvConfig('lando.url');

    if (!$legacy_lando_uri && !$new_lando_uri) {
      return;
    }

    $active_diff = $object->getActiveDiff();
    if (!$active_diff) {
      return;
    }


    // Get repository projects, and determine if it uses the new Lando.
    // If a repository is associated with the "lando" project, it is treated
    // as a repo that uses the modern Lando. Otherwise, it is treated as using
    // the legacy Lando.
    $repository_phid = $object->getRepositoryPHID();
    $repository = id(new PhabricatorRepositoryQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->needProjectPHIDs(true)
      ->withPHIDs(array($repository_phid))
      ->executeOne();

    if (!$repository) {
        return;
    }

    $repository_project_phids = $repository->getProjectPHIDs();

    if ($repository_project_phids) {
        $projects = id(new PhabricatorProjectQuery())
          ->setViewer(PhabricatorUser::getOmnipotentUser())
          ->withPHIDs($repository_project_phids)
          ->execute();

        $lando_project = id(new PhabricatorProjectQuery())
          ->setViewer(PhabricatorUser::getOmnipotentUser())
          ->withNames(array("lando"))
          ->executeOne();

        $uses_new_lando = in_array($lando_project, $projects);
    } else {
        $uses_new_lando = false;
    }

    if ($uses_new_lando) {
        $lando_uri = $new_lando_uri;
    } else {
        $lando_uri = $legacy_lando_uri;
    }

    $lando_stack_uri = (string) id(new PhutilURI($lando_uri))
      ->setPath('/D' . $object->getID() . '/');

    $action = id(new PhabricatorActionView())
      ->setHref($lando_stack_uri)
      ->setName(pht('View Stack in Lando'))
      ->setIcon('fa-link');

    $actions = $event->getValue('actions');
    $actions[] = $action;

    $event->setValue('actions', $actions);
  }

}
