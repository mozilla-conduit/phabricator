<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

final class DifferentialMergeConflictStatusFieldTestCase
  extends PhabricatorTestCase {

  public function testStatusValueRecordsTheCheckedDiff() {
    $value = $this->newStatusValue();

    $this->assertEqual(
      'PHID-DIFF-active',
      idx(
        $value,
        DifferentialMergeConflictStatusField::KEY_DIFF_PHID),
      pht('The payload should record the diff the check ran against.'));

    $this->assertEqual(
      456,
      idx($value, DifferentialMergeConflictStatusField::KEY_DIFF_ID),
      pht(
        'The diff ID should be recorded as an integer, so the payload '.
        'carries a JSON number rather than the string `LiskDAO` hands back.'));
  }

  public function testStatusValueRecordsWhenTheCheckRan() {
    $value = $this->newStatusValue();

    $this->assertEqual(
      1757000000,
      idx($value, DifferentialMergeConflictStatusField::KEY_EPOCH),
      pht('The payload should record when the check ran.'));
  }

  public function testStatusValueCarriesTheEngineResult() {
    $value = $this->newStatusValue();

    $this->assertEqual(
      DifferentialMergeConflictStatusField::STATUS_CLEAN,
      idx($value, DifferentialMergeConflictStatusField::KEY_STATUS),
      pht('The payload should carry the status the engine reported.'));

    $this->assertEqual(
      'Merges cleanly when merged against target branch tip '.
      'ffffffffffff, starting from aaaaaaaaaaaa.',
      idx($value, DifferentialMergeConflictStatusField::KEY_REASON),
      pht('The payload should carry the reason the engine reported.'));

    $this->assertEqual(
      'ffffffffffffffffffffffffffffffffffffffff',
      idx($value, DifferentialMergeConflictStatusField::KEY_TARGET_COMMIT),
      pht('The payload should record the target branch tip that was merged.'));

    $this->assertEqual(
      'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
      idx($value, DifferentialMergeConflictStatusField::KEY_BASE_COMMIT),
      pht(
        'The payload should record the base the stack was merged from, so a '.
        'reader can tell how old the answer is without re-deriving it.'));
  }

  public function testStatusValueRecordsTheStackItDependsOn() {
    $value = $this->newStatusValue();

    $this->assertEqual(
      array('PHID-DIFF-parent', 'PHID-DIFF-active'),
      idx(
        $value,
        DifferentialMergeConflictStatusField::KEY_STACK_DIFF_PHIDS),
      pht(
        'The payload should record every diff the check depends on, so a '.
        'change anywhere in the stack invalidates the stored result.'));
  }

  public function testStatusValueTolerantOfAnUnresolvedStack() {
    // The stack may not resolve at all, which is itself a reason for an
    // `unknown` result, so the worker has nothing to record.
    $value = DifferentialMergeConflictStatusField::newStatusValue(
      array(
        'status' => DifferentialMergeConflictStatusField::STATUS_UNKNOWN,
        'reason' => 'The patch for this revision does not apply.',
      ),
      $this->newDiff(),
      null,
      1757000000);

    $this->assertEqual(
      null,
      idx(
        $value,
        DifferentialMergeConflictStatusField::KEY_STACK_DIFF_PHIDS),
      pht('An unresolved stack should be recorded as `null`.'));

    $this->assertEqual(
      null,
      idx($value, DifferentialMergeConflictStatusField::KEY_TARGET_COMMIT),
      pht(
        'An `unknown` result records no target commit, so a retry is never '.
        'short-circuited.'));

    $this->assertEqual(
      null,
      idx($value, DifferentialMergeConflictStatusField::KEY_BASE_COMMIT),
      pht('An `unknown` result may not have resolved a base commit.'));
  }

  public function testCheckedAgainstDescription() {
    $this->assertEqual(
      'Diff 456, based on aaaaaaaaaaaa',
      DifferentialMergeConflictStatusField::newCheckedAgainstDescription(
        456,
        'aaaaaaaaaaaa'),
      pht(
        'A complete payload should describe both the diff that was checked '.
        'and the base it was merged from.'));

    $this->assertEqual(
      'Diff 456',
      DifferentialMergeConflictStatusField::newCheckedAgainstDescription(
        456,
        null),
      pht(
        'An `unknown` result records no base commit, so the description '.
        'should fall back to naming the diff alone.'));

    $this->assertEqual(
      'Based on aaaaaaaaaaaa',
      DifferentialMergeConflictStatusField::newCheckedAgainstDescription(
        null,
        'aaaaaaaaaaaa'),
      pht('A payload with only a base commit should still describe it.'));

    $this->assertEqual(
      null,
      DifferentialMergeConflictStatusField::newCheckedAgainstDescription(
        null,
        null),
      pht(
        'A payload recording neither input should describe nothing, rather '.
        'than rendering an empty note.'));
  }

  public function testCheckedDiffStaleness() {
    $this->assertFalse(
      DifferentialMergeConflictStatusField::isCheckedDiffStale(
        'PHID-DIFF-active',
        'PHID-DIFF-active'),
      pht('A verdict computed for the current diff is not stale.'));

    $this->assertTrue(
      DifferentialMergeConflictStatusField::isCheckedDiffStale(
        'PHID-DIFF-old',
        'PHID-DIFF-active'),
      pht(
        'A verdict computed for an earlier diff is stale, since the revision '.
        'has been updated since it was computed.'));

    $this->assertFalse(
      DifferentialMergeConflictStatusField::isCheckedDiffStale(
        null,
        'PHID-DIFF-active'),
      pht(
        'A payload that does not record which diff it checked cannot be '.
        'proven stale, so it should be reported as fresh rather than '.
        'casting doubt on a verdict we cannot check.'));

    $this->assertFalse(
      DifferentialMergeConflictStatusField::isCheckedDiffStale(
        'PHID-DIFF-old',
        null),
      pht(
        'A revision with no active diff gives us nothing to compare against, '.
        'so its verdict should be reported as fresh.'));
  }

  public function testCheckedStackStaleness() {
    $checked = array('PHID-DIFF-parent', 'PHID-DIFF-active');

    $this->assertFalse(
      DifferentialMergeConflictStatusField::isCheckedStackStaleForDiffs(
        $checked,
        array(
          'PHID-DIFF-parent' => 'PHID-DIFF-parent',
          'PHID-DIFF-active' => 'PHID-DIFF-active',
        )),
      pht(
        'A verdict is current while every diff it was computed from is still '.
        'its own revision\'s active diff.'));

    $this->assertTrue(
      DifferentialMergeConflictStatusField::isCheckedStackStaleForDiffs(
        $checked,
        array(
          'PHID-DIFF-parent' => 'PHID-DIFF-newer-parent',
          'PHID-DIFF-active' => 'PHID-DIFF-active',
        )),
      pht(
        'A revision below this one receiving a new diff makes the verdict '.
        'stale, since this revision would land on a different set of '.
        'changes than the one that was checked.'));

    $this->assertTrue(
      DifferentialMergeConflictStatusField::isCheckedStackStaleForDiffs(
        $checked,
        array(
          'PHID-DIFF-active' => 'PHID-DIFF-active',
        )),
      pht(
        'A diff that could not be resolved to a revision leaves the verdict '.
        'unprovable, which should read as stale rather than as current.'));

    $this->assertFalse(
      DifferentialMergeConflictStatusField::isCheckedStackStaleForDiffs(
        array(),
        array()),
      pht(
        'A payload recording no stack cannot be proven stale by this check, '.
        'which is what the diff comparison is for.'));
  }

  private function newStatusValue(): array {
    return DifferentialMergeConflictStatusField::newStatusValue(
      array(
        'status' => DifferentialMergeConflictStatusField::STATUS_CLEAN,
        'reason' =>
          'Merges cleanly when merged against target branch tip '.
          'ffffffffffff, starting from aaaaaaaaaaaa.',
        'baseCommit' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        'targetCommit' => 'ffffffffffffffffffffffffffffffffffffffff',
      ),
      $this->newDiff(),
      array('PHID-DIFF-parent', 'PHID-DIFF-active'),
      1757000000);
  }

  private function newDiff(): DifferentialDiff {
    return id(new DifferentialDiff())
      ->setID(456)
      ->setPHID('PHID-DIFF-active');
  }

}
