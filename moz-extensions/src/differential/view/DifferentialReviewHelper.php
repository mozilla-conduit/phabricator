<?php
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at http://mozilla.org/MPL/2.0/.
#
# This Source Code Form is "Incompatible With Secondary Licenses", as
# defined by the Mozilla Public License, v. 2.0.

class DifferentialReviewHelper extends Phobject {
  private $content = array();

  public function createJWT($viewer, $revision) {
    $this->content[] = $this->createContent($viewer, $revision);
    return $this->content;
  }

  public function createContent($viewer, $revision) {
    $content = new PHUIInfoView();
    $content->setTitle(pht('This is test.'));
    $content->appendChild(hsprintf(pht("Hello world!")));
    return $content->render();
    #$warning->setSeverity(PHUIInfoView::SEVERITY_WARNING);
  }
}
