<?php

final class PHUIHomeView
  extends AphrontTagView {

  protected function getTagName() {
    return null;
  }

  protected function getTagAttributes() {
    return array();
  }

  protected function getTagContent() {
    require_celerity_resource('phabricator-dashboard-css');
    $viewer = $this->getViewer();

    $has_diffusion = PhabricatorApplication::isClassInstalledForViewer(
      'PhabricatorDiffusionApplication',
      $viewer);

    $has_differential = PhabricatorApplication::isClassInstalledForViewer(
      'PhabricatorDifferentialApplication',
      $viewer);

    $revision_panel = null;
    if ($has_differential) {
      $revision_panel = $this->buildRevisionPanel();
    }

    $repository_panel = null;
    if ($has_diffusion) {
      $repository_panel = $this->buildRepositoryPanel();
    }

    $feed_panel = $this->buildFeedPanel();

    $dashboard = id(new AphrontMultiColumnView())
      ->setFluidlayout(true)
      ->setGutter(AphrontMultiColumnView::GUTTER_LARGE);

    $main_panel = phutil_tag(
      'div',
      array(
        'class' => 'homepage-panel',
      ),
      array(
        $revision_panel,
        $repository_panel,
      ));
    $dashboard->addColumn($main_panel, 'thirds');

    $side_panel = phutil_tag(
      'div',
      array(
        'class' => 'homepage-side-panel',
      ),
      array(
        $feed_panel,
      ));
    $dashboard->addColumn($side_panel, 'third');

      $view = id(new PHUIBoxView())
        ->addClass('dashboard-view')
        ->appendChild($dashboard);

      return $view;
  }

  private function buildRevisionPanel() {
    $viewer = $this->getViewer();
    if (!$viewer->isLoggedIn()) {
      return null;
    }

    $panel = $this->newQueryPanel()
      ->setName(pht('Active Revisions'))
      ->setProperty('class', 'DifferentialRevisionSearchEngine')
      ->setProperty('key', 'active');

    return $this->renderPanel($panel);
  }

  public function buildFeedPanel() {
    $panel = $this->newQueryPanel()
      ->setName(pht('Recent Activity'))
      ->setProperty('class', 'PhabricatorFeedSearchEngine')
      ->setProperty('key', 'all')
      ->setProperty('limit', 40);

    return $this->renderPanel($panel);
  }

  public function buildRepositoryPanel() {
    $panel = $this->newQueryPanel()
      ->setName(pht('Active Repositories'))
      ->setProperty('class', 'PhabricatorRepositorySearchEngine')
      ->setProperty('key', 'active')
      ->setProperty('limit', 5);

    return $this->renderPanel($panel);
  }

  private function newQueryPanel() {
    $panel_type = id(new PhabricatorDashboardQueryPanelType())
      ->getPanelTypeKey();

    return id(new PhabricatorDashboardPanel())
      ->setPanelType($panel_type);
  }

  private function renderPanel(PhabricatorDashboardPanel $panel) {
    $viewer = $this->getViewer();

    return id(new PhabricatorDashboardPanelRenderingEngine())
      ->setViewer($viewer)
      ->setPanel($panel)
      ->setParentPanelPHIDs(array())
      ->renderPanel();
  }

}
