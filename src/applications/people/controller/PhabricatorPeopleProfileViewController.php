<?php

final class PhabricatorPeopleProfileViewController
  extends PhabricatorPeopleProfileController {

  public function shouldAllowPublic() {
    return true;
  }

  public function handleRequest(AphrontRequest $request) {
    $viewer = $this->getViewer();
    $username = $request->getURIData('username');

    $user = id(new PhabricatorPeopleQuery())
      ->setViewer($viewer)
      ->withUsernames(array($username))
      ->needProfileImage(true)
      ->executeOne();
    if (!$user) {
      return new Aphront404Response();
    }

    $this->setUser($user);
    $header = $this->buildProfileHeader();

    $properties = $this->buildPropertyView($user);
    $name = $user->getUsername();

    $feed = $this->buildPeopleFeed($user, $viewer);

    $view_all = id(new PHUIButtonView())
      ->setTag('a')
      ->setIcon(
        id(new PHUIIconView())
          ->setIcon('fa-list-ul'))
      ->setText(pht('View All'))
      ->setHref('/feed/?userPHIDs='.$user->getPHID());

    $feed_header = id(new PHUIHeaderView())
      ->setHeader(pht('Recent Activity'))
      ->addActionLink($view_all);

    $feed = id(new PHUIObjectBoxView())
      ->setHeader($feed_header)
      ->addClass('project-view-feed')
      ->appendChild($feed);

    $projects = $this->buildProjectsView($user);

    $home = id(new PHUITwoColumnView())
      ->setHeader($header)
      ->addClass('project-view-home')
      ->addClass('project-view-people-home')
      ->setMainColumn(
        array(
          $properties,
          $feed,
        ))
      ->setSideColumn(
        array(
          $projects,
        ));

    $navigation = $this->newNavigation(
      $user,
      PhabricatorPeopleProfileMenuEngine::ITEM_PROFILE);

    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->setBorder(true);

    return $this->newPage()
      ->setTitle($user->getUsername())
      ->setNavigation($navigation)
      ->setCrumbs($crumbs)
      ->setPageObjectPHIDs(
        array(
          $user->getPHID(),
        ))
      ->appendChild(
        array(
          $home,
        ));
  }

  private function buildPropertyView(
    PhabricatorUser $user) {

    $viewer = $this->getRequest()->getUser();
    $view = id(new PHUIPropertyListView())
      ->setUser($viewer)
      ->setObject($user);

    $field_list = PhabricatorCustomField::getObjectFields(
      $user,
      PhabricatorCustomField::ROLE_VIEW);
    $field_list->appendFieldsToPropertyList($user, $viewer, $view);

    if (!$view->hasAnyProperties()) {
      return null;
    }

    $header = id(new PHUIHeaderView())
      ->setHeader(pht('User Details'));

    $view = id(new PHUIObjectBoxView())
      ->appendChild($view)
      ->setHeader($header)
      ->setBackground(PHUIObjectBoxView::BLUE_PROPERTY)
      ->addClass('project-view-properties');

    return $view;
  }

  private function buildProjectsView(
    PhabricatorUser $user) {

    $viewer = $this->getViewer();
    $projects = id(new PhabricatorProjectQuery())
      ->setViewer($viewer)
      ->withMemberPHIDs(array($user->getPHID()))
      ->needImages(true)
      ->withStatuses(
        array(
          PhabricatorProjectStatus::STATUS_ACTIVE,
        ))
      ->execute();

    $header = id(new PHUIHeaderView())
      ->setHeader(pht('Projects'));

    if (!empty($projects)) {
      $limit = 5;
      $render_phids = array_slice($projects, 0, $limit);
      $list = id(new PhabricatorProjectListView())
        ->setUser($viewer)
        ->setProjects($render_phids);

      if (count($projects) > $limit) {
        $header_text = pht(
          'Projects (%s)',
          phutil_count($projects));

        $header = id(new PHUIHeaderView())
          ->setHeader($header_text)
          ->addActionLink(
            id(new PHUIButtonView())
              ->setTag('a')
              ->setIcon('fa-list-ul')
              ->setText(pht('View All'))
              ->setHref('/project/?member='.$user->getPHID()));

      }

    } else {
      $list = id(new PHUIInfoView())
        ->setSeverity(PHUIInfoView::SEVERITY_NODATA)
        ->appendChild(pht('User does not belong to any projects.'));
    }

    $box = id(new PHUIObjectBoxView())
      ->setHeader($header)
      ->appendChild($list)
      ->setBackground(PHUIObjectBoxView::BLUE_PROPERTY);

    return $box;
  }

  private function buildPeopleFeed(
    PhabricatorUser $user,
    $viewer) {

    $query = id(new PhabricatorFeedQuery())
      ->setViewer($viewer)
      ->withFilterPHIDs(array($user->getPHID()))
      ->setLimit(100)
      ->setReturnPartialResultsOnOverheat(true);

    $stories = $query->execute();

    $overheated_view = null;
    $is_overheated = $query->getIsOverheated();
    if ($is_overheated) {
      $overheated_message =
        PhabricatorApplicationSearchController::newOverheatedError(
          (bool)$stories);

      $overheated_view = id(new PHUIInfoView())
        ->setSeverity(PHUIInfoView::SEVERITY_WARNING)
        ->setTitle(pht('Query Overheated'))
        ->setErrors(
          array(
            $overheated_message,
          ));
    }

    $builder = new PhabricatorFeedBuilder($stories);
    $builder->setUser($viewer);
    $builder->setShowHovercards(true);
    $builder->setNoDataString(pht('To begin on such a grand journey, '.
      'requires but just a single step.'));
    $view = $builder->buildView();

    return array(
      $overheated_view,
      $view->render(),
    );
  }

}
