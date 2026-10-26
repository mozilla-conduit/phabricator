<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * Publishes held revisions that Review Helper hasn't reviewed in time.
 *
 * A single trigger queues this sweep every minute, rather than each held
 * revision queueing its own timeout. The taskmaster can't run a task later
 * than it was queued, and new tasks from the trigger run at default priority,
 * ahead of the bulk merge conflict checks. Sweeping also publishes any held
 * revision whose review request was lost.
 *
 * Once Review Helper has posted findings the revision waits on the author
 * instead, so this only publishes revisions still waiting for the review. It
 * runs even if the hold has since been disabled, so turning the hold off never
 * strands a revision in draft.
 */
final class ReviewHelperHoldSweepWorker extends PhabricatorWorker {

  // A fixed PHID lets every caller find the trigger, and the unique key on it
  // stops concurrent callers from creating a second one.
  const TRIGGER_PHID = 'PHID-TRIG-reviewhelperholdsweep';

  const SWEEP_PERIOD = 60;

  // Holds only start when phab-bot first sees a revision, so older revisions
  // are never held and the sweep doesn't need to load them.
  const MAXIMUM_REVISION_AGE = 604800; // 7 days

  /**
   * Creates the trigger that queues this sweep, if it doesn't exist yet.
   */
  public static function ensureTrigger(): void {
    $trigger = id(new PhabricatorWorkerTriggerQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs(array(self::TRIGGER_PHID))
      ->executeOne();
    if ($trigger) {
      return;
    }

    $clock = new PhabricatorMetronomicTriggerClock(
      array('period' => self::SWEEP_PERIOD));

    $action = new PhabricatorScheduleTaskTriggerAction(
      array(
        'class' => __CLASS__,
        'data' => array(),
        'options' => array('priority' => self::PRIORITY_DEFAULT),
      ));

    try {
      id(new PhabricatorWorkerTrigger())
        ->setPHID(self::TRIGGER_PHID)
        ->setClock($clock)
        ->setAction($action)
        ->save();
    } catch (AphrontDuplicateKeyQueryException $ex) {
      // Another edit created the trigger first.
      return;
    }
  }

  protected function doWork() {
    $now = PhabricatorTime::getNow();
    $timeout = PhabricatorEnv::getEnvConfig('reviewhelper.draft-hold.timeout');

    $revisions = id(new DifferentialRevisionQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withStatuses(array(DifferentialRevisionStatus::DRAFT))
      ->withCreatedEpochBetween($now - self::MAXIMUM_REVISION_AGE, $now)
      ->execute();

    foreach ($revisions as $revision) {
      if (!self::isOverdue($revision, $now, $timeout)) {
        continue;
      }

      ReviewHelperDraftHold::publishHeldRevision(
        $revision,
        ReviewHelperReleaseHoldTransaction::REASON_TIMEOUT);
    }
  }

  private static function isOverdue(
    DifferentialRevision $revision,
    int $now,
    int $timeout): bool {

    if (!ReviewHelperDraftHold::isWaitingForReview($revision)) {
      return false;
    }

    return ReviewHelperDraftHold::getHeldAt($revision) + $timeout <= $now;
  }

}
