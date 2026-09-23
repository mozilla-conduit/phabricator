<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

final class MergeConflictConfigOptions
  extends PhabricatorApplicationConfigOptions {

  const OPTION_ENABLED = 'merge.conflict.enabled';
  const OPTION_REPOSITORIES = 'merge.conflict.repository-phids';

  public function getName() {
    return pht('Merge Conflict Detection');
  }

  public function getDescription() {
    return pht(
      'Configure whether revisions are checked for merge conflicts against '.
      'their target branch.');
  }

  public function getIcon() {
    return 'fa-code-fork';
  }

  public function getGroup() {
    return 'apps';
  }

  public function getOptions() {
    return array(
      $this->newOption(self::OPTION_ENABLED, 'bool', false)
        ->setSummary(pht('Enable merge conflict detection.'))
        ->setDescription(
          pht(
            'Whether revisions are checked for merge conflicts against their '.
            'target branch. When disabled, no checks are scheduled and any '.
            'work already in the queue is discarded, so this is safe to turn '.
            'off at any time. Statuses already stored are left alone and will '.
            'be recomputed when it is turned back on.')),
      $this->newOption(
        self::OPTION_REPOSITORIES,
        'custom:PhabricatorRepositoryListConfigType',
        array())
        ->setSummary(pht('Check merge conflicts on these repositories.'))
        ->setDescription(
          pht(
            'Repositories to check. Nothing is checked while this is empty, '.
            'so a repository is only ever checked once it is named here. '.
            'Has no effect unless `%s` is also on, so a staged rollout means '.
            'enabling that option with a single repository selected here. '.
            'Only Git repositories are checked, since the check performs a '.
            'real `git` merge.',
            self::OPTION_ENABLED)),
    );
  }

}
