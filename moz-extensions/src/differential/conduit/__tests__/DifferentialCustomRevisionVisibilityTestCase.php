<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

final class DifferentialCustomRevisionVisibilityTestCase
  extends PhabricatorTestCase {

  public function testClassifyVisibility() {
    $secure = 'PHID-PROJ-secure-revision';
    $other = 'PHID-PROJ-bmo-core-security';
    $custom = 'PHID-PLCY-abcdefghijklmnopqrst';

    $cases = array(
      // policy, projects named by the policy, expected
      array(PhabricatorPolicies::POLICY_PUBLIC, array(), 'public'),
      array(PhabricatorPolicies::POLICY_USER, array(), 'public'),
      array($custom, array($secure), 'unprocessed'),
      array($custom, array($other, $secure), 'unprocessed'),
      array($custom, array($other), 'secure'),
      array($custom, array(), 'secure'),
      array($other, array(), 'secure'),
    );

    foreach ($cases as $case) {
      list($policy, $projects, $expect) = $case;
      $this->assertEqual(
        $expect,
        DifferentialCustomRevisionVisibilityConduitAPIMethod
          ::classifyVisibility($policy, $projects, $secure),
        $policy);
    }

    // No #secure-revision project configured: never "unprocessed".
    $this->assertEqual(
      'secure',
      DifferentialCustomRevisionVisibilityConduitAPIMethod
        ::classifyVisibility($custom, array($secure), null));
  }

  public function testFilterPolicyProjectPHIDs() {
    $secure = 'PHID-PROJ-secure-revision';
    $other = 'PHID-PROJ-bmo-core-security';

    $cases = array(
      'no rules' => array(array(), array()),
      'allow, any project' => array(
        array(
          array(
            'action' => 'allow',
            'rule' => 'PhabricatorProjectsPolicyRule',
            'value' => array($secure, $other),
          ),
        ),
        array($secure, $other),
      ),
      'allow, all projects' => array(
        array(
          array(
            'action' => 'allow',
            'rule' => 'PhabricatorProjectsAllPolicyRule',
            'value' => array($other),
          ),
        ),
        array($other),
      ),
      // A deny rule takes access away, so it grants nothing.
      'deny' => array(
        array(
          array(
            'action' => 'deny',
            'rule' => 'PhabricatorProjectsPolicyRule',
            'value' => array($secure),
          ),
        ),
        array(),
      ),
      'allow other rule classes are ignored' => array(
        array(
          array(
            'action' => 'allow',
            'rule' => 'PhabricatorDifferentialReviewersPolicyRule',
            'value' => null,
          ),
        ),
        array(),
      ),
      'allow and deny mixed' => array(
        array(
          array(
            'action' => 'allow',
            'rule' => 'PhabricatorProjectsPolicyRule',
            'value' => array($other),
          ),
          array(
            'action' => 'deny',
            'rule' => 'PhabricatorProjectsPolicyRule',
            'value' => array($secure),
          ),
        ),
        array($other),
      ),
      // A malformed rule invalidates the whole policy.
      'malformed rule' => array(
        array(
          array(
            'action' => 'allow',
            'rule' => 'PhabricatorProjectsPolicyRule',
            'value' => array($secure),
          ),
          'bogus',
        ),
        array(),
      ),
    );

    foreach ($cases as $label => $case) {
      list($rules, $expect) = $case;
      $this->assertEqual(
        $expect,
        DifferentialCustomRevisionVisibilityConduitAPIMethod
          ::filterPolicyProjectPHIDs($rules),
        $label);
    }
  }
}
