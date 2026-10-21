<?php

class SentryLoggerPlugin extends Phobject {

  public static function registerErrorHandler() {
    PhutilReadableSerializer::printableValue(null);
    PhutilErrorHandler::setErrorListener(
      array(__CLASS__, 'handleError'));
  }

  public static function parse_query_str($str) {
    # result array
    $arr = array();

    # split on outer delimiter
    $pairs = explode('&', $str);

    # loop through each pair
    foreach ($pairs as $i) {
      # split into name and value
      // A bare key like `flag` has no `=`, so its value is `null`.
      $parts = explode('=', $i, 2);
      $name = $parts[0];
      $value = idx($parts, 1);

      # if name already exists
      if( isset($arr[$name]) ) {
        # stick multiple values into an array
        if( is_array($arr[$name]) ) {
          $arr[$name][] = $value;
        }
        else {
          $arr[$name] = array($arr[$name], $value);
        }
      }
      # otherwise, simply stick it in a scalar
      else {
        $arr[$name] = $value;
      }
    }

    # return result array
    return $arr;
  }

  public static function generate_query_str($array) {
    $params = array();
    foreach ($array as $k => $v) {
      // `parse_query_str()` collects the values of a repeated key in a list.
      // Avoid `(array)` here, since it turns a bare key's `null` into `array()`.
      foreach (is_array($v) ? $v : array($v) as $value) {
        $params[] = urlencode($k) . '=' . urlencode($value ?? '');
      }
    }

    return implode('&', $params);
  }

  public static function filter_values($array, $fields_re = null) {
    if ($fields_re === null) {
      $fields_re = '/^(__csrf__|token)$/i';
    }

    if (is_array($array)) {
      foreach ($array as $key => $value) {
        // Mask the whole value of a matching key, so the values of a repeated
        // query key or a nested object are masked too.
        if (is_string($key) && preg_match($fields_re, $key)) {
          $array[$key] = '********';
        } else if (is_array($value)) {
          $array[$key] = self::filter_values($value, $fields_re);
        }
      }
    } else {
      if (preg_match($fields_re, $array)) {
        $array = '********';
      }
    }

    return $array;
  }

  public static function filter_headers($headers) {
    // Take the CSRF header's name from `AphrontRequest`, so it can't drift.
    $fields_re = sprintf(
      '/^(Cookie|%s)$/i',
      preg_quote(AphrontRequest::getCSRFHeaderName(), '/'));

    return self::filter_values($headers, $fields_re);
  }

  public static function handleError($event, $value, $metadata) {
    // PHP deprecation warnings are only logged, and would flood Sentry.
    if ($event === PhutilErrorHandler::DEPRECATED) {
      return;
    }

    $sentry_dsn = PhabricatorEnv::getEnvConfigIfExists('sentry.dsn');

    if (empty($sentry_dsn)) {
      return;
    }

    $root = phutil_get_library_root('phabricator');
    $root = dirname($root);
    require_once $root . '/externals/extensions/autoload.php';

    $before_send = function(Sentry\Event $event) {
      $request = $event->getRequest();

      // `RequestIntegration` leaves out the parts a request doesn't have.

      // Sanitize HTTP POST data
      if (isset($request['data'])) {
        $request['data'] = self::filter_values($request['data']);

        if (is_array($request['data']) &&
            is_string(idx($request['data'], 'params'))) {
          try {
            $params = phutil_json_decode($request['data']['params']);
            $params = self::filter_values($params);
            $request['data']['params'] = phutil_json_encode($params);
          } catch (PhutilJSONParserException $ex) {
            // data['params'] wasn't JSON, so we're finished sanitizing the request data.
          }
        }
      }

      // Sanitize query string. The URL includes it too, unsanitized, so drop
      // it there and leave it to `query_string`.
      if (isset($request['url'])) {
        $request['url'] = head(explode('?', $request['url'], 2));
      }
      if (isset($request['query_string'])) {
        $query_data = self::parse_query_str($request['query_string']);
        $query_data = self::filter_values($query_data);
        $request['query_string'] = self::generate_query_str($query_data);
      }

      // Sanitize cookie data
      if (isset($request['cookies'])) {
        $request['cookies']['phsid'] = '********';
      }

      // Sanitize header data
      if (isset($request['headers'])) {
        $request['headers'] = self::filter_headers($request['headers']);
      }

      $event->setRequest($request);
      return $event;
    };

    // Configure the client. The default integrations would also install
    // Sentry's own PHP error and exception handlers, but errors reach Sentry
    // through `PhutilErrorHandler`, which calls this.
    Sentry\init(array(
      'dsn' => $sentry_dsn,
      'before_send' => $before_send,
      'default_integrations' => false,
      'integrations' => array(
        new Sentry\Integration\RequestIntegration(),
        new Sentry\Integration\EnvironmentIntegration(),
        new Sentry\Integration\FrameContextifierIntegration(),
      ),
    ));

    switch ($event) {
      case PhutilErrorHandler::EXCEPTION:
        // $value is of type Exception
        Sentry\captureException($value);
        break;
      case PhutilErrorHandler::ERROR:
        // $value is a simple string
        Sentry\captureMessage($value);
        break;
      default:
        error_log(pht('Unknown event: %s', $event));
        break;
    }
  }
}

