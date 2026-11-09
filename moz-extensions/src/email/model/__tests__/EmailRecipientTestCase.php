<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

final class EmailRecipientTestCase extends PhabricatorTestCase {

  public function testTimezoneOffset() {
    $recipient = $this->assertNoDeprecations(
      function () {
        return new EmailRecipient(
          'user@example.com',
          'user',
          new DateTimeZone('Asia/Kolkata'),
          false);
      },
      pht('Creating an `EmailRecipient` should not raise deprecation '.
        'warnings.'));

    $this->assertEqual(
      19800,
      $recipient->timezoneOffset,
      pht('The offset should be the timezone\'s offset from UTC in seconds.'));
  }

}
