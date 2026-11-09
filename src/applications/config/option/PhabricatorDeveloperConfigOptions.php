<?php

final class PhabricatorDeveloperConfigOptions
  extends PhabricatorApplicationConfigOptions {

  public function getName() {
    return pht('Developer / Debugging');
  }

  public function getDescription() {
    return pht('Options for platform developers, including debugging.');
  }

  public function getIcon() {
    return 'fa-bug';
  }

  public function getGroup() {
    return 'core';
  }

  public function getOptions() {
    return array(
      $this->newOption('debug.time-limit', 'int', null)
        ->setSummary(
          pht(
            'Limit page execution time to debug hangs.'))
        ->setDescription(
          pht(
            "This option can help debug pages which are taking a very ".
            "long time (more than 30 seconds) to render.\n\n".
            "If a page is slow to render (but taking less than 30 seconds), ".
            "the best tool to use to figure out why it is slow is usually ".
            "a profiler.\n\n".
            "However, if a request takes a very long time to return, some ".
            "components (like Apache, nginx, or PHP itself) may abort the ".
            "request before it finishes. This can prevent you from using ".
            "profiling tools to understand page performance in detail.\n\n".
            "In these cases, you can use this option to force the page to ".
            "abort after a smaller number of seconds (for example, 10), and ".
            "dump a useful stack trace. This can provide useful information ".
            "about why a page is hanging.\n\n".
            "To use this option, set it to a small number (like 10), and ".
            "reload a hanging page. The page should exit after 10 seconds ".
            "and give you a stack trace.\n\n".
            "You should turn this option off (set it to 0) when you are ".
            "done with it. Leaving it on creates a small amount of overhead ".
            "for all requests, even if they do not hit the time limit.")),
      $this->newOption('debug.stop-on-redirect', 'bool', false)
        ->setBoolOptions(
          array(
            pht('Stop Before HTTP Redirect'),
            pht('Use Normal HTTP Redirects'),
          ))
        ->setSummary(
          pht(
            'Confirm before redirecting, to debug POST handling.'))
        ->setDescription(
          pht(
            'Normally, this software issues HTTP redirects after a successful '.
            'POST. This can make it difficult to debug things which happen '.
            'while processing the POST, because service and profiling '.
            'information are lost. By setting this configuration option, '.
            'an interstitial page will be shown instead of automatically '.
            'redirecting, allowing you to examine service and profiling '.
            'information. It also makes the UX awful, so you should only '.
            'enable it when debugging.')),
      $this->newOption('phabricator.developer-mode', 'bool', false)
        ->setBoolOptions(
          array(
            pht('Enable developer mode'),
            pht('Disable developer mode'),
          ))
        ->setSummary(pht('Enable verbose error reporting and disk reads.'))
        ->setDescription(
          pht(
            'This option enables verbose error reporting (stack traces, '.
            'error callouts) and forces disk reads of static assets on '.
            'every reload.')),
    );
  }
}
