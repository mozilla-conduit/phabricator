<?php

final class SentryLoggerPluginTestCase extends PhabricatorTestCase {

  public function testQueryStringRoundTrip() {
    $query = 'a=1&b=2';

    $this->assertEqual(
      $query,
      SentryLoggerPlugin::generate_query_str(
        SentryLoggerPlugin::parse_query_str($query)),
      pht('A query string without repeated keys should survive a round trip.'));
  }

  public function testQueryStringWithRepeatedKey() {
    $params = SentryLoggerPlugin::parse_query_str('id=1&id=2&b=3');

    $this->assertEqual(
      array('1', '2'),
      $params['id'],
      pht('`parse_query_str()` should collect the values of a repeated key.'));
    $this->assertEqual(
      'id=1&id=2&b=3',
      SentryLoggerPlugin::generate_query_str($params),
      pht('`generate_query_str()` should repeat the key for each value.'));
  }

  public function testParseQueryStringWithBareKey() {
    $this->assertEqual(
      array('flag' => null, 'a' => '1'),
      SentryLoggerPlugin::parse_query_str('flag&a=1'),
      pht('`parse_query_str()` should give a bare key a `null` value.'));
  }

  public function testQueryStringWithBareKey() {
    $this->assertEqual(
      'flag=&a=1',
      SentryLoggerPlugin::generate_query_str(
        SentryLoggerPlugin::parse_query_str('flag&a=1')),
      pht('A bare key without a value should be kept as `flag=`.'));
  }

  public function testFilterValuesMasksRepeatedKey() {
    $params = SentryLoggerPlugin::filter_values(
      SentryLoggerPlugin::parse_query_str('token=a&token=b&id=1&id=2'));

    $this->assertEqual(
      'token=%2A%2A%2A%2A%2A%2A%2A%2A&id=1&id=2',
      SentryLoggerPlugin::generate_query_str($params),
      pht('`filter_values()` should mask every value of a repeated `token` key.'));
  }

  public function testFilterValuesMasksNestedValues() {
    $this->assertEqual(
      array(
        'token' => '********',
        'nested' => array('__csrf__' => '********', 'id' => 1),
      ),
      SentryLoggerPlugin::filter_values(
        array(
          'token' => array('a' => 1),
          'nested' => array('__csrf__' => 'x', 'id' => 1),
        )),
      pht('`filter_values()` should mask matching keys at any depth.'));
  }

  public function testFilterHeadersMasksCSRFHeaderAndCookie() {
    // Sentry sends each header as a list of values.
    $this->assertEqual(
      array(
        'X-Phabricator-Csrf' => '********',
        'cookie' => '********',
        'Accept' => array('*/*'),
      ),
      SentryLoggerPlugin::filter_headers(
        array(
          'X-Phabricator-Csrf' => array('secret'),
          'cookie' => array('phsid=secret'),
          'Accept' => array('*/*'),
        )),
      pht('`filter_headers()` should mask the CSRF header and cookies.'));
  }

}
