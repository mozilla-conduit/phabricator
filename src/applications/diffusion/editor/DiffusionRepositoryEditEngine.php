<?php

final class DiffusionRepositoryEditEngine
  extends PhabricatorEditEngine {

  const ENGINECONST = 'diffusion.repository';

  private $versionControlSystem;

  public function setVersionControlSystem($version_control_system) {
    $this->versionControlSystem = $version_control_system;
    return $this;
  }

  public function getVersionControlSystem() {
    return $this->versionControlSystem;
  }

  public function isEngineConfigurable() {
    return false;
  }

  public function isDefaultQuickCreateEngine() {
    return true;
  }

  public function getQuickCreateOrderVector() {
    return id(new PhutilSortVector())->addInt(300);
  }

  public function getEngineName() {
    return pht('Repositories');
  }

  public function getSummaryHeader() {
    return pht('Edit Repositories');
  }

  public function getSummaryText() {
    return pht('Creates and edits repositories.');
  }

  public function getEngineApplicationClass() {
    return 'PhabricatorDiffusionApplication';
  }

  protected function newEditableObject() {
    $viewer = $this->getViewer();
    $repository = PhabricatorRepository::initializeNewRepository($viewer);

    $repository->setDetail('newly-initialized', true);

    $vcs = $this->getVersionControlSystem();
    if ($vcs) {
      $repository->setVersionControlSystem($vcs);
    }

    // Pick a random open service to allocate this repository on, if any exist.
    // If there are no services, we aren't in cluster mode and will allocate
    // locally. If there are services but none permit allocations, we fail.

    // Eventually we can make this more flexible, but this rule is a reasonable
    // starting point as we begin to deploy cluster services.

    $services = id(new AlmanacServiceQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withServiceTypes(
        array(
          AlmanacClusterRepositoryServiceType::SERVICETYPE,
        ))
      ->needProperties(true)
      ->execute();
    if ($services) {
      // Filter out services which do not permit new allocations.
      foreach ($services as $key => $possible_service) {
        if ($possible_service->getAlmanacPropertyValue('closed')) {
          unset($services[$key]);
        }
      }

      if (!$services) {
        throw new Exception(
          pht(
            'This install is configured in cluster mode, but all available '.
            'repository cluster services are closed to new allocations. '.
            'At least one service must be open to allow new allocations to '.
            'take place.'));
      }

      shuffle($services);
      $service = head($services);

      $repository->setAlmanacServicePHID($service->getPHID());
    }

    return $repository;
  }

  protected function newObjectQuery() {
    return new PhabricatorRepositoryQuery();
  }

  protected function getObjectCreateTitleText($object) {
    return pht('Create Repository');
  }

  protected function getObjectCreateButtonText($object) {
    return pht('Create Repository');
  }

  protected function getObjectEditTitleText($object) {
    return pht('Edit Repository: %s', $object->getName());
  }

  protected function getObjectEditShortText($object) {
    return $object->getDisplayName();
  }

  protected function getObjectCreateShortText() {
    return pht('Create Repository');
  }

  protected function getObjectName() {
    return pht('Repository');
  }

  protected function getObjectViewURI($object) {
    return $object->getPathURI('manage/');
  }

  protected function getCreateNewObjectPolicy() {
    return $this->getApplication()->getPolicy(
      DiffusionCreateRepositoriesCapability::CAPABILITY);
  }

  protected function newPages($object) {
    $panels = DiffusionRepositoryManagementPanel::getAllPanels();

    $pages = array();
    $uris = array();
    foreach ($panels as $panel_key => $panel) {
      $panel->setRepository($object);

      $uris[$panel_key] = $panel->getPanelURI();

      $page = $panel->newEditEnginePage();
      if (!$page) {
        continue;
      }
      $pages[] = $page;
    }

    $basics_key = DiffusionRepositoryBasicsManagementPanel::PANELKEY;
    $basics_uri = $uris[$basics_key];

    $more_pages = array(
      id(new PhabricatorEditPage())
        ->setKey('encoding')
        ->setLabel(pht('Text Encoding'))
        ->setViewURI($basics_uri)
        ->setFieldKeys(
          array(
            'encoding',
          )),
      id(new PhabricatorEditPage())
        ->setKey('extensions')
        ->setLabel(pht('Extensions'))
        ->setIsDefault(true),
    );

    foreach ($more_pages as $page) {
      $pages[] = $page;
    }

    return $pages;
  }

  protected function willConfigureFields($object, array $fields) {
    // Change the default field order so related fields are adjacent.
    $after = array(
      'policy.edit' => array('policy.push'),
    );

    $result = array();
    foreach ($fields as $key => $value) {
      $result[$key] = $value;

      if (!isset($after[$key])) {
        continue;
      }

      foreach ($after[$key] as $next_key) {
        if (!isset($fields[$next_key])) {
          continue;
        }

        unset($result[$next_key]);
        $result[$next_key] = $fields[$next_key];
        unset($fields[$next_key]);
      }
    }

    return $result;
  }


  protected function buildCustomEditFields($object) {
    $viewer = $this->getViewer();

    $policies = id(new PhabricatorPolicyQuery())
      ->setViewer($viewer)
      ->setObject($object)
      ->execute();

    $fetch_value = $object->getFetchRules();
    $track_value = $object->getTrackOnlyRules();
    $permanent_value = $object->getPermanentRefRules();

    $staging_instructions = pht(
      "To make it easier to run integration tests and builds on code ".
      "under review, you can configure a **Staging Area**. When `arc` ".
      "creates a diff, it will push a copy of the changes to the ".
      "configured staging area with a corresponding tag.".
      "\n\n".
      "IMPORTANT: This feature is new, experimental, and not supported. ".
      "Use it at your own risk.");

    $track_instructions = pht(
      'WARNING: The "Track Only" feature is deprecated. Use "Fetch Refs" '.
      'and "Permanent Refs" instead. This feature will be removed in a '.
      'future version of this software.');

    return array(
      id(new PhabricatorSelectEditField())
        ->setKey('vcs')
        ->setLabel(pht('Version Control System'))
        ->setTransactionType(
          PhabricatorRepositoryVCSTransaction::TRANSACTIONTYPE)
        ->setIsFormField(false)
        ->setIsCopyable(true)
        ->setOptions(PhabricatorRepositoryType::getAllRepositoryTypes())
        ->setDescription(pht('Underlying repository version control system.'))
        ->setConduitDescription(
          pht(
            'Choose which version control system to use when creating a '.
            'repository.'))
        ->setConduitTypeDescription(pht('Version control system selection.'))
        ->setValue($object->getVersionControlSystem()),
      id(new PhabricatorTextEditField())
        ->setKey('name')
        ->setLabel(pht('Name'))
        ->setIsRequired(true)
        ->setTransactionType(
          PhabricatorRepositoryNameTransaction::TRANSACTIONTYPE)
        ->setDescription(pht('The repository name.'))
        ->setConduitDescription(pht('Rename the repository.'))
        ->setConduitTypeDescription(pht('New repository name.'))
        ->setValue($object->getName()),
      id(new PhabricatorTextEditField())
        ->setKey('callsign')
        ->setLabel(pht('Callsign'))
        ->setTransactionType(
          PhabricatorRepositoryCallsignTransaction::TRANSACTIONTYPE)
        ->setDescription(pht('The repository callsign.'))
        ->setConduitDescription(pht('Change the repository callsign.'))
        ->setConduitTypeDescription(pht('New repository callsign.'))
        ->setValue($object->getCallsign()),
      id(new PhabricatorTextEditField())
        ->setKey('shortName')
        ->setLabel(pht('Short Name'))
        ->setTransactionType(
          PhabricatorRepositorySlugTransaction::TRANSACTIONTYPE)
        ->setDescription(pht('Short, unique repository name.'))
        ->setConduitDescription(pht('Change the repository short name.'))
        ->setConduitTypeDescription(pht('New short name for the repository.'))
        ->setValue($object->getRepositorySlug()),
      id(new PhabricatorRemarkupEditField())
        ->setKey('description')
        ->setLabel(pht('Description'))
        ->setTransactionType(
          PhabricatorRepositoryDescriptionTransaction::TRANSACTIONTYPE)
        ->setDescription(pht('Repository description.'))
        ->setConduitDescription(pht('Change the repository description.'))
        ->setConduitTypeDescription(pht('New repository description.'))
        ->setValue($object->getDetail('description')),
      id(new PhabricatorTextEditField())
        ->setKey('encoding')
        ->setLabel(pht('Text Encoding'))
        ->setIsCopyable(true)
        ->setTransactionType(
          PhabricatorRepositoryEncodingTransaction::TRANSACTIONTYPE)
        ->setDescription(pht('Default text encoding.'))
        ->setConduitDescription(pht('Change the default text encoding.'))
        ->setConduitTypeDescription(pht('New text encoding.'))
        ->setValue($object->getDetail('encoding')),
      id(new PhabricatorSelectEditField())
        ->setKey('status')
        ->setLabel(pht('Status'))
        ->setTransactionType(
          PhabricatorRepositoryActivateTransaction::TRANSACTIONTYPE)
        ->setIsFormField(false)
        ->setOptions(PhabricatorRepository::getStatusNameMap())
        ->setDescription(pht('Active or inactive status.'))
        ->setConduitDescription(pht('Active or deactivate the repository.'))
        ->setConduitTypeDescription(pht('New repository status.'))
        ->setValue($object->getStatus()),
      id(new PhabricatorTextEditField())
        ->setKey('defaultBranch')
        ->setLabel(pht('Default Branch'))
        ->setTransactionType(
          PhabricatorRepositoryDefaultBranchTransaction::TRANSACTIONTYPE)
        ->setIsCopyable(true)
        ->setDescription(pht('Default branch name.'))
        ->setConduitDescription(pht('Set the default branch name.'))
        ->setConduitTypeDescription(pht('New default branch name.'))
        ->setValue($object->getDetail('default-branch')),
      id(new PhabricatorTextAreaEditField())
        ->setIsStringList(true)
        ->setKey('fetchRefs')
        ->setLabel(pht('Fetch Refs'))
        ->setTransactionType(
          PhabricatorRepositoryFetchRefsTransaction::TRANSACTIONTYPE)
        ->setIsCopyable(true)
        ->setDescription(pht('Fetch only these refs.'))
        ->setConduitDescription(pht('Set the fetched refs.'))
        ->setConduitTypeDescription(pht('New fetched refs.'))
        ->setValue($fetch_value),
      id(new PhabricatorTextAreaEditField())
        ->setIsStringList(true)
        ->setKey('permanentRefs')
        ->setLabel(pht('Permanent Refs'))
        ->setTransactionType(
          PhabricatorRepositoryPermanentRefsTransaction::TRANSACTIONTYPE)
        ->setIsCopyable(true)
        ->setDescription(pht('Only these refs are considered permanent.'))
        ->setConduitDescription(pht('Set the permanent refs.'))
        ->setConduitTypeDescription(pht('New permanent ref rules.'))
        ->setValue($permanent_value),
      id(new PhabricatorTextAreaEditField())
        ->setIsStringList(true)
        ->setKey('trackOnly')
        ->setLabel(pht('Track Only'))
        ->setTransactionType(
          PhabricatorRepositoryTrackOnlyTransaction::TRANSACTIONTYPE)
        ->setIsCopyable(true)
        ->setControlInstructions($track_instructions)
        ->setDescription(pht('Track only these branches.'))
        ->setConduitDescription(pht('Set the tracked branches.'))
        ->setConduitTypeDescription(pht('New tracked branches.'))
        ->setValue($track_value),
      id(new PhabricatorTextEditField())
        ->setKey('stagingAreaURI')
        ->setLabel(pht('Staging Area URI'))
        ->setTransactionType(
          PhabricatorRepositoryStagingURITransaction::TRANSACTIONTYPE)
        ->setIsCopyable(true)
        ->setDescription(pht('Staging area URI.'))
        ->setConduitDescription(pht('Set the staging area URI.'))
        ->setConduitTypeDescription(pht('New staging area URI.'))
        ->setValue($object->getStagingURI())
        ->setControlInstructions($staging_instructions),
      id(new PhabricatorStringListEditField())
        ->setKey('symbolLanguages')
        ->setLabel(pht('Languages'))
        ->setTransactionType(
          PhabricatorRepositorySymbolLanguagesTransaction::TRANSACTIONTYPE)
        ->setIsCopyable(true)
        ->setDescription(
          pht('Languages which define symbols in this repository.'))
        ->setConduitDescription(
          pht('Change symbol languages for this repository.'))
        ->setConduitTypeDescription(
          pht('New symbol languages.'))
        ->setValue($object->getSymbolLanguages()),
      id(new PhabricatorDatasourceEditField())
        ->setKey('symbolRepositoryPHIDs')
        ->setLabel(pht('Uses Symbols From'))
        ->setTransactionType(
          PhabricatorRepositorySymbolSourcesTransaction::TRANSACTIONTYPE)
        ->setIsCopyable(true)
        ->setDatasource(new DiffusionRepositoryDatasource())
        ->setDescription(pht('Repositories to link symbols from.'))
        ->setConduitDescription(pht('Change symbol source repositories.'))
        ->setConduitTypeDescription(pht('New symbol repositories.'))
        ->setValue($object->getSymbolSources()),
      id(new PhabricatorBoolEditField())
        ->setKey('publish')
        ->setLabel(pht('Publish/Notify'))
        ->setTransactionType(
          PhabricatorRepositoryNotifyTransaction::TRANSACTIONTYPE)
        ->setIsCopyable(true)
        ->setOptions(
          pht('Disable Notifications, Feed, and Herald'),
          pht('Enable Notifications, Feed, and Herald'))
        ->setDescription(pht('Configure how changes are published.'))
        ->setConduitDescription(pht('Change publishing options.'))
        ->setConduitTypeDescription(pht('New notification setting.'))
        ->setValue(!$object->isPublishingDisabled()),
      id(new PhabricatorTextEditField())
        ->setKey('copyTimeLimit')
        ->setLabel(pht('Clone/Fetch Timeout'))
        ->setTransactionType(
          PhabricatorRepositoryCopyTimeLimitTransaction::TRANSACTIONTYPE)
        ->setDescription(
          pht('Maximum permitted duration of internal clone/fetch.'))
        ->setConduitDescription(pht('Change the copy time limit.'))
        ->setConduitTypeDescription(pht('New repository copy time limit.'))
        ->setValue($object->getCopyTimeLimit()),
    );
  }

}
