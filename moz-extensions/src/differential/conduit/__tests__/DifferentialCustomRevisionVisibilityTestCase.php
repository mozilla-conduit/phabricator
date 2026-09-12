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
}
