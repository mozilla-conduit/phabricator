<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * Submits a new draft revision once phab-bot has finished setting it up,
 * publishing it for review unless a hold applies.
 *
 * phab-bot sends this as `submit-draft` instead of `request-review` when it
 * first sees a revision, in the same edit that sets the revision's view
 * policy. The decision is made after that policy is applied (see
 * `resolveSubmissions`), so holds can depend on the final visibility and on
 * the repository without phab-bot knowing about them. Today the only hold is
 * the AI review hold (see `ReviewHelperDraftHold`).
 *
 * It has no effect on a revision that is already published or that the author
 * is holding as a draft, so it never fails phab-bot's edit. This is only
 * available through Conduit and doesn't appear in the revision's action menu.
 */
final class DifferentialSubmitDraftTransaction
  extends DifferentialRevisionActionTransaction {

  const TRANSACTIONTYPE = 'differential.revision.submit';
  const ACTIONKEY = 'submit-draft';

  const METADATA_HELD = 'reviewhelper.held';

  public function isActionAvailable($object, PhabricatorUser $viewer) {
    // Never offer this in the action menu. Edits still run `validateAction`,
    // and skipping it here saves a project query on every revision page.
    return false;
  }

  protected function getRevisionActionLabel(
    DifferentialRevision $revision,
    PhabricatorUser $viewer) {
    return null;
  }

  public function generateOldValue($object) {
    // Report "already submitted" for anything we wouldn't publish, so the
    // transaction is dropped as having no effect.
    return !$object->isDraft() ||
      $object->getShouldBroadcast() ||
      $object->getHoldAsDraft();
  }

  public function generateNewValue($object, $value) {
    return true;
  }

  public function applyInternalEffects($object, $value) {
    // The decision waits for `resolveSubmissions`, so it sees the view policy
    // phab-bot sets in the same edit whatever order the transactions are in.
    return;
  }

  /**
   * Holds or publishes the revision for any submission in an edit, checking
   * each hold that applies to new revisions. The editor calls this once every
   * transaction's internal effects have been applied, before the revision and
   * its transactions are saved.
   */
  public static function resolveSubmissions(
    DifferentialRevision $revision,
    array $xactions): void {

    foreach ($xactions as $xaction) {
      if ($xaction->getTransactionType() !== self::TRANSACTIONTYPE) {
        continue;
      }

      if (ReviewHelperDraftHold::shouldHoldRevision($revision)) {
        ReviewHelperDraftHold::startHold($revision);
        $xaction->setMetadataValue(self::METADATA_HELD, true);
        continue;
      }

      $revision
        ->setModernRevisionStatus(DifferentialRevisionStatus::NEEDS_REVIEW)
        ->setShouldBroadcast(true);
    }
  }

  public function didCommitTransaction($object, $value) {
    if (!$this->isHeld()) {
      return;
    }

    ReviewHelperHoldRequestWorker::queueRequest($object);
    ReviewHelperHoldSweepWorker::ensureTrigger();
  }

  protected function validateAction($object, PhabricatorUser $viewer) {
    // Match "Request Review": phab-bot is allowed through by being in the
    // `secure-revision` project.
    if ($viewer->isOmnipotent()) {
      return;
    }

    if ($this->isViewerRevisionAuthor($object, $viewer)) {
      return;
    }

    $in_secure_revision = id(new PhabricatorProjectQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withMemberPHIDs(array($viewer->getPHID()))
      ->withNames(array('secure-revision'))
      ->executeOne();
    if ($in_secure_revision) {
      return;
    }

    throw new Exception(
      pht(
        'You can not submit this revision because you are not the author of '.
        'the revision or in the proper permissions group.'));
  }

  public function getColor() {
    return 'sky';
  }

  public function getIcon() {
    return $this->isHeld() ? 'fa-magic' : 'fa-paper-plane';
  }

  public function getActionName() {
    return $this->isHeld() ? pht('Held for AI Review') : pht('Published');
  }

  public function getTitle() {
    if ($this->isHeld()) {
      return pht(
        '%s held this revision in draft while Review Helper reviews it.',
        $this->renderAuthor());
    }

    return pht(
      '%s published this revision for review.',
      $this->renderAuthor());
  }

  public function getTitleForFeed() {
    if ($this->isHeld()) {
      return pht(
        '%s held %s in draft while Review Helper reviews it.',
        $this->renderAuthor(),
        $this->renderObject());
    }

    return pht(
      '%s published %s for review.',
      $this->renderAuthor(),
      $this->renderObject());
  }

  public function getTransactionTypeForConduit($xaction) {
    return self::ACTIONKEY;
  }

  public function getFieldValuesForConduit($xaction, $data) {
    return array(
      'held' => (bool)$xaction->getMetadataValue(self::METADATA_HELD),
    );
  }

  private function isHeld(): bool {
    return (bool)$this->getMetadataValue(self::METADATA_HELD);
  }

}
