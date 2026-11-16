<?php

/**
 * Manages repository synchronization for cluster repositories.
 *
 * @task config Configuring Synchronization
 * @task sync Cluster Synchronization
 * @task internal Internals
 */
final class DiffusionRepositoryClusterEngine extends Phobject {

  private $repository;
  private $viewer;
  private $actingAsPHID;
  private $logger;


/* -(  Configuring Synchronization  )---------------------------------------- */


  public function setRepository(PhabricatorRepository $repository) {
    $this->repository = $repository;
    return $this;
  }

  public function getRepository() {
    return $this->repository;
  }

  public function setViewer(PhabricatorUser $viewer) {
    $this->viewer = $viewer;
    return $this;
  }

  public function getViewer() {
    return $this->viewer;
  }

  public function setLog(DiffusionRepositoryClusterEngineLogInterface $log) {
    $this->logger = $log;
    return $this;
  }

  public function setActingAsPHID($acting_as_phid) {
    $this->actingAsPHID = $acting_as_phid;
    return $this;
  }

  public function getActingAsPHID() {
    return $this->actingAsPHID;
  }


/* -(  Cluster Synchronization  )-------------------------------------------- */


  /**
   * Synchronize repository version information after creating a repository.
   *
   * This initializes working copy versions for all currently bound devices to
   * 0, so that we don't get stuck making an ambiguous choice about which
   * devices are leaders when we later synchronize before a read.
   *
   * @task sync
   */
  public function synchronizeWorkingCopyAfterCreation() {
    if (!$this->shouldEnableSynchronization(false)) {
      return;
    }

    $repository = $this->getRepository();
    $repository_phid = $repository->getPHID();

    $service = $repository->loadAlmanacService();
    if (!$service) {
      throw new Exception(pht('Failed to load repository cluster service.'));
    }

    $bindings = $service->getActiveBindings();
    foreach ($bindings as $binding) {
      PhabricatorRepositoryWorkingCopyVersion::updateVersion(
        $repository_phid,
        $binding->getDevicePHID(),
        0);
    }

    return $this;
  }


  /**
   * @task sync
   */
  public function synchronizeWorkingCopyAfterHostingChange() {
    if (!$this->shouldEnableSynchronization(false)) {
      return;
    }

    $repository = $this->getRepository();
    $repository_phid = $repository->getPHID();

    $versions = PhabricatorRepositoryWorkingCopyVersion::loadVersions(
      $repository_phid);
    $versions = mpull($versions, null, 'getDevicePHID');

    // After converting a hosted repository to observed, or vice versa, we
    // need to reset version numbers because the clocks for observed and hosted
    // repositories run on different units.

    // We identify all the cluster leaders and reset their version to 0.
    // We identify all the cluster followers and demote them.

    // This allows the cluster to start over again at version 0 but keep the
    // same leaders.

    if ($versions) {
      $max_version = (int)max(mpull($versions, 'getRepositoryVersion'));
      foreach ($versions as $version) {
        $device_phid = $version->getDevicePHID();

        if ($version->getRepositoryVersion() == $max_version) {
          PhabricatorRepositoryWorkingCopyVersion::updateVersion(
            $repository_phid,
            $device_phid,
            0);
        } else {
          PhabricatorRepositoryWorkingCopyVersion::demoteDevice(
            $repository_phid,
            $device_phid);
        }
      }
    }

    return $this;
  }


  /**
   * @task sync
   */
  public function synchronizeWorkingCopyBeforeRead() {
    if (!$this->shouldEnableSynchronization(true)) {
      return;
    }

    $repository = $this->getRepository();
    $repository_phid = $repository->getPHID();

    $device = AlmanacKeys::getLiveDevice();
    $device_phid = $device->getPHID();

    $read_lock = PhabricatorRepositoryWorkingCopyVersion::getReadLock(
      $repository_phid,
      $device_phid);

    $lock_wait = phutil_units('2 minutes in seconds');

    $this->logLine(
      pht(
        'Acquiring read lock for repository "%s" on device "%s"...',
        $repository->getDisplayName(),
        $device->getName()));

    try {
      $start = PhabricatorTime::getNow();
      $read_lock->lock($lock_wait);
      $waited = (PhabricatorTime::getNow() - $start);

      if ($waited) {
        $this->logLine(
          pht(
            'Acquired read lock after %s second(s).',
            new PhutilNumber($waited)));
      } else {
        $this->logLine(
          pht(
            'Acquired read lock immediately.'));
      }
    } catch (PhutilLockException $ex) {
      throw new PhutilProxyException(
        pht(
          'Failed to acquire read lock after waiting %s second(s). You '.
          'may be able to retry later. (%s)',
          new PhutilNumber($lock_wait),
          $ex->getHint()),
        $ex);
    }

    $versions = PhabricatorRepositoryWorkingCopyVersion::loadVersions(
      $repository_phid);
    $versions = mpull($versions, null, 'getDevicePHID');

    $this_version = idx($versions, $device_phid);
    if ($this_version) {
      $this_version = (int)$this_version->getRepositoryVersion();
    } else {
      $this_version = null;
    }

    if ($versions) {
      // This is the normal case, where we have some version information and
      // can identify which nodes are leaders. If the current node is not a
      // leader, we want to fetch from a leader and then update our version.

      $max_version = (int)max(mpull($versions, 'getRepositoryVersion'));
      if (($this_version === null) || ($max_version > $this_version)) {
        if ($repository->isHosted()) {
          $fetchable = array();
          foreach ($versions as $version) {
            if ($version->getRepositoryVersion() == $max_version) {
              $fetchable[] = $version->getDevicePHID();
            }
          }


          $this->synchronizeWorkingCopyFromDevices(
            $fetchable,
            $this_version,
            $max_version);
        } else {
          $this->synchronizeWorkingCopyFromRemote();
        }

        PhabricatorRepositoryWorkingCopyVersion::updateVersion(
          $repository_phid,
          $device_phid,
          $max_version);
      } else {
        $this->logLine(
          pht(
            'Device "%s" is already a cluster leader and does not need '.
            'to be synchronized.',
            $device->getName()));
      }

      $result_version = $max_version;
    } else {
      // If no version records exist yet, we need to be careful, because we
      // can not tell which nodes are leaders.

      // There might be several nodes with arbitrary existing data, and we have
      // no way to tell which one has the "right" data. If we pick wrong, we
      // might erase some or all of the data in the repository.

      // Since this is dangerous, we refuse to guess unless there is only one
      // device. If we're the only device in the group, we obviously must be
      // a leader.

      $service = $repository->loadAlmanacService();
      if (!$service) {
        throw new Exception(pht('Failed to load repository cluster service.'));
      }

      $bindings = $service->getActiveBindings();
      $device_map = array();
      foreach ($bindings as $binding) {
        $device_map[$binding->getDevicePHID()] = true;
      }

      if (count($device_map) > 1) {
        throw new Exception(
          pht(
            'Repository "%s" exists on more than one device, but no device '.
            'has any repository version information. There is no way for the '.
            'software to determine which copy of the existing data is '.
            'authoritative. Promote a device or see "Ambiguous Leaders" in '.
            'the documentation.',
            $repository->getDisplayName()));
      }

      if (empty($device_map[$device->getPHID()])) {
        throw new Exception(
          pht(
            'Repository "%s" is being synchronized on device "%s", but '.
            'this device is not bound to the corresponding cluster '.
            'service ("%s").',
            $repository->getDisplayName(),
            $device->getName(),
            $service->getName()));
      }

      // The current device is the only device in service, so it must be a
      // leader. We can safely have any future nodes which come online read
      // from it.
      PhabricatorRepositoryWorkingCopyVersion::updateVersion(
        $repository_phid,
        $device_phid,
        0);

      $result_version = 0;
    }

    $read_lock->unlock();

    return $result_version;
  }



  public function synchronizeWorkingCopyAfterDiscovery($new_version) {
    if (!$this->shouldEnableSynchronization(true)) {
      return;
    }

    $repository = $this->getRepository();
    $repository_phid = $repository->getPHID();
    if ($repository->isHosted()) {
      return;
    }

    $device = AlmanacKeys::getLiveDevice();
    $device_phid = $device->getPHID();

    // NOTE: We are not holding a lock here because this method is only called
    // from PhabricatorRepositoryDiscoveryEngine, which already holds a device
    // lock. Even if we do race here and record an older version, the
    // consequences are mild: we only do extra work to correct it later.

    $versions = PhabricatorRepositoryWorkingCopyVersion::loadVersions(
      $repository_phid);
    $versions = mpull($versions, null, 'getDevicePHID');

    $this_version = idx($versions, $device_phid);
    if ($this_version) {
      $this_version = (int)$this_version->getRepositoryVersion();
    } else {
      $this_version = null;
    }

    if (($this_version === null) || ($new_version > $this_version)) {
      PhabricatorRepositoryWorkingCopyVersion::updateVersion(
        $repository_phid,
        $device_phid,
        $new_version);
    }
  }



/* -(  Internals  )---------------------------------------------------------- */


  /**
   * @task internal
   */
  private function shouldEnableSynchronization($require_device) {
    $repository = $this->getRepository();

    $service_phid = $repository->getAlmanacServicePHID();
    if (!$service_phid) {
      return false;
    }

    if (!$repository->supportsSynchronization()) {
      return false;
    }

    if ($require_device) {
      $device = AlmanacKeys::getLiveDevice();
      if (!$device) {
        return false;
      }
    }

    return true;
  }


  /**
   * @task internal
   */
  private function synchronizeWorkingCopyFromRemote() {
    $repository = $this->getRepository();
    $device = AlmanacKeys::getLiveDevice();

    $local_path = $repository->getLocalPath();
    $fetch_uri = $repository->getRemoteURIEnvelope();

    if ($repository->isGit()) {
      $this->requireWorkingCopy();

      $argv = array(
        'fetch --prune -- %P %s',
        $fetch_uri,
        '+refs/*:refs/*',
      );
    } else {
      throw new Exception(pht('Remote sync only supported for git!'));
    }

    $future = DiffusionCommandEngine::newCommandEngine($repository)
      ->setArgv($argv)
      ->setSudoAsDaemon(true)
      ->setCredentialPHID($repository->getCredentialPHID())
      ->setURI($repository->getRemoteURIObject())
      ->newFuture();

    $future->setCWD($local_path);

    try {
      $future->resolvex();
    } catch (Exception $ex) {
      $this->logLine(
        pht(
          'Synchronization of "%s" from remote failed: %s',
          $device->getName(),
          $ex->getMessage()));
      throw $ex;
    }
  }


  /**
   * @task internal
   */
  private function synchronizeWorkingCopyFromDevices(
    array $device_phids,
    $local_version,
    $remote_version) {

    $repository = $this->getRepository();

    $service = $repository->loadAlmanacService();
    if (!$service) {
      throw new Exception(pht('Failed to load repository cluster service.'));
    }

    $device_map = array_fuse($device_phids);
    $bindings = $service->getActiveBindings();

    $fetchable = array();
    foreach ($bindings as $binding) {
      // We can't fetch from nodes which don't have the newest version.
      $device_phid = $binding->getDevicePHID();
      if (empty($device_map[$device_phid])) {
        continue;
      }

      // TODO: For now, only fetch over SSH. We could support fetching over
      // HTTP eventually.
      if ($binding->getAlmanacPropertyValue('protocol') != 'ssh') {
        continue;
      }

      $fetchable[] = $binding;
    }

    if (!$fetchable) {
      throw new Exception(
        pht(
          'Leader lost: no up-to-date nodes in repository cluster are '.
          'fetchable.'));
    }

    // If we can synchronize from multiple sources, choose one at random.
    shuffle($fetchable);

    $caught = null;
    foreach ($fetchable as $binding) {
      try {
        $this->synchronizeWorkingCopyFromBinding(
          $binding,
          $local_version,
          $remote_version);
        $caught = null;
        break;
      } catch (Exception $ex) {
        $caught = $ex;
      }
    }

    if ($caught) {
      throw $caught;
    }
  }


  /**
   * @task internal
   */
  private function synchronizeWorkingCopyFromBinding(
    AlmanacBinding $binding,
    $local_version,
    $remote_version) {

    $repository = $this->getRepository();
    $device = AlmanacKeys::getLiveDevice();

    $this->logLine(
      pht(
        'Synchronizing this device ("%s") from cluster leader ("%s").',
        $device->getName(),
        $binding->getDevice()->getName()));

    $fetch_uri = $repository->getClusterRepositoryURIFromBinding($binding);
    $local_path = $repository->getLocalPath();

    if ($repository->isGit()) {
      $this->requireWorkingCopy();

      $argv = array(
        'fetch --prune -- %s %s',
        $fetch_uri,
        '+refs/*:refs/*',
      );
    } else {
      throw new Exception(pht('Binding sync only supported for git!'));
    }

    $future = DiffusionCommandEngine::newCommandEngine($repository)
      ->setArgv($argv)
      ->setConnectAsDevice(true)
      ->setSudoAsDaemon(true)
      ->setURI($fetch_uri)
      ->newFuture();

    $future->setCWD($local_path);

    $log = PhabricatorRepositorySyncEvent::initializeNewEvent()
      ->setRepositoryPHID($repository->getPHID())
      ->setEpoch(PhabricatorTime::getNow())
      ->setDevicePHID($device->getPHID())
      ->setFromDevicePHID($binding->getDevice()->getPHID())
      ->setDeviceVersion($local_version)
      ->setFromDeviceVersion($remote_version);

    $sync_start = microtime(true);

    try {
      $future->resolvex();
    } catch (Exception $ex) {
      $log->setSyncWait(phutil_microseconds_since($sync_start));

      if ($ex instanceof CommandException) {
        if ($future->getWasKilledByTimeout()) {
          $result_type = PhabricatorRepositorySyncEvent::RESULT_TIMEOUT;
        } else {
          $result_type = PhabricatorRepositorySyncEvent::RESULT_ERROR;
        }

       $log
         ->setResultCode($ex->getError())
         ->setResultType($result_type)
         ->setProperty('stdout', $ex->getStdout())
         ->setProperty('stderr', $ex->getStderr());
      } else {
        $log
          ->setResultCode(1)
          ->setResultType(PhabricatorRepositorySyncEvent::RESULT_EXCEPTION)
          ->setProperty('message', $ex->getMessage());
      }

      $log->save();

      $this->logLine(
        pht(
          'Synchronization of "%s" from leader "%s" failed: %s',
          $device->getName(),
          $binding->getDevice()->getName(),
          $ex->getMessage()));

      throw $ex;
    }

    $log
      ->setSyncWait(phutil_microseconds_since($sync_start))
      ->setResultCode(0)
      ->setResultType(PhabricatorRepositorySyncEvent::RESULT_SYNC)
      ->save();
  }


  /**
   * @task internal
   */
  private function logLine($message) {
    return $this->logText("# {$message}\n");
  }


  /**
   * @task internal
   */
  private function logText($message) {
    $log = $this->logger;
    if ($log) {
      $log->writeClusterEngineLogMessage($message);
    }
    return $this;
  }

  private function requireWorkingCopy() {
    $repository = $this->getRepository();
    $local_path = $repository->getLocalPath();

    if (!Filesystem::pathExists($local_path)) {
      $device = AlmanacKeys::getLiveDevice();

      throw new Exception(
        pht(
          'Repository "%s" does not have a working copy on this device '.
          'yet, so it can not be synchronized. Wait for the daemons to '.
          'construct one or run `bin/repository update %s` on this host '.
          '("%s") to build it explicitly.',
          $repository->getDisplayName(),
          $repository->getMonogram(),
          $device->getName()));
    }
  }

}
