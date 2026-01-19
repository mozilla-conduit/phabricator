<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * Adds a "Request AI Review" action to the revision page
 */
final class ReviewHelperEventListener extends PhabricatorEventListener {

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

    $service_url = PhabricatorEnv::getEnvConfig('reviewhelper.url');
    if (!$service_url) {
      return;
    }

    $user = $event->getUser();
    if (!$user || !$user->isLoggedIn()) {
      return;
    }

    $active_diff = $object->getActiveDiff();
    if (!$active_diff) {
      return;
    }

    $status = $object->getStatusObject();
    $is_open = !$status->isClosedStatus();

    $request_uri = '/reviewhelper/request/' . $object->getID() . '/';

    $tag = id(new PHUITagView())
      ->setType(PHUITagView::TYPE_SHADE)
      ->setColor(PHUITagView::COLOR_GREEN)
      ->setSlimShady(true)
      ->setName(pht('New'));

    $action = id(new PhabricatorActionView())
      ->setHref($request_uri)
      ->setName(array(pht('Request AI Review'), ' ', $tag))
      ->setIcon('fa-magic')
      ->setDisabled(!$is_open)
      ->setWorkflow(true);

    $actions = $event->getValue('actions');
    array_unshift($actions, $action);

    $event->setValue('actions', $actions);
  }
}
