<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * Read-only field holding whether a revision's active diff merges cleanly into
 * its target branch. The value is computed by `RevisionMergeConflictWorker` and
 * written directly to storage (never via a transaction), then exposed in the
 * revision "Details" section and over Conduit so Lando can surface its own
 * warning.
 */
final class DifferentialMergeConflictStatusField
  extends DifferentialStoredCustomField {

  const STATUS_CLEAN = 'clean';
  const STATUS_CONFLICT = 'conflict';
  const STATUS_UNKNOWN = 'unknown';

  // Keys used in the stored JSON payload.
  const KEY_STATUS = 'status';
  const KEY_REASON = 'reason';
  const KEY_TARGET_COMMIT = 'checkedAgainstCommit';
  const KEY_BASE_COMMIT = 'checkedAgainstBaseCommit';
  const KEY_DIFF_PHID = 'checkedAgainstDiffPHID';
  const KEY_DIFF_ID = 'checkedAgainstDiffID';
  const KEY_STACK_DIFF_PHIDS = 'checkedAgainstStackDiffPHIDs';
  const KEY_EPOCH = 'epoch';

  // Derived for the Conduit payload only; never written to storage.
  const KEY_IS_STALE = 'isStale';

/* -(  Core Properties and Field Identity  )--------------------------------- */

  public function getFieldKey() {
    return 'differential:merge-conflict-status';
  }

  public function getFieldKeyForConduit() {
    return 'merge.conflict.status';
  }

  public function getFieldName() {
    return pht('Merge Conflict Status');
  }

  public function getFieldDescription() {
    return pht(
      'Indicates whether the active diff merges cleanly into the target '.
      'branch.');
  }

  public function isFieldEnabled() {
    // Turning the feature off hides the field everywhere rather than leaving a
    // status behind that nothing will update.
    return (bool)PhabricatorEnv::getEnvConfig(
      MergeConflictConfigOptions::OPTION_ENABLED);
  }

  public function canDisableField() {
    // The field is managed automatically, so don't allow it to be switched off.
    return false;
  }

/* -(  Read-only: not user-editable  )--------------------------------------- */

  public function isFieldEditable() {
    return false;
  }

  public function shouldAppearInApplicationTransactions() {
    return false;
  }

  public function shouldAppearInEditView() {
    return false;
  }

  public function renderEditControl(array $handles) {
    return null;
  }

  public function newCommentAction() {
    return null;
  }

/* -(  Storage  )------------------------------------------------------------ */

  public function getValueForStorage() {
    return phutil_json_encode($this->getValue());
  }

  public function setValueFromStorage($value) {
    try {
      $this->setValue(phutil_json_decode($value));
    } catch (PhutilJSONParserException $ex) {
      $this->setValue(array());
    }
    return $this;
  }

  /**
   * Persist a computed status for a revision directly to field storage,
   * bypassing the transaction editor so routine rechecks don't generate feed
   * stories, mail, or Herald evaluation (which would also risk re-triggering
   * the recompute). Mirrors the upsert in
   * `PhabricatorCustomField::applyApplicationTransactionExternalEffects()`.
   */
  public function writeStatusForObject(
    string $object_phid,
    array $value): self {
    $table = $this->newStorageObject();
    $conn_w = $table->establishConnection('w');

    queryfx(
      $conn_w,
      'INSERT INTO %T (objectPHID, fieldIndex, fieldValue)
        VALUES (%s, %s, %s)
        ON DUPLICATE KEY UPDATE fieldValue = VALUES(fieldValue)',
      $table->getTableName(),
      $object_phid,
      $this->getFieldIndex(),
      phutil_json_encode($value));

    return $this;
  }

  /**
   * Builds the payload stored for one completed check.
   *
   * The field owns the shape of its own storage, so callers hand over the
   * engine's result plus the inputs it ran against rather than assembling keys
   * themselves. The epoch is passed in so the caller decides what "now" means.
   */
  public static function newStatusValue(
    array $result,
    DifferentialDiff $diff,
    ?array $stack_diff_phids,
    int $epoch): array {

    return array(
      self::KEY_STATUS => idx($result, 'status'),
      self::KEY_REASON => idx($result, 'reason'),
      self::KEY_TARGET_COMMIT => idx($result, 'targetCommit'),
      self::KEY_BASE_COMMIT => idx($result, 'baseCommit'),
      self::KEY_DIFF_PHID => $diff->getPHID(),
      // Cast so the payload carries a JSON number; `LiskDAO` hands the ID
      // back as a string.
      self::KEY_DIFF_ID => (int)$diff->getID(),
      self::KEY_STACK_DIFF_PHIDS => $stack_diff_phids,
      self::KEY_EPOCH => $epoch,
    );
  }

  /**
   * Reads the currently-stored status payload for a revision directly from
   * field storage, returning the decoded array or `null` if nothing is stored
   * (or the stored value can't be decoded). Used to decide whether a recompute
   * would be redundant.
   */
  public function readStoredValueForObject(string $object_phid): ?array {
    $table = $this->newStorageObject();

    $row = queryfx_one(
      $table->establishConnection('r'),
      'SELECT fieldValue FROM %T WHERE objectPHID = %s AND fieldIndex = %s',
      $table->getTableName(),
      $object_phid,
      $this->getFieldIndex());

    if (!$row) {
      return null;
    }

    try {
      $value = phutil_json_decode($row['fieldValue']);
    } catch (PhutilJSONParserException $ex) {
      return null;
    }

    // A value that decodes to something other than an array is as unusable as
    // one that does not decode at all.
    if (!is_array($value)) {
      return null;
    }

    return $value;
  }

/* -(  Property View  )------------------------------------------------------ */

  public function shouldAppearInPropertyView() {
    return true;
  }

  public function renderPropertyViewValue(array $handles) {
    $value = $this->getValue();
    if (!is_array($value) || empty($value[self::KEY_STATUS])) {
      return null;
    }

    if (!$this->isEnabledForRevisionRepository()) {
      return null;
    }

    // A revision that will never land has no useful mergeability. Closing one
    // also attaches a new commit-derived diff, which would otherwise leave the
    // stored status looking permanently stale.
    if ($this->isRevisionClosed()) {
      return null;
    }

    $list = id(new PHUIStatusListView())
      ->addItem($this->newVerdictItem($value));

    // What the verdict was computed from matters as much as the verdict: a
    // reader who can see the diff, the time and the base can decide for
    // themselves whether an inconvenient answer is still worth believing.
    $checked_item = $this->newLastCheckedItem($value);
    if ($checked_item) {
      $list->addItem($checked_item);
    }

    return $list;
  }

  /**
   * Renders the verdict itself. Landing a revision lands every revision below
   * it in the stack, and the check merges that whole stack, so this is a
   * statement about the landing rather than about this revision's own patch.
   */
  private function newVerdictItem(array $value): PHUIStatusItemView {
    $item = new PHUIStatusItemView();

    if ($this->isStatusStale($value)) {
      return $item
        ->setIcon(
          PHUIStatusItemView::ICON_CLOCK,
          'blue',
          pht('Recomputing'))
        ->setTarget(pht('Recomputing for the latest diff'))
        ->setNote(
          pht(
            'The last result was computed for an earlier diff, or for an '.
            'earlier version of a revision below this one, so it is not '.
            'shown.'));
    }

    switch ($value[self::KEY_STATUS]) {
      case self::STATUS_CLEAN:
        $item
          ->setIcon(
            PHUIStatusItemView::ICON_ACCEPT,
            'green',
            pht('Merges Cleanly'))
          ->setTarget(pht('Landing merges cleanly into the target branch'));
        break;
      case self::STATUS_CONFLICT:
        $item
          ->setIcon(
            PHUIStatusItemView::ICON_REJECT,
            'red',
            pht('Merge Conflict'))
          ->setTarget(
            pht(
              'Landing may fail: this does not merge cleanly into the '.
              'target branch'));
        break;
      case self::STATUS_UNKNOWN:
      default:
        $item
          ->setIcon(
            PHUIStatusItemView::ICON_QUESTION,
            'grey',
            pht('Unknown'))
          ->setTarget(pht('Mergeability could not be determined'));
        break;
    }

    // The reason names the revision responsible and how much of the stack was
    // applied first, which is the actionable part of every verdict.
    return $item->setNote(idx($value, self::KEY_REASON));
  }

  /**
   * Renders when the stored verdict was computed and what it was computed
   * from, or `null` if the payload records neither.
   */
  private function newLastCheckedItem(array $value): ?PHUIStatusItemView {
    $epoch = idx($value, self::KEY_EPOCH);

    $description = self::newCheckedAgainstDescription(
      idx($value, self::KEY_DIFF_ID),
      $this->newBaseCommitName($value));

    if (!$epoch && $description === null) {
      return null;
    }

    $item = new PHUIStatusItemView();

    if ($epoch) {
      $item->setTarget(
        pht(
          'Last checked %s',
          phabricator_datetime($epoch, $this->requireViewer())));
    } else {
      $item->setTarget(pht('Last checked at an unrecorded time'));
    }

    if ($description !== null) {
      $item->setNote($description);
    }

    return $item;
  }

  /**
   * Describes the inputs a verdict was computed from: the diff that was checked
   * and the base commit the stack was merged from. Returns `null` when the
   * payload records neither, so the caller can leave the note empty.
   */
  public static function newCheckedAgainstDescription(
    ?int $diff_id,
    ?string $base_commit_name): ?string {

    $has_base = phutil_nonempty_string($base_commit_name);

    if ($diff_id && $has_base) {
      return pht('Diff %d, based on %s', $diff_id, $base_commit_name);
    }

    if ($diff_id) {
      return pht('Diff %d', $diff_id);
    }

    if ($has_base) {
      return pht('Based on %s', $base_commit_name);
    }

    return null;
  }

  /**
   * Abbreviates the recorded base commit the way the rest of the interface
   * abbreviates commits, so the hash here is recognisable next to one in
   * Diffusion.
   */
  private function newBaseCommitName(array $value): ?string {
    $base_commit = idx($value, self::KEY_BASE_COMMIT);
    if (!phutil_nonempty_string($base_commit)) {
      return null;
    }

    $repository = $this->getRevisionRepository();
    if (!$repository) {
      return $base_commit;
    }

    // Format the name without the repository scope, since the field is already
    // being read in the context of one repository's revision.
    return $repository->formatCommitName($base_commit, true);
  }

/* -(  Conduit  )------------------------------------------------------------ */

  public function shouldAppearInConduitDictionary() {
    return true;
  }

  public function getConduitDictionaryValue() {
    if (!$this->isEnabledForRevisionRepository()) {
      return null;
    }

    // A revision that will never land has no useful mergeability, and closing
    // one attaches a new commit-derived diff that would leave the stored
    // status looking permanently stale. The property view hides it for the
    // same reason.
    if ($this->isRevisionClosed()) {
      return null;
    }

    $value = $this->getValue();
    if (!is_array($value)) {
      return null;
    }

    // Lando warns from this payload alone, so answer the question it would
    // otherwise have to work out for itself: does this verdict still describe
    // the revision's current diff?
    $value[self::KEY_IS_STALE] = $this->isStatusStale($value);

    return $value;
  }

/* -(  Helpers  )------------------------------------------------------------ */

  /**
   * Whether merge conflict detection is turned on for this revision's
   * repository, so a per-repository rollout does not leave statuses showing on
   * repositories that are no longer being checked.
   */
  private function isEnabledForRevisionRepository(): bool {
    $repository = $this->getRevisionRepository();
    if (!$repository) {
      return false;
    }

    return RevisionMergeConflictWorker::isEnabledForRepository($repository);
  }

  /**
   * Returns the repository of the revision this field is attached to, or `null`
   * if the field is attached to something else or the revision has no
   * repository.
   */
  private function getRevisionRepository(): ?PhabricatorRepository {
    $object = $this->getObject();
    if (!($object instanceof DifferentialRevision)) {
      return null;
    }

    return $object->getRepository();
  }

  /**
   * Whether the revision has reached a state it will never land from, in which
   * case there is nothing useful to display.
   */
  private function isRevisionClosed(): bool {
    $object = $this->getObject();
    if (!($object instanceof DifferentialRevision)) {
      return false;
    }

    // `isClosed` covers abandoned revisions too.
    return $object->isClosed();
  }

  /**
   * Whether the stored verdict was computed against diffs that are no longer
   * current, in which case a fresh check is already queued and this answer is
   * only a best guess until it lands.
   *
   * The verdict covers the whole stack, so a revision below this one receiving
   * a new diff invalidates it just as surely as this revision doing so. Lando
   * lands from this payload, so missing that case would permit exactly the
   * failure this field exists to prevent.
   */
  private function isStatusStale(array $value): bool {
    $object = $this->getObject();
    if (!($object instanceof DifferentialRevision)) {
      return false;
    }

    // Read the PHID column rather than the attached diff: this also runs over
    // Conduit, where the active diff is not necessarily loaded.
    $is_stale = self::isCheckedDiffStale(
      idx($value, self::KEY_DIFF_PHID),
      $object->getActiveDiffPHID());
    if ($is_stale) {
      return true;
    }

    return $this->isCheckedStackStale($value);
  }

  /**
   * Whether any revision whose patch the verdict included has been updated
   * since. Every stored stack diff has to still be its own revision's active
   * diff for the verdict to describe what would land today.
   *
   * Costs two queries, so it only runs for a revision that was actually
   * checked as part of a stack: a standalone revision's stored stack is just
   * its own diff, which `isStatusStale` has already compared.
   */
  private function isCheckedStackStale(array $value): bool {
    $checked_phids = idx($value, self::KEY_STACK_DIFF_PHIDS);
    if (!is_array($checked_phids) || count($checked_phids) < 2) {
      return false;
    }

    // Comparing PHIDs discloses nothing, and a verdict must not read as fresh
    // just because the reader cannot see a revision below it.
    $viewer = PhabricatorUser::getOmnipotentUser();

    $checked_diffs = id(new DifferentialDiffQuery())
      ->setViewer($viewer)
      ->withPHIDs($checked_phids)
      ->execute();
    if (count($checked_diffs) !== count($checked_phids)) {
      // A diff the verdict was computed from has gone away, so we cannot show
      // that the verdict still holds.
      return true;
    }

    $revision_ids = array_filter(mpull($checked_diffs, 'getRevisionID'));
    if (count($revision_ids) !== count($checked_diffs)) {
      // A diff the verdict was computed from is no longer attached to a
      // revision, so there is nothing to compare it against.
      return true;
    }

    $revisions = id(new DifferentialRevisionQuery())
      ->setViewer($viewer)
      ->withIDs($revision_ids)
      ->needActiveDiffs(true)
      ->execute();
    $revisions = mpull($revisions, null, 'getID');

    $current_diff_phids = array();
    foreach ($checked_diffs as $checked_diff) {
      $revision = idx($revisions, $checked_diff->getRevisionID());
      if (!$revision) {
        continue;
      }

      $current_diff_phids[$checked_diff->getPHID()] =
        $revision->getActiveDiffPHID();
    }

    return self::isCheckedStackStaleForDiffs(
      $checked_phids,
      $current_diff_phids);
  }

  /**
   * Compares the diffs a verdict was computed from against the current active
   * diff of each of their revisions, given as a map from checked diff PHID to
   * the current one. A diff missing from the map could not be resolved at all,
   * which is reported as stale rather than assumed current.
   */
  public static function isCheckedStackStaleForDiffs(
    array $checked_diff_phids,
    array $current_diff_phids): bool {

    foreach ($checked_diff_phids as $checked_phid) {
      $current_phid = idx($current_diff_phids, $checked_phid);
      if (!phutil_nonempty_string($current_phid)) {
        return true;
      }

      if ($current_phid !== $checked_phid) {
        return true;
      }
    }

    return false;
  }

  /**
   * Compares the diff a verdict was computed against with the revision's
   * current active diff. Either one being unknown is reported as fresh, since
   * we would rather show a verdict than cast doubt on one we cannot check.
   */
  public static function isCheckedDiffStale(
    ?string $checked_diff_phid,
    ?string $active_diff_phid): bool {

    if (!phutil_nonempty_string($checked_diff_phid)) {
      return false;
    }

    if (!phutil_nonempty_string($active_diff_phid)) {
      return false;
    }

    return ($checked_diff_phid !== $active_diff_phid);
  }

}
