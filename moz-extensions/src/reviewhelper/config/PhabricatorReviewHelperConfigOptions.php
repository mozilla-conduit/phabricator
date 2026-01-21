<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

final class PhabricatorReviewHelperConfigOptions
extends PhabricatorApplicationConfigOptions {

  public function getName() {
    return pht('Review Helper');
  }

  public function getDescription() {
    return pht('Configure AI Review Helper settings.');
  }

  public function getIcon() {
    return 'fa-magic';
  }

  public function getGroup() {
    return 'apps';
  }

  public function getOptions() {
    return array(
      $this->newOption(
        'reviewhelper.url',
        'string',
        ''
      )
        ->setDescription(pht('Full URL for the AI review service endpoint. ' .
          'Set to an empty string to disable the feature.')),
      $this->newOption(
        'reviewhelper.auth-key',
        'string',
        ''
      )
        ->setDescription(pht('Authentication key for the AI review service. ' .
          'This will be sent as a Bearer token in the Authorization header.')),
      $this->newOption(
        'reviewhelper.timeout',
        'int',
        30
      )
        ->setDescription(pht('Request timeout in seconds for the AI review service.')),
      $this->newOption(
        'reviewhelper.bot-username',
        'string',
        ''
      )
        ->setDescription(pht('Username of the bot account that posts AI review comments. ' .
          'Feedback buttons will appear on inline comments from this user.')),
    );
  }

  protected function didValidateOption(PhabricatorConfigOption $option, $value) {
    $key = $option->getKey();
    if ($key === 'reviewhelper.url') {
      $this->validateServiceURL($value);
    } elseif ($key === 'reviewhelper.bot-username') {
      $this->validateBotUsername($value);
    }
  }

  private function validateServiceURL($value) {
    if ($value === '' || $value === null) {
      return;
    }

    try {
      PhabricatorEnv::requireValidRemoteURIForFetch(
        $value,
        array('https')
      );
    } catch (Exception $ex) {
      throw new PhabricatorConfigValidationException(
        pht(
          'The Review Helper URL "%s" is not valid: %s',
          $value,
          $ex->getMessage()
        )
      );
    }

    $auth_key = PhabricatorEnv::getEnvConfig('reviewhelper.auth-key');
    if ($auth_key === '' || $auth_key === null) {
      throw new PhabricatorConfigValidationException(
        pht(
          'The Review Helper authentication key must be set before a Review Helper URL is configured.'
        )
      );
    }
  }

  private function validateBotUsername($value) {
    if ($value === '' || $value === null) {
      return;
    }

    $user = id(new PhabricatorUser())->loadOneWhere(
      'userName = %s',
      $value
    );

    if (!$user) {
      throw new PhabricatorConfigValidationException(
        pht(
          'The bot username "%s" does not match any existing Phabricator user.',
          $value
        )
      );
    }
  }
}
