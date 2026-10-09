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

}
