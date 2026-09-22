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

    return array(
      'revisionID' => $revision->getID(),
      'visibility' => self::classifyVisibility(
        $view_policy,
        self::loadPolicyProjectPHIDs($view_policy),
        self::loadSecureProjectPHID()),
    );
  }

  /**
   * A revision is "unprocessed" while it still carries the default view
   * policy, which grants access to members of #secure-revision.
   *
   * Once phab-bot has processed the revision it is either public, or carries
   * a custom policy built from the "bmo-*" projects of the bug's Bugzilla
   * groups. In that case #secure-revision survives only as a project tag on
   * the revision, never as a policy rule (see PhabBugz Feed.pm
   * process_revision_change() and Policy.pm create()). Granting access to
   * #secure-revision is therefore the signature of the pre-processing
   * default policy.
   *
   * @param string $view_policy Revision view policy.
   * @param list<phid> $policy_projects Projects granted access by the view
   *   policy. Empty for policies that grant access to no project.
   * @param phid|null $secure_phid PHID of the #secure-revision project, or
   *   null if no such project exists.
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
   * Projects granted access by the "members of project" rules of a custom
   * policy.
   *
   * Only ALLOW rules are collected: a DENY rule takes access away, so the
   * projects it names are not granted anything by this policy. Both project
   * rule classes are read because phab-bot uses them interchangeably (see
   * PhabBugz Policy.pm _build_rule_projects()).
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

    return self::filterPolicyProjectPHIDs($policy->getRules());
  }

  /**
   * @param list<wild> $rules Rules of a custom policy.
   * @return list<phid> Projects granted access by those rules.
   */
  public static function filterPolicyProjectPHIDs(array $rules) {
    $project_rules = array(
      'PhabricatorProjectsPolicyRule',
      'PhabricatorProjectsAllPolicyRule',
    );

    $phids = array();
    foreach ($rules as $rule) {
      if (!is_array($rule)) {
        // The policy filter rejects a policy with a malformed rule outright,
        // so treat it as granting no project and fall back to "secure".
        return array();
      }
      if (idx($rule, 'action') !== PhabricatorPolicy::ACTION_ALLOW) {
        continue;
      }
      if (!in_array(idx($rule, 'rule'), $project_rules, true)) {
        continue;
      }
      foreach ((array)idx($rule, 'value', array()) as $phid) {
        $phids[] = $phid;
      }
    }

    return $phids;
  }

  private static function loadSecureProjectPHID() {
    $project = id(new PhabricatorProjectQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withNames(array(self::SECURE_PROJECT_NAME))
      ->executeOne();

    return $project ? $project->getPHID() : null;
  }
}
