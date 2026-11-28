<?php

final class DiffusionURIEditor
  extends PhabricatorApplicationTransactionEditor {

  private $repository;
  private $repositoryPHID;

  public function getEditorApplicationClass() {
    return 'PhabricatorDiffusionApplication';
  }

  public function getEditorObjectsDescription() {
    return pht('Diffusion URIs');
  }

  public function getTransactionTypes() {
    $types = parent::getTransactionTypes();

    $types[] = PhabricatorRepositoryURITransaction::TYPE_REPOSITORY;
    $types[] = PhabricatorRepositoryURITransaction::TYPE_URI;
    $types[] = PhabricatorRepositoryURITransaction::TYPE_IO;
    $types[] = PhabricatorRepositoryURITransaction::TYPE_DISPLAY;
    $types[] = PhabricatorRepositoryURITransaction::TYPE_DISABLE;

    return $types;
  }

  protected function getCustomTransactionOldValue(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {

    switch ($xaction->getTransactionType()) {
      case PhabricatorRepositoryURITransaction::TYPE_URI:
        return $object->getURI();
      case PhabricatorRepositoryURITransaction::TYPE_IO:
        return $object->getIOType();
      case PhabricatorRepositoryURITransaction::TYPE_DISPLAY:
        return $object->getDisplayType();
      case PhabricatorRepositoryURITransaction::TYPE_REPOSITORY:
        return $object->getRepositoryPHID();
      case PhabricatorRepositoryURITransaction::TYPE_DISABLE:
        return (int)$object->getIsDisabled();
    }

    return parent::getCustomTransactionOldValue($object, $xaction);
  }

  protected function getCustomTransactionNewValue(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {

    switch ($xaction->getTransactionType()) {
      case PhabricatorRepositoryURITransaction::TYPE_URI:
      case PhabricatorRepositoryURITransaction::TYPE_IO:
      case PhabricatorRepositoryURITransaction::TYPE_DISPLAY:
      case PhabricatorRepositoryURITransaction::TYPE_REPOSITORY:
        return $xaction->getNewValue();
      case PhabricatorRepositoryURITransaction::TYPE_DISABLE:
        return (int)$xaction->getNewValue();
    }

    return parent::getCustomTransactionNewValue($object, $xaction);
  }

  protected function applyCustomInternalTransaction(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {

    switch ($xaction->getTransactionType()) {
      case PhabricatorRepositoryURITransaction::TYPE_URI:
        if ($this->getIsNewObject()) {
          // When creating a URI via the API, we may not have processed the
          // repository transaction yet. Attach the repository here to make
          // sure we have it for the calls below.
          if ($this->repository) {
            $object->attachRepository($this->repository);
          }
        }

        $object->setURI($xaction->getNewValue());
        break;
      case PhabricatorRepositoryURITransaction::TYPE_IO:
        $object->setIOType($xaction->getNewValue());
        break;
      case PhabricatorRepositoryURITransaction::TYPE_DISPLAY:
        $object->setDisplayType($xaction->getNewValue());
        break;
      case PhabricatorRepositoryURITransaction::TYPE_REPOSITORY:
        $object->setRepositoryPHID($xaction->getNewValue());
        $object->attachRepository($this->repository);
        break;
      case PhabricatorRepositoryURITransaction::TYPE_DISABLE:
        $object->setIsDisabled($xaction->getNewValue());
        break;
    }
  }

  protected function applyCustomExternalTransaction(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {

    switch ($xaction->getTransactionType()) {
      case PhabricatorRepositoryURITransaction::TYPE_URI:
      case PhabricatorRepositoryURITransaction::TYPE_IO:
      case PhabricatorRepositoryURITransaction::TYPE_DISPLAY:
      case PhabricatorRepositoryURITransaction::TYPE_REPOSITORY:
      case PhabricatorRepositoryURITransaction::TYPE_DISABLE:
        return;
    }

    return parent::applyCustomExternalTransaction($object, $xaction);
  }

  protected function validateTransaction(
    PhabricatorLiskDAO $object,
    $type,
    array $xactions) {

    $errors = parent::validateTransaction($object, $type, $xactions);

    switch ($type) {
      case PhabricatorRepositoryURITransaction::TYPE_REPOSITORY:
        // Save this, since we need it to validate TYPE_IO transactions.
        $this->repositoryPHID = $object->getRepositoryPHID();

        $missing = $this->validateIsEmptyTextField(
          $object->getRepositoryPHID(),
          $xactions);
        if ($missing) {
          // NOTE: This isn't being marked as a missing field error because
          // it's a fundamental, required property of the URI.
          $errors[] = new PhabricatorApplicationTransactionValidationError(
            $type,
            pht('Required'),
            pht(
              'When creating a repository URI, you must specify which '.
              'repository the URI will belong to.'),
            nonempty(last($xactions), null));
          break;
        }

        $viewer = $this->getActor();

        foreach ($xactions as $xaction) {
          $repository_phid = $xaction->getNewValue();

          // If this isn't changing anything, let it through as-is.
          if ($repository_phid == $object->getRepositoryPHID()) {
            continue;
          }

          if (!$this->getIsNewObject()) {
            $errors[] = new PhabricatorApplicationTransactionValidationError(
              $type,
              pht('Invalid'),
              pht(
                'The repository a URI is associated with is immutable, and '.
                'can not be changed after the URI is created.'),
              $xaction);
            continue;
          }

          $repository = id(new PhabricatorRepositoryQuery())
            ->setViewer($viewer)
            ->withPHIDs(array($repository_phid))
            ->requireCapabilities(
              array(
                PhabricatorPolicyCapability::CAN_VIEW,
                PhabricatorPolicyCapability::CAN_EDIT,
              ))
            ->executeOne();
          if (!$repository) {
            $errors[] = new PhabricatorApplicationTransactionValidationError(
              $type,
              pht('Invalid'),
              pht(
                'To create a URI for a repository ("%s"), it must exist and '.
                'you must have permission to edit it.',
                $repository_phid),
              $xaction);
            continue;
          }

          $this->repository = $repository;
          $this->repositoryPHID = $repository_phid;
        }
        break;
      case PhabricatorRepositoryURITransaction::TYPE_URI:
        $missing = $this->validateIsEmptyTextField(
          $object->getURI(),
          $xactions);

        if ($missing) {
          $error = new PhabricatorApplicationTransactionValidationError(
            $type,
            pht('Required'),
            pht('A repository URI must have a nonempty URI.'),
            nonempty(last($xactions), null));

          $error->setIsMissingFieldError(true);
          $errors[] = $error;
          break;
        }

        foreach ($xactions as $xaction) {
          $new_uri = $xaction->getNewValue();
          if ($new_uri == $object->getURI()) {
            continue;
          }

          try {
            PhabricatorRepository::assertValidRemoteURI($new_uri);
          } catch (Exception $ex) {
            $errors[] = new PhabricatorApplicationTransactionValidationError(
              $type,
              pht('Invalid'),
              $ex->getMessage(),
              $xaction);
            continue;
          }
        }

        break;
      case PhabricatorRepositoryURITransaction::TYPE_IO:
        $available = $object->getAvailableIOTypeOptions();
        foreach ($xactions as $xaction) {
          $new = $xaction->getNewValue();

          if (empty($available[$new])) {
            $errors[] = new PhabricatorApplicationTransactionValidationError(
              $type,
              pht('Invalid'),
              pht(
                'Value "%s" is not a valid IO setting for this URI. '.
                'Available types for this URI are: %s.',
                $new,
                implode(', ', array_keys($available))),
              $xaction);
            continue;
          }

          // If we are setting this URI to use "Observe", we must have no
          // other "Observe" URIs.
          if ($new == PhabricatorRepositoryURI::IO_OBSERVE) {
            $repository = id(new PhabricatorRepositoryQuery())
              ->setViewer(PhabricatorUser::getOmnipotentUser())
              ->withPHIDs(array($this->repositoryPHID))
              ->needURIs(true)
              ->executeOne();
            $uris = $repository->getURIs();

            $observe_conflict = null;
            foreach ($uris as $uri) {
              // If this is the URI being edited, it can not conflict with
              // itself.
              if ($uri->getID() == $object->getID()) {
                continue;
              }

              $io_observe = PhabricatorRepositoryURI::IO_OBSERVE;
              if ($uri->getEffectiveIOType() == $io_observe) {
                $observe_conflict = $uri;
                break;
              }
            }

            if ($observe_conflict) {
              $errors[] = new PhabricatorApplicationTransactionValidationError(
                $type,
                pht('Invalid'),
                pht(
                  'You can not set this URI to use Observe IO because '.
                  'another URI for this repository is already configured '.
                  'in Observe IO mode. A repository can not observe two '.
                  'different remotes simultaneously. Turn off IO for the '.
                  'other URI first.'),
                $xaction);
              continue;
            }
          }
        }

        break;
      case PhabricatorRepositoryURITransaction::TYPE_DISPLAY:
        $available = $object->getAvailableDisplayTypeOptions();
        foreach ($xactions as $xaction) {
          $new = $xaction->getNewValue();

          if (empty($available[$new])) {
            $errors[] = new PhabricatorApplicationTransactionValidationError(
              $type,
              pht('Invalid'),
              pht(
                'Value "%s" is not a valid display setting for this URI. '.
                'Available types for this URI are: %s.',
                $new,
                implode(', ', array_keys($available))));
          }
        }
        break;
    }

    return $errors;
  }

  protected function applyFinalEffects(
    PhabricatorLiskDAO $object,
    array $xactions) {

    // Synchronize the repository state based on the presence of an "Observe"
    // URI.
    $repository = $object->getRepository();

    $uris = id(new PhabricatorRepositoryURIQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withRepositories(array($repository))
      ->execute();

    // Reattach the current URIs to the repository: we're going to rebuild
    // the index explicitly below, and want to include any changes made to
    // this URI in the index update.
    $repository->attachURIs($uris);

    $observe_uri = null;
    foreach ($uris as $uri) {
      if ($uri->getIoType() != PhabricatorRepositoryURI::IO_OBSERVE) {
        continue;
      }

      $observe_uri = $uri;
      break;
    }

    if ($observe_uri) {
      $repository
        ->setDetail('remote-uri', (string)$observe_uri->getEffectiveURI());
    } else {
      $repository
        ->setDetail('remote-uri', null);
    }

    $repository->save();

    // Explicitly update the URI index.
    $repository->updateURIIndex();

    $repository->writeStatusMessage(
      PhabricatorRepositoryStatusMessage::TYPE_NEEDS_UPDATE,
      null);

    return $xactions;
  }

}
