<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

final class DifferentialRevisionWarningTestCase extends PhabricatorTestCase {

  const AUTHOR_PHID = 'PHID-USER-author';

  public function testRevisionThatIsNotHeldHasNoHoldWarning() {
    $this->assertEqual(
      null,
      $this->renderHoldWarning($this->newDraft(), self::AUTHOR_PHID),
      pht('A revision that is not held should not show the hold banner.'));
  }

  public function testHeldRevisionExplainsTheWait() {
    $revision = $this->newDraft();
    ReviewHelperDraftHold::startHold($revision);

    $warning = $this->renderHoldWarning($revision, 'PHID-USER-reviewer');

    $this->assertTrue(
      strpos($warning, 'Review Helper is reviewing this revision.') !== false,
      pht(
        'A held revision should explain that Review Helper is reviewing '.
        'it.'));
    $this->assertFalse(
      strpos($warning, 'Publish Revision') !== false,
      pht('Only the author should be told they can publish the revision.'));
  }

  public function testAuthorIsToldHowToRespondToFindings() {
    $revision = $this->newDraft();
    ReviewHelperDraftHold::startHold($revision);
    ReviewHelperDraftHold::recordFindings($revision);

    $warning = $this->renderHoldWarning($revision, self::AUTHOR_PHID);

    $this->assertTrue(
      strpos($warning, 'Address the findings by updating the diff') !== false,
      pht('The author should be told how to respond to the findings.'));
    $this->assertTrue(
      strpos($warning, 'Publish Revision') !== false,
      pht('The author should be told they can publish the revision.'));
  }

  private function renderHoldWarning(
    DifferentialRevision $revision,
    string $viewer_phid): ?string {

    $viewer = id(new PhabricatorUser())->setPHID($viewer_phid);
    $warning = id(new DifferentialRevisionWarning())
      ->createAIReviewHoldWarning($viewer, $revision);
    if ($warning === null) {
      return null;
    }

    return phutil_escape_html($warning);
  }

  private function newDraft(): DifferentialRevision {
    return id(new DifferentialRevision())
      ->setAuthorPHID(self::AUTHOR_PHID)
      ->setModernRevisionStatus(DifferentialRevisionStatus::DRAFT)
      ->setShouldBroadcast(false);
  }

}
