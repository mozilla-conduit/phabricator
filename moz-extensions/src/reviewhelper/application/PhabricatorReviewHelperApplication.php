<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

final class PhabricatorReviewHelperApplication extends PhabricatorApplication {

  public function getName() {
    return pht('Review Helper');
  }

  public function getShortDescription() {
    return pht('AI-Assisted Code Review');
  }

  public function getBaseURI() {
    return '/reviewhelper/';
  }

  public function getIcon() {
    return 'fa-magic';
  }

  public function isLaunchable() {
    return false;
  }

  public function getRoutes() {
    return array(
      '/reviewhelper/' => array(
        'request/(?P<revisionID>[1-9]\d*)/' => 'ReviewHelperRequestController',
        'feedback/' => 'ReviewHelperFeedbackController',
      ),
    );
  }
}
