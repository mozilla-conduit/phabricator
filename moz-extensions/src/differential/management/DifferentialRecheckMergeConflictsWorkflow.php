<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * Recomputes merge-conflict statuses from the command line.
 *
 * The status is normally maintained by `RevisionMergeConflictWorker` on the
 * edges where an answer can change, so this exists for backfilling revisions
 * that predate the field and for checking a specific revision while testing.
 */
final class DifferentialRecheckMergeConflictsWorkflow
  extends PhabricatorDifferentialManagementWorkflow {

  protected function didConstruct() {
    $this
      ->setName('recheck-merge-conflicts')
      ->setExamples(
        "**recheck-merge-conflicts** --revision __D123__\n".
        "**recheck-merge-conflicts** --all --repository __rREPO__")
      ->setSynopsis(
        pht(
          'Recompute the merge-conflict status of one or more revisions. '.
          'Revisions whose stored status was already computed from the same '.
          'diffs and the same branch tip are left alone.'))
      ->setArguments(
        array(
          array(
            'name' => 'revision',
            'param' => 'revision',
            'repeat' => true,
            'help' => pht('Revision to recheck. May be repeated.'),
          ),
          array(
            'name' => 'all',
            'help' => pht('Recheck every open revision.'),
          ),
          array(
            'name' => 'repository',
            'param' => 'repository',
            'help' => pht('With "--all", limit to one repository.'),
          ),
          array(
            'name' => 'background',
            'help' => pht(
              'Queue the checks for the taskmaster instead of running them '.
              'in this process.'),
          ),
        ));
  }

  public function execute(PhutilArgumentParser $args) {
    $viewer = $this->getViewer();

    if (!PhabricatorEnv::getEnvConfig(
        MergeConflictConfigOptions::OPTION_ENABLED)) {
      throw new PhutilArgumentUsageException(
        pht(
          'Merge conflict detection is disabled. Enable it with '.
          '`./bin/config set %s true`.',
          MergeConflictConfigOptions::OPTION_ENABLED));
    }

    // Checks are only written for repositories named in the list, so an empty
    // one would leave this workflow reporting work it silently discards.
    if (!PhabricatorEnv::getEnvConfig(
        MergeConflictConfigOptions::OPTION_REPOSITORIES)) {
      throw new PhutilArgumentUsageException(
        pht(
          'No repositories are configured for merge conflict detection. '.
          'Select them in the `%s` setting.',
          MergeConflictConfigOptions::OPTION_REPOSITORIES));
    }

    $revisions = $this->loadRevisions($args);
    if (!$revisions) {
      throw new PhutilArgumentUsageException(
        pht('No open revisions matched the arguments.'));
    }

    $is_background = $args->getArg('background');
    if (!$is_background) {
      PhabricatorWorker::setRunAllTasksInProcess(true);
    }

    echo tsprintf(
      "%s\n",
      pht(
        'Rechecking %s revision(s) and anything stacked above them.',
        phutil_count($revisions)));

    RevisionMergeConflictWorker::queueChecks(
      $viewer,
      mpull($revisions, 'getPHID'));

    if ($is_background) {
      echo tsprintf("%s\n", pht('Queued checks for the taskmaster.'));
      return 0;
    }

    foreach ($revisions as $revision) {
      $this->printStatus($revision);
    }

    return 0;
  }

  private function loadRevisions(PhutilArgumentParser $args): array {
    $viewer = $this->getViewer();

    $identifiers = $args->getArg('revision');
    $is_all = $args->getArg('all');
    $repository_identifier = $args->getArg('repository');

    if ($identifiers && $is_all) {
      throw new PhutilArgumentUsageException(
        pht('Specify either "--revision" or "--all", not both.'));
    }

    if (!$identifiers && !$is_all) {
      throw new PhutilArgumentUsageException(
        pht('Specify revisions with "--revision", or use "--all".'));
    }

    $query = id(new DifferentialRevisionQuery())
      ->setViewer($viewer)
      ->withIsOpen(true);

    if ($identifiers) {
      $ids = array();
      foreach ($identifiers as $identifier) {
        $ids[] = (int)ltrim($identifier, 'dD');
      }
      $query->withIDs($ids);
    }

    if ($repository_identifier) {
      $repository = id(new PhabricatorRepositoryQuery())
        ->setViewer($viewer)
        ->withIdentifiers(array($repository_identifier))
        ->executeOne();
      if (!$repository) {
        throw new PhutilArgumentUsageException(
          pht('No repository "%s" exists.', $repository_identifier));
      }
      $query->withRepositoryPHIDs(array($repository->getPHID()));
    }

    return $query->execute();
  }

  private function printStatus(DifferentialRevision $revision): void {
    $stored = id(new DifferentialMergeConflictStatusField())
      ->readStoredValueForObject($revision->getPHID());

    if (!is_array($stored)) {
      echo tsprintf(
        "    %s %s\n",
        $revision->getMonogram(),
        pht('(no status stored)'));
      return;
    }

    echo tsprintf(
      "    %s %s: %s\n",
      $revision->getMonogram(),
      idx($stored, DifferentialMergeConflictStatusField::KEY_STATUS),
      idx($stored, DifferentialMergeConflictStatusField::KEY_REASON));

    echo tsprintf("      %s\n", $this->newCheckSummary($stored));
  }

  /**
   * Summarizes what a stored verdict was computed from, so a backfill run shows
   * which answers are current and which ones were left alone.
   */
  private function newCheckSummary(array $stored): string {
    $epoch = idx($stored, DifferentialMergeConflictStatusField::KEY_EPOCH);
    if ($epoch) {
      $when = phabricator_datetime($epoch, $this->getViewer());
    } else {
      $when = pht('an unrecorded time');
    }

    $base = idx(
      $stored,
      DifferentialMergeConflictStatusField::KEY_BASE_COMMIT);
    if (!phutil_nonempty_string($base)) {
      // An `unknown` verdict may not have got as far as resolving a base.
      $base = pht('no resolved base');
    }

    return pht(
      'Diff %s, based on %s, checked at %s',
      idx($stored, DifferentialMergeConflictStatusField::KEY_DIFF_ID),
      $base,
      $when);
  }

}
