<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * Herald action that asks Review Helper to review a revision.
 *
 * Rules using this action are skipped while a revision is a draft, without
 * counting as a match, so a rule set to apply "only the first time" requests
 * one review when the revision is published. Private revisions are never sent,
 * whatever the rule's conditions say.
 */
final class ReviewHelperRequestReviewHeraldAction extends HeraldAction {

  const ACTIONCONST = 'reviewhelper.request';

  const DO_REQUEST = 'do.reviewhelper.request';
  const DO_INELIGIBLE = 'do.reviewhelper.ineligible';
  const DO_HELD = 'do.reviewhelper.held';

  public function getHeraldActionName() {
    return pht('Request AI review');
  }

  public function getActionGroupKey() {
    return HeraldSupportActionGroup::ACTIONGROUPKEY;
  }

  public function getRequiredAdapterStates() {
    // Drafts forbid this state, which defers the rule until publication.
    return array(
      HeraldMailableState::STATECONST,
    );
  }

  public function supportsObject($object) {
    return ($object instanceof DifferentialRevision);
  }

  public function supportsRuleType($rule_type) {
    // Personal rules would let any user send other people's revisions to
    // Review Helper.
    return ($rule_type != HeraldRuleTypeConfig::RULE_TYPE_PERSONAL);
  }

  public function applyEffect($object, HeraldEffect $effect) {
    // The hold requested its own review, and publishing the revision when the
    // hold ends would otherwise trigger a second one here.
    if (ReviewHelperDraftHold::wasEverHeld($object)) {
      $this->logEffect(self::DO_HELD);
      return;
    }

    if (!ReviewHelperAutoRequestWorker::shouldRequestReview($object)) {
      $this->logEffect(self::DO_INELIGIBLE);
      return;
    }

    ReviewHelperAutoRequestWorker::queueRequest($object);
    $this->logEffect(self::DO_REQUEST);
  }

  public function getHeraldActionStandardType() {
    return self::STANDARD_NONE;
  }

  public function renderActionDescription($value) {
    return pht('Request AI review.');
  }

  protected function getActionEffectMap() {
    return array(
      self::DO_REQUEST => array(
        'icon' => 'fa-magic',
        'color' => 'green',
        'name' => pht('Requested AI Review'),
      ),
      self::DO_INELIGIBLE => array(
        'icon' => 'fa-ban',
        'color' => 'grey',
        'name' => pht('Not Eligible for AI Review'),
      ),
      self::DO_HELD => array(
        'icon' => 'fa-check',
        'color' => 'grey',
        'name' => pht('Already Reviewed While Held'),
      ),
    );
  }

  protected function renderActionEffectDescription($type, $data) {
    switch ($type) {
      case self::DO_REQUEST:
        return pht('Queued a request for an AI review.');
      case self::DO_INELIGIBLE:
        return pht(
          'Did not request an AI review, since the revision is private or '.
          'its repository is not enabled for Review Helper.');
      case self::DO_HELD:
        return pht(
          'Did not request an AI review, since one was requested when the '.
          'revision was held in draft.');
    }
  }

}
