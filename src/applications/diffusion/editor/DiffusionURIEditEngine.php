<?php

final class DiffusionURIEditEngine
  extends PhabricatorEditEngine {

  const ENGINECONST = 'diffusion.uri';

  private $repository;

  public function setRepository(PhabricatorRepository $repository) {
    $this->repository = $repository;
    return $this;
  }

  public function getRepository() {
    return $this->repository;
  }

  public function isEngineConfigurable() {
    return false;
  }

  public function getEngineName() {
    return pht('Repository URIs');
  }

  public function getSummaryHeader() {
    return pht('Edit Repository URI');
  }

  public function getSummaryText() {
    return pht('Creates and edits repository URIs.');
  }

  public function getEngineApplicationClass() {
    return 'PhabricatorDiffusionApplication';
  }

  protected function newEditableObject() {
    $uri = PhabricatorRepositoryURI::initializeNewURI();

    $repository = $this->getRepository();
    if ($repository) {
      $uri->setRepositoryPHID($repository->getPHID());
      $uri->attachRepository($repository);
    }

    return $uri;
  }

  protected function newObjectQuery() {
    return new PhabricatorRepositoryURIQuery();
  }

  protected function getObjectCreateTitleText($object) {
    return pht('Create Repository URI');
  }

  protected function getObjectCreateButtonText($object) {
    return pht('Create Repository URI');
  }

  protected function getObjectEditTitleText($object) {
    return pht('Edit Repository URI %d', $object->getID());
  }

  protected function getObjectEditShortText($object) {
    return pht('URI %d', $object->getID());
  }

  protected function getObjectCreateShortText() {
    return pht('Create Repository URI');
  }

  protected function getObjectName() {
    return pht('Repository URI');
  }

  protected function getObjectViewURI($object) {
    return $object->getViewURI();
  }

  protected function buildCustomEditFields($object) {
    $viewer = $this->getViewer();

    $uri_instructions = null;
    $uri_value = $object->getURI();

    if ($object->getRepositoryPHID()) {
      $repository = $object->getRepository();
      if ($repository->isGit()) {
        $uri_instructions = pht(
          "Provide the URI of a Git repository. It should usually look ".
          "like one of these examples:\n".
          "\n".
          "| Example Git URIs\n".
          "| -----------------------\n".
          "| `git@github.com:example/example.git`\n".
          "| `ssh://user@host.com/git/example.git`\n".
          "| `https://example.com/repository.git`");
      } else if ($repository->isHg()) {
        $uri_instructions = pht(
          "Provide the URI of a Mercurial repository. It should usually ".
          "look like one of these examples:\n".
          "\n".
          "| Example Mercurial URIs\n".
          "|-----------------------\n".
          "| `ssh://hg@bitbucket.org/example/repository`\n".
          "| `https://bitbucket.org/example/repository`");
      }
    }

    return array(
      id(new PhabricatorHandlesEditField())
        ->setKey('repository')
        ->setAliases(array('repositoryPHID'))
        ->setLabel(pht('Repository'))
        ->setIsRequired(true)
        ->setIsFormField(false)
        ->setTransactionType(
          PhabricatorRepositoryURITransaction::TYPE_REPOSITORY)
        ->setDescription(pht('The repository this URI is associated with.'))
        ->setConduitDescription(
          pht(
            'Create a URI in a given repository. This transaction type '.
            'must be present when creating a new URI and must not be '.
            'present when editing an existing URI.'))
        ->setConduitTypeDescription(
          pht('Repository PHID to create a new URI for.'))
        ->setSingleValue($object->getRepositoryPHID()),
      id(new PhabricatorTextEditField())
        ->setKey('uri')
        ->setLabel(pht('URI'))
        ->setTransactionType(PhabricatorRepositoryURITransaction::TYPE_URI)
        ->setDescription(pht('The repository URI.'))
        ->setConduitDescription(pht('Change the repository URI.'))
        ->setConduitTypeDescription(pht('New repository URI.'))
        ->setIsRequired(true)
        ->setValue($uri_value)
        ->setControlInstructions($uri_instructions),
      id(new PhabricatorSelectEditField())
        ->setKey('io')
        ->setLabel(pht('I/O Type'))
        ->setTransactionType(PhabricatorRepositoryURITransaction::TYPE_IO)
        ->setDescription(pht('URI I/O behavior.'))
        ->setConduitDescription(pht('Adjust I/O behavior.'))
        ->setConduitTypeDescription(pht('New I/O behavior.'))
        ->setValue($object->getIOType())
        ->setOptions($object->getAvailableIOTypeOptions()),
      id(new PhabricatorSelectEditField())
        ->setKey('display')
        ->setLabel(pht('Display Type'))
        ->setTransactionType(PhabricatorRepositoryURITransaction::TYPE_DISPLAY)
        ->setDescription(pht('URI display behavior.'))
        ->setConduitDescription(pht('Change display behavior.'))
        ->setConduitTypeDescription(pht('New display behavior.'))
        ->setValue($object->getDisplayType())
        ->setOptions($object->getAvailableDisplayTypeOptions()),
      id(new PhabricatorBoolEditField())
        ->setKey('disable')
        ->setLabel(pht('Disabled'))
        ->setIsFormField(false)
        ->setTransactionType(PhabricatorRepositoryURITransaction::TYPE_DISABLE)
        ->setDescription(pht('Active status of the URI.'))
        ->setConduitDescription(pht('Disable or activate the URI.'))
        ->setConduitTypeDescription(pht('True to disable the URI.'))
        ->setOptions(pht('Enable'), pht('Disable'))
        ->setValue($object->getIsDisabled()),
    );
  }

}
