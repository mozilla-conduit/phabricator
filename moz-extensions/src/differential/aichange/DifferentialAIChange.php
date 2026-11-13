<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * The AI change state of a revision.
 *
 * A tool like Lando requests an approval when an AI changes the code in a
 * revision, and anyone may approve it. The request is tied to the diff it was
 * made against, so a newer diff makes the approval no longer required.
 */
final class DifferentialAIChange extends Phobject {

  const PROPERTY = 'mozilla.ai-change';

  const STATUS_NONE = 'none';
  const STATUS_PENDING = 'pending';
  const STATUS_APPROVED = 'approved';
  const STATUS_LAPSED = 'lapsed';

  // Why the approval was requested.
  const KIND_MERGE_CONFLICT = 'merge-conflict';
  const KIND_REQUESTED_EDIT = 'requested-edit';

  // Keys used in the stored revision property.
  const KEY_KIND = 'kind';
  const KEY_CHANGE_DIFF_ID = 'changeDiffID';
  const KEY_CHANGE_DIFF_PHID = 'changeDiffPHID';
  const KEY_BASE_DIFF_ID = 'baseDiffID';
  const KEY_SUMMARY = 'summary';
  const KEY_CONFIDENCE = 'confidence';
  const KEY_DETAILS = 'details';
  const KEY_REQUESTER_PHID = 'requesterPHID';
  const KEY_REQUEST_EPOCH = 'requestEpoch';
  const KEY_APPROVER_PHID = 'approverPHID';
  const KEY_APPROVAL_EPOCH = 'approvalEpoch';

  private $revision;
  private $data;

  public function __construct(DifferentialRevision $revision) {
    $this->revision = $revision;
    $this->data = $revision->getProperty(self::PROPERTY, array());
  }

  public static function newFromRevision(DifferentialRevision $revision) {
    return new self($revision);
  }

  /**
   * Map each kind to the noun used for it in titles and the revision box.
   */
  public static function getKindNames() {
    return array(
      self::KIND_MERGE_CONFLICT => pht('AI merge conflict resolution'),
      self::KIND_REQUESTED_EDIT => pht('AI edit'),
    );
  }

  public static function getKindName($kind) {
    return idx(self::getKindNames(), $kind, pht('AI change'));
  }

  public function getStatus() {
    if (!$this->data) {
      return self::STATUS_NONE;
    }

    // A newer diff means someone has updated the code since the AI's
    // change, so normal review covers it.
    $active_diff_phid = $this->revision->getActiveDiffPHID();
    if ($active_diff_phid !== $this->getChangeDiffPHID()) {
      return self::STATUS_LAPSED;
    }

    if ($this->getApproverPHID()) {
      return self::STATUS_APPROVED;
    }

    return self::STATUS_PENDING;
  }

  public function isPending() {
    return ($this->getStatus() === self::STATUS_PENDING);
  }

  public function getKind() {
    return idx($this->data, self::KEY_KIND);
  }

  public function getChangeDiffID() {
    return idx($this->data, self::KEY_CHANGE_DIFF_ID);
  }

  public function getChangeDiffPHID() {
    return idx($this->data, self::KEY_CHANGE_DIFF_PHID);
  }

  public function getBaseDiffID() {
    return idx($this->data, self::KEY_BASE_DIFF_ID);
  }

  public function getSummary() {
    return idx($this->data, self::KEY_SUMMARY);
  }

  public function getConfidence() {
    return idx($this->data, self::KEY_CONFIDENCE);
  }

  public function getDetails() {
    return idx($this->data, self::KEY_DETAILS);
  }

  public function getRequesterPHID() {
    return idx($this->data, self::KEY_REQUESTER_PHID);
  }

  public function getRequestEpoch() {
    return idx($this->data, self::KEY_REQUEST_EPOCH);
  }

  public function getApproverPHID() {
    return idx($this->data, self::KEY_APPROVER_PHID);
  }

  public function getApprovalEpoch() {
    return idx($this->data, self::KEY_APPROVAL_EPOCH);
  }

  /**
   * Store a new request, replacing any earlier request and its approval.
   */
  public function writeRequest(array $request, $requester_phid) {
    $this->data = array(
      self::KEY_KIND => $request[self::KEY_KIND],
      self::KEY_CHANGE_DIFF_ID => $request[self::KEY_CHANGE_DIFF_ID],
      self::KEY_CHANGE_DIFF_PHID => $this->revision->getActiveDiffPHID(),
      self::KEY_BASE_DIFF_ID => $request[self::KEY_BASE_DIFF_ID],
      self::KEY_SUMMARY => $request[self::KEY_SUMMARY],
      self::KEY_CONFIDENCE => $request[self::KEY_CONFIDENCE],
      self::KEY_DETAILS => $request[self::KEY_DETAILS],
      self::KEY_REQUESTER_PHID => $requester_phid,
      self::KEY_REQUEST_EPOCH => PhabricatorTime::getNow(),
      self::KEY_APPROVER_PHID => null,
      self::KEY_APPROVAL_EPOCH => null,
    );
    $this->revision->setProperty(self::PROPERTY, $this->data);
    return $this;
  }

}
