<?php

abstract class DiffusionCommandEngine extends Phobject {

  private $repository;
  private $protocol;
  private $argv;
  private $passthru;
  private $connectAsDevice;
  private $sudoAsDaemon;
  private $uri;

  public static function newCommandEngine(PhabricatorRepository $repository) {
    $engines = self::newCommandEngines();

    foreach ($engines as $engine) {
      if ($engine->canBuildForRepository($repository)) {
        return id(clone $engine)
          ->setRepository($repository);
      }
    }

    throw new Exception(
      pht(
        'No registered command engine can build commands for this '.
        'repository ("%s").',
        $repository->getDisplayName()));
  }

  private static function newCommandEngines() {
    return id(new PhutilClassMapQuery())
      ->setAncestorClass(__CLASS__)
      ->execute();
  }

  abstract protected function canBuildForRepository(
    PhabricatorRepository $repository);

  abstract protected function newFormattedCommand($pattern, array $argv);
  abstract protected function newCustomEnvironment();

  public function setRepository(PhabricatorRepository $repository) {
    $this->repository = $repository;
    return $this;
  }

  public function getRepository() {
    return $this->repository;
  }

  public function setURI(PhutilURI $uri) {
    $this->uri = $uri;
    $this->setProtocol($uri->getProtocol());
    return $this;
  }

  public function getURI() {
    return $this->uri;
  }

  public function setProtocol($protocol) {
    $this->protocol = $protocol;
    return $this;
  }

  public function getProtocol() {
    return $this->protocol;
  }

  public function getDisplayProtocol() {
    return $this->getProtocol().'://';
  }

  public function setArgv(array $argv) {
    $this->argv = $argv;
    return $this;
  }

  public function getArgv() {
    return $this->argv;
  }

  public function setPassthru($passthru) {
    $this->passthru = $passthru;
    return $this;
  }

  public function getPassthru() {
    return $this->passthru;
  }

  public function setConnectAsDevice($connect_as_device) {
    $this->connectAsDevice = $connect_as_device;
    return $this;
  }

  public function getConnectAsDevice() {
    return $this->connectAsDevice;
  }

  public function setSudoAsDaemon($sudo_as_daemon) {
    $this->sudoAsDaemon = $sudo_as_daemon;
    return $this;
  }

  public function getSudoAsDaemon() {
    return $this->sudoAsDaemon;
  }

  protected function shouldAlwaysSudo() {
    return false;
  }

  public function newFuture() {
    $argv = $this->newCommandArgv();
    $env = $this->newCommandEnvironment();
    $is_passthru = $this->getPassthru();

    if ($this->getSudoAsDaemon() || $this->shouldAlwaysSudo()) {
      $command = call_user_func_array('csprintf', $argv);
      $command = PhabricatorDaemon::sudoCommandAsDaemonUser($command);
      $argv = array('%C', $command);
    }

    if ($is_passthru) {
      $future = newv('PhutilExecPassthru', $argv);
    } else {
      $future = newv('ExecFuture', $argv);
    }

    $future->setEnv($env);

    // See T13108. By default, don't let any cluster command run indefinitely
    // to try to avoid cases where `git fetch` hangs for some reason and we're
    // left sitting with a held lock forever.
    $repository = $this->getRepository();
    if (!$is_passthru) {
      $future->setTimeout($repository->getEffectiveCopyTimeLimit());
    }

    return $future;
  }

  private function newCommandArgv() {
    $argv = $this->argv;
    $pattern = $argv[0];
    $argv = array_slice($argv, 1);

    list($pattern, $argv) = $this->newFormattedCommand($pattern, $argv);

    return array_merge(array($pattern), $argv);
  }

  private function newCommandEnvironment() {
    $env = $this->newCommonEnvironment() + $this->newCustomEnvironment();
    foreach ($env as $key => $value) {
      if ($value === null) {
        unset($env[$key]);
      }
    }
    return $env;
  }

  private function newCommonEnvironment() {
    $repository = $this->getRepository();

    $env = array();
      // NOTE: Force the language to "en_US.UTF-8", which overrides locale
      // settings. This makes stuff print in English instead of, e.g., French,
      // so we can parse the output of some commands, error messages, etc.
    $env['LANG'] = 'en_US.UTF-8';

      // Propagate PHABRICATOR_ENV explicitly. For discussion, see T4155.
    $env['PHABRICATOR_ENV'] = PhabricatorEnv::getSelectedEnvironmentName();

    $as_device = $this->getConnectAsDevice();

    if ($as_device) {
      $device = AlmanacKeys::getLiveDevice();
      if (!$device) {
        throw new Exception(
          pht(
            'Attempting to build a repository command (for repository "%s") '.
            'as device, but this host ("%s") is not configured as a cluster '.
            'device.',
            $repository->getDisplayName(),
            php_uname('n')));
      }
    }


    if ($this->isAnySSHProtocol()) {
      if ($as_device) {
        $env['PHABRICATOR_AS_DEVICE'] = 1;
      }
    }

    $env += $repository->getPassthroughEnvironmentalVariables();

    return $env;
  }

  public function isSSHProtocol() {
    return ($this->getProtocol() == 'ssh');
  }

  public function isHTTPProtocol() {
    return ($this->getProtocol() == 'http');
  }

  public function isHTTPSProtocol() {
    return ($this->getProtocol() == 'https');
  }

  public function isAnyHTTPProtocol() {
    return ($this->isHTTPProtocol() || $this->isHTTPSProtocol());
  }

  public function isAnySSHProtocol() {
    return $this->isSSHProtocol();
  }

  protected function getSSHWrapper() {
    $root = dirname(phutil_get_library_root('phabricator'));
    return $root.'/bin/ssh-connect';
  }

}
