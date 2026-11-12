<?php

final class PhabricatorWorkingCopyPullTestCase
  extends PhabricatorWorkingCopyTestCase {

  public function testGitPullBasic() {
    $repo = $this->buildPulledRepository('GT');

    $this->assertTrue(Filesystem::pathExists($repo->getLocalPath().'/HEAD'));
  }

  public function testHgPullBasic() {
    $this->requireBinaryForTest('hg');
    $repo = $this->buildPulledRepository('HT');

    $this->assertTrue(Filesystem::pathExists($repo->getLocalPath().'/.hg'));
  }

}
