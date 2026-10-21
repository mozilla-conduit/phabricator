<?php

final class DifferentialLocalCommitsViewTestCase extends PhabricatorTestCase {

  public function testRenderCommitWithoutSummary() {
    // `moz-phab` submits local commits with a message but no summary.
    $view = id(new DifferentialLocalCommitsView())
      ->setViewer(new PhabricatorUser())
      ->setLocalCommits(
        array(
          array(
            'commit' => 'a0b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e6f7a8b9',
            'parents' => array(),
            'author' => 'Author <author@example.com>',
            'message' => "Bug 1 - Fix things.\n\nMore detail.",
          ),
        ));

    $this->assertNoDeprecations(
      function () use ($view) {
        return $view->render();
      },
      pht('Rendering a local commit without a summary should not raise '.
        'deprecation warnings.'));
  }

}
