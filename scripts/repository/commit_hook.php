#!/usr/bin/env php
<?php

// NOTE: This script will sometimes emit a warning like this on startup:
//
//   No entry for terminal type "unknown";
//   using dumb terminal settings.
//
// This can be fixed by adding "TERM=dumb" to the shebang line, but doing so
// causes some systems to hang mysteriously. See T7119.

// Commit hooks execute in an unusual context where the environment may be
// unavailable. The first parameter to this script is
// either a bare repository identifier ("X"), or a repository identifier
// followed by an instance identifier ("X:instance"). If we have an instance
// identifier, unpack it into the environment before we start up. This allows
// subclasses of PhabricatorConfigSiteSource to read it and build an instance
// environment.

$hook_start = microtime(true);

if ($argc > 1) {
  $context = $argv[1];
  $context = explode(':', $context, 2);
  $argv[1] = $context[0];

  if (count($context) > 1) {
    $_ENV['PHABRICATOR_INSTANCE'] = $context[1];
    putenv('PHABRICATOR_INSTANCE='.$context[1]);
  }
}

$root = dirname(dirname(dirname(__FILE__)));
require_once $root.'/scripts/__init_script__.php';

if ($argc < 2) {
  throw new Exception(pht('usage: commit-hook <repository>'));
}

$engine = id(new DiffusionCommitHookEngine())
  ->setStartTime($hook_start);

$repository = id(new PhabricatorRepositoryQuery())
  ->setViewer(PhabricatorUser::getOmnipotentUser())
  ->withIdentifiers(array($argv[1]))
  ->needProjectPHIDs(true)
  ->executeOne();

if (!$repository) {
  throw new Exception(pht('No such repository "%s"!', $argv[1]));
}

if (!$repository->isHosted()) {
  // In Mercurial, the "pretxnchangegroup" hook fires for both pulls and
  // pushes. Normally we only install the hook for hosted repositories, but
  // if a hosted repository is later converted into an observed repository we
  // can end up with an observed repository that has the hook installed.
  // If we're running hooks from an observed repository, just exit without
  // taking action. For more discussion, see PHI24.
  return 0;
}

$engine->setRepository($repository);

// Figure out which user is writing the commit.
if ($repository->isGit() || $repository->isHg()) {
  $username = getenv(DiffusionCommitHookEngine::ENV_USER);
  if (!phutil_nonempty_string($username)) {
    throw new Exception(
      pht(
        'No Direct Pushes: You are pushing directly to a hosted repository. '.
        'This will not work. See "No Direct Pushes" in the documentation '.
        'for more information.'));
  }

  if ($repository->isHg()) {
    // We respond to several different hooks in Mercurial.
    $engine->setMercurialHook($argv[2]);
  }

} else {
  throw new Exception(pht('Unknown repository type.'));
}

$user = id(new PhabricatorPeopleQuery())
  ->setViewer(PhabricatorUser::getOmnipotentUser())
  ->withUsernames(array($username))
  ->executeOne();

if (!$user) {
  throw new Exception(pht('No such user "%s"!', $username));
}

$engine->setViewer($user);


// Read stdin for the hook engine.

if ($repository->isHg()) {
  // Mercurial leaves stdin open, so we can't just read it until EOF.
  $stdin = '';
} else {
  // Git writes data into stdin and then closes it. Read the data.
  $stdin = @file_get_contents('php://stdin');
  if ($stdin === false) {
    throw new Exception(pht('Failed to read stdin!'));
  }
}

$engine->setStdin($stdin);
$engine->setOriginalArgv(array_slice($argv, 2));

$remote_address = getenv(DiffusionCommitHookEngine::ENV_REMOTE_ADDRESS);
if (phutil_nonempty_string($remote_address)) {
  $engine->setRemoteAddress($remote_address);
}

$remote_protocol = getenv(DiffusionCommitHookEngine::ENV_REMOTE_PROTOCOL);
if (phutil_nonempty_string($remote_protocol)) {
  $engine->setRemoteProtocol($remote_protocol);
}

$request_identifier = getenv(DiffusionCommitHookEngine::ENV_REQUEST);
if (phutil_nonempty_string($request_identifier)) {
  $engine->setRequestIdentifier($request_identifier);
}

try {
  $err = $engine->execute();
} catch (DiffusionCommitHookRejectException $ex) {
  $console = PhutilConsole::getConsole();

  if (PhabricatorEnv::getEnvConfig('phabricator.serious-business')) {
    $preamble = pht('*** PUSH REJECTED BY COMMIT HOOK ***');
  } else {
    $preamble = pht(<<<EOTXT
+---------------------------------------------------------------+
|      * * * PUSH REJECTED BY EVIL DRAGON BUREAUCRATS * * *     |
+---------------------------------------------------------------+
             \
              \                    ^    /^
               \                  / \  // \
                \   |\___/|      /   \//  .\
                 \  /V  V  \__  /    //  | \ \           *----*
                   /     /  \/_/    //   |  \  \          \   |
                   @___@`    \/_   //    |   \   \         \/\ \
                  0/0/|       \/_ //     |    \    \         \  \
              0/0/0/0/|        \///      |     \     \       |  |
           0/0/0/0/0/_|_ /   (  //       |      \     _\     |  /
        0/0/0/0/0/0/`/,_ _ _/  ) ; -.    |    _ _\.-~       /   /
                    ,-}        _      *-.|.-~-.           .~    ~
  *     \__/         `/\      /                 ~-. _ .-~      /
   \____(Oo)            *.   }            {                   /
   (    (..)           .----~-.\        \-`                 .~
   //___\\\\  \ DENIED!  ///.----..<        \             _ -~
  //     \\\\                ///-._ _ _ _ _ _ _{^ - - - - ~

EOTXT
);
  }

  $console->writeErr("%s\n\n", $preamble);
  $console->writeErr("%s\n\n", $ex->getMessage());
  $err = 1;
}

exit($err);
