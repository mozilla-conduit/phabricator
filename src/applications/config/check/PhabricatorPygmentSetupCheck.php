<?php

final class PhabricatorPygmentSetupCheck extends PhabricatorSetupCheck {

  public function getDefaultGroup() {
    return self::GROUP_OTHER;
  }

  protected function executeChecks() {
    $pygment = PhabricatorEnv::getEnvConfig('pygments.enabled');

    if ($pygment) {
      if (!Filesystem::binaryExists('pygmentize')) {
        $summary = pht(
          'You enabled pygments but the %s script is not '.
          'actually available, your %s is probably broken.',
          'pygmentize',
          '$PATH');

        $message = pht(
          'The environmental variable %s does not contain %s. '.
          'You have enabled pygments, which requires '.
          '%s to be available in your %s variable.',
          '$PATH',
          'pygmentize',
          'pygmentize',
          '$PATH');

        $this
          ->newIssue('pygments.enabled')
          ->setName(pht('%s Not Found', 'pygmentize'))
          ->setSummary($summary)
          ->setMessage($message)
          ->addRelatedPhabricatorConfig('pygments.enabled')
          ->addPhabricatorConfig('environment.append-paths');
      } else {
        list($err) = exec_manual('pygmentize -h');
        if ($err) {
          $summary = pht(
            'You have enabled pygments and the %s script is '.
            'available, but does not seem to work.',
            'pygmentize');

          $message = pht(
            'This server has %s available in %s, but the binary '.
            'exited with an error code when run as %s. Check that it is '.
            'installed correctly.',
            phutil_tag('tt', array(), 'pygmentize'),
            phutil_tag('tt', array(), '$PATH'),
            phutil_tag('tt', array(), 'pygmentize -h'));

          $this
            ->newIssue('pygments.failed')
            ->setName(pht('%s Not Working', 'pygmentize'))
            ->setSummary($summary)
            ->setMessage($message)
            ->addRelatedPhabricatorConfig('pygments.enabled')
            ->addPhabricatorConfig('environment.append-paths');
        }
      }
    } else {
      $summary = pht(
        'Pygments is disabled, so source code is shown without syntax '.
        'highlighting.');

      $message = pht(
        'This software uses Pygments (a third-party highlighting tool) to '.
        'syntax highlight source code. While %s is disabled, source code is '.
        'shown as plain text.'."\n\n".
        'For instructions on installing and enabling Pygments, see the '.
        '%s configuration option.',
        phutil_tag('tt', array(), 'pygments.enabled'),
        phutil_tag('tt', array(), 'pygments.enabled'));

      $this
        ->newIssue('pygments.noenabled')
        ->setName(pht('Pygments Is Disabled'))
        ->setSummary($summary)
        ->setMessage($message)
        ->addRelatedPhabricatorConfig('pygments.enabled');
    }
  }
}
