<?php

final class PhutilDefaultSyntaxHighlighterEnginePygmentsFuture
  extends FutureProxy {

  protected function didReceiveResult($result) {
    list($err, $stdout, $stderr) = $result;

    if (!$err && strlen($stdout)) {
      // Strip off fluff Pygments adds.
      $stdout = preg_replace(
        '@^<div class="highlight"><pre>(.*)</pre></div>\s*$@s',
        '\1',
        $stdout);
      return phutil_safe_html($stdout);
    }

    throw new PhutilSyntaxHighlighterException($stderr, $err);
  }

}
