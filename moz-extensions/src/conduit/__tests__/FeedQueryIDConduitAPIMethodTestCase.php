<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

final class FeedQueryIDConduitAPIMethodTestCase extends PhabricatorTestCase {

  protected function getPhabricatorTestCaseConfiguration() {
    return array(
      self::PHABRICATOR_TESTCONFIG_BUILD_STORAGE_FIXTURES => true,
    );
  }

  public function testQueryWithoutCursors() {
    $viewer = $this->generateNewTestUser();

    $result = $this->assertNoDeprecations(
      function () use ($viewer) {
        return id(new ConduitCall('feed.query_id', array()))
          ->setUser($viewer)
          ->execute();
      },
      pht('Calling `feed.query_id` without `before` or `after` should not '.
        'raise deprecation warnings.'));

    $this->assertTrue(
      is_array($result),
      pht('`feed.query_id` should return a result without cursors.'));
  }

}
