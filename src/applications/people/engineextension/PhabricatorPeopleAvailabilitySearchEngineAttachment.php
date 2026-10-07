<?php

final class PhabricatorPeopleAvailabilitySearchEngineAttachment
  extends PhabricatorSearchEngineAttachment {

  public function getAttachmentName() {
    return pht('User Availability');
  }

  public function getAttachmentDescription() {
    return pht('Get availability information for users.');
  }

  public function getAttachmentForObject($object, $data, $spec) {
    // Availability was computed from Calendar events. Calendar has been
    // removed from this install, so every user is reported as available.
    // The attachment is kept so existing API clients continue to work.
    return array(
      'value' => 'available',
      'until' => null,
      'name' => pht('Available'),
      'color' => 'green',
      'eventPHID' => null,
    );
  }

}
