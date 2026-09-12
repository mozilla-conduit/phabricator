<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * Report whether a revision is still awaiting phab-bot processing, or has
 * been processed into a secure or public revision.
 *
 * New revisions are created with the default view policy, a custom policy
 * granting access to the author, the reviewers and members of
 * #secure-revision. Shortly afterwards phab-bot replaces that policy with
 * either a public policy or a custom policy built from the Bugzilla groups
 * of the associated bug.
 */
final class DifferentialCustomRevisionVisibilityConduitAPIMethod
  extends DifferentialConduitAPIMethod {

  const SECURE_PROJECT_NAME = 'secure-revision';

  public function getAPIMethodName() {
    return 'differential.custom.revision.visibility';
  }

  public function getMethodDescription() {
    return pht(
      'Return whether a revision is "unprocessed" (still waiting on '.
      'phab-bot), "secure" or "public". Answers for revisions the caller '.
      'can not otherwise see.');
  }

  public function getMethodStatus() {
    return self::METHOD_STATUS_UNSTABLE;
  }

  /**
   * The revision is loaded as the omnipotent user, so callers must at least
   * be logged in. This is the default, but is stated explicitly here because
   * the method depends on it.
   */
  public function shouldRequireAuthentication() {
    return true;
  }

  protected function defineParamTypes() {
    return array(
      'revisionID' => 'required int',
    );
  }

  protected function defineReturnType() {
    return 'map<string, wild>';
  }

  protected function defineErrorTypes() {
    return array(
      'ERR-INVALID-PARAMETER' => pht('Missing or malformed parameter.'),
      'ERR-REVISION-NOT-FOUND' => pht('Revision not found.'),
    );
  }

  protected function execute(ConduitAPIRequest $request) {
    $revision_id = (int)$request->getValue('revisionID');
    if (!$revision_id) {
      throw id(new ConduitException('ERR-INVALID-PARAMETER'))
        ->setErrorDescription(pht('Parameter "revisionID" is required.'));
    }

    // Loaded as the omnipotent user: callers routinely can not see the
    // revisions they are asking about. NOTHING derived from the revision may
    // be returned or included in an error message except the three-state
    // visibility below, which is exactly what a permission denial would
    // already tell the caller.
    $revision = id(new DifferentialRevisionQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withIDs(array($revision_id))
      ->executeOne();

    if (!$revision) {
      throw id(new ConduitException('ERR-REVISION-NOT-FOUND'))
        ->setErrorDescription(pht('Revision D%d not found.', $revision_id));
    }

    $view_policy = $revision->getViewPolicy();
    $policy_projects = self::loadPolicyProjectPHIDs($view_policy);
    $secure_phid = $policy_projects
      ? self::loadSecureProjectPHID()
      : null;

    return array(
      'revisionID' => $revision->getID(),
      'visibility' => self::classifyVisibility(
        $view_policy,
        $policy_projects,
        $secure_phid),
    );
  }

  /**
   * @param string $view_policy Revision view policy.
   * @param list<phid> $policy_projects Projects named by the view policy.
   * @param phid|null $secure_phid PHID of the #secure-revision project.
   * @return string One of "unprocessed", "secure" or "public".
   */
  public static function classifyVisibility(
    $view_policy,
    array $policy_projects,
    $secure_phid) {

    $is_public = in_array(
      $view_policy,
      array(
        PhabricatorPolicies::POLICY_PUBLIC,
        PhabricatorPolicies::POLICY_USER,
      ),
      true);

    if ($is_public) {
      return 'public';
    }

    if ($secure_phid && in_array($secure_phid, $policy_projects, true)) {
      return 'unprocessed';
    }

    return 'secure';
  }

  /**
   * Projects named by "members of any project" rules of a custom policy.
   */
  private static function loadPolicyProjectPHIDs($view_policy) {
    $is_custom = (phid_get_type($view_policy) ===
      PhabricatorPolicyPHIDTypePolicy::TYPECONST);
    if (!$is_custom) {
      return array();
    }

    $policy = id(new PhabricatorPolicyQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs(array($view_policy))
      ->executeOne();

    if (!$policy) {
      return array();
    }

    return array_mergev(
      $policy->getCustomRuleValues('PhabricatorProjectsPolicyRule'));
  }

  private static function loadSecureProjectPHID() {
    $project = id(new PhabricatorProjectQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withNames(array(self::SECURE_PROJECT_NAME))
      ->executeOne();

    return $project ? $project->getPHID() : null;
  }
}
