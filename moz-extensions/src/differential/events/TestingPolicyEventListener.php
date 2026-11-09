<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * Reminds reviewers to set a testing policy project tag when they accept a
 * revision, and starts a comment explaining the exception for the tags which
 * need one.
 */
final class TestingPolicyEventListener extends PhabricatorEventListener {

  const TAG_PREFIX = 'testing-';
  const POLICY_PROJECT_SLUG = 'testing-policy';
  const POLICY_URI =
    'https://firefox-source-docs.mozilla.org/testing/testing-policy/';

  /**
   * Tags which don't explain the exception by themselves, so the reviewer
   * should say why in their comment.
   */
  private static $tagsRequiringComment = array(
    'testing-exception-elsewhere',
    'testing-exception-other',
  );

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

    if (!($object instanceof DifferentialRevision) || !$object->getPHID()) {
      return;
    }

    $user = $event->getUser();
    if (!$user || !$user->isLoggedIn()) {
      return;
    }

    if (!$this->repositoryHasTestingPolicy($object)) {
      return;
    }

    $testing_projects = id(new PhabricatorProjectQuery())
      ->setViewer($user)
      ->withNamePrefixes(array(self::TAG_PREFIX))
      ->execute();
    if (!$testing_projects) {
      return;
    }

    $testing_tags = mpull($testing_projects, 'getName', 'getPHID');

    $comment_tag_phids = array_keys(
      array_intersect($testing_tags, self::$tagsRequiringComment));

    $project_phids = PhabricatorEdgeQuery::loadDestinationPHIDs(
      $object->getPHID(),
      PhabricatorProjectObjectHasProjectEdgeType::EDGECONST);

    require_celerity_resource('testing-policy-css', 'moz-extensions');
    Javelin::initBehavior(
      'testing-policy',
      array(
        'testingTags' => $testing_tags,
        'projectPHIDs' => $project_phids,
        'commentTagPHIDs' => $comment_tag_phids,
        'policyURI' => self::POLICY_URI,
      ),
      'moz-extensions');
  }

  private function repositoryHasTestingPolicy(DifferentialRevision $revision) {
    $repository_phid = $revision->getRepositoryPHID();
    if (!$repository_phid) {
      return false;
    }

    $repository = id(new PhabricatorRepositoryQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->needProjectPHIDs(true)
      ->withPHIDs(array($repository_phid))
      ->executeOne();
    if (!$repository) {
      return false;
    }

    $policy_project = id(new PhabricatorProjectQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withSlugs(array(self::POLICY_PROJECT_SLUG))
      ->executeOne();

    return $policy_project && in_array(
      $policy_project->getPHID(),
      $repository->getProjectPHIDs(),
      true);
  }

}
