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

  public function testStatusValueRecordsTheBaseRevision() {
    $value = DifferentialMergeConflictStatusField::newStatusValue(
      array(
        'status' => DifferentialMergeConflictStatusField::STATUS_CLEAN,
        'reason' => 'Merges cleanly when merged against target branch tip.',
        'baseCommit' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        'targetCommit' => 'ffffffffffffffffffffffffffffffffffffffff',
        'baseRevisionPHID' => 'PHID-DREV-landedparent',
      ),
      $this->newDiff(),
      array('PHID-DIFF-parent', 'PHID-DIFF-active'),
      1757000000);

    $this->assertEqual(
      'PHID-DREV-landedparent',
      idx(
        $value,
        DifferentialMergeConflictStatusField::KEY_BASE_REVISION_PHID),
      pht(
        'A verdict that merged from a landed parent should record which '.
        'revision that was, since the reason names it and it sits outside '.
        'the stack the check applied.'));

    $this->assertEqual(
      null,
      idx(
        $this->newStatusValue(),
        DifferentialMergeConflictStatusField::KEY_BASE_REVISION_PHID),
      pht(
        'A verdict that used the stack\'s own recorded base should record no '.
        'base revision.'));
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

  public function testMercurialToolingDetection() {
    $cases = array(
      // Declared Mercurial, whatever submitted it.
      array('arc', 'hg', true),
      array('phlay', 'hg', true),
      // `moz-phab-hg` declares itself Git while recording Mercurial nodes, so
      // the creation method has to be consulted too. This is the largest
      // affected population in production.
      array('moz-phab-hg', 'git', true),
      array('moz-phab-git-cinnabar', 'git', true),
      array('moz-phab-git-cinnabar-uplift', 'hg', true),
      // Native Git tooling.
      array('moz-phab-git', 'git', false),
      array('moz-phab-jj', 'git', false),
      array('moz-phab-git-uplift-lando', 'git', false),
      array('commit', 'git', false),
      // Nothing recorded at all.
      array(null, null, false),
    );

    foreach ($cases as $case) {
      list($method, $system, $expected) = $case;

      $this->assertEqual(
        $expected,
        DifferentialMergeConflictStatusField::isMercurialTooling($method, $system),
        pht('Tooling check for "%s" on "%s".', $method, $system));
    }
  }

  public function testMissingBaseHintSplitsToolingFromFetchLag() {
    $cinnabar = DifferentialMergeConflictStatusField::newHint(
      RevisionMergeConflictReasonException::CODE_BASE_MISSING,
      'moz-phab-git-cinnabar',
      'hg');

    $this->assertTrue(
      strpos($cinnabar, 'native Git checkout') !== false,
      pht('A Mercurial base is advised to submit from Git: %s', $cinnabar));

    $native = DifferentialMergeConflictStatusField::newHint(
      RevisionMergeConflictReasonException::CODE_BASE_MISSING,
      'moz-phab-git',
      'git');

    $this->assertTrue(
      strpos($native, 'resolves on its own') !== false,
      pht(
        'A Git base that has not been fetched yet must not be blamed on '.
        'tooling, since the author is already submitting correctly: %s',
        $native));

    $this->assertTrue(
      strpos($native, 'native Git checkout') === false,
      pht('The fetch-lag hint must not advise a tooling change.'));
  }

  public function testHintNamesTheSubmittingTool() {
    $hint = DifferentialMergeConflictStatusField::newHint(
      RevisionMergeConflictReasonException::CODE_BASE_MISSING,
      'moz-phab-hg',
      'git');

    $this->assertTrue(
      strpos($hint, 'moz-phab-hg') !== false,
      pht('The hint names the tool so the advice can be acted on: %s', $hint));
  }

  public function testDefinitiveVerdictsHaveNoHint() {
    $this->assertEqual(
      null,
      DifferentialMergeConflictStatusField::newHint(null, 'moz-phab-git', 'git'),
      pht(
        'A clean or conflicting verdict records no reason code and needs no '.
        'advice.'));

    $this->assertEqual(
      null,
      DifferentialMergeConflictStatusField::newHint('some-future-code', 'moz-phab-git', 'git'),
      pht('An unrecognised code yields no hint rather than raising.'));
  }

  public function testEveryHintedCodeIsReachable() {
    $codes = array(
      RevisionMergeConflictReasonException::CODE_BASE_NOT_ANCESTOR,
      RevisionMergeConflictReasonException::CODE_PATCH_DOES_NOT_APPLY,
      RevisionMergeConflictReasonException::CODE_PARENT_LANDED,
      RevisionMergeConflictReasonException::CODE_PARENT_ABANDONED,
      RevisionMergeConflictReasonException::CODE_PARENT_OTHER_REPOSITORY,
      RevisionMergeConflictReasonException::CODE_STACK_NOT_LINEAR,
      RevisionMergeConflictReasonException::CODE_STACK_TOO_DEEP,
      RevisionMergeConflictReasonException::CODE_PATCH_TOO_LARGE,
      RevisionMergeConflictReasonException::CODE_COMMAND_FAILED,
      RevisionMergeConflictReasonException::CODE_INTERNAL_ERROR,
    );

    foreach ($codes as $code) {
      $hint = DifferentialMergeConflictStatusField::newHint($code, 'moz-phab-git', 'git');

      $this->assertTrue(
        phutil_nonempty_string($hint),
        pht('Reason code "%s" should offer a hint.', $code));
    }
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

  public function testCheckedDependenciesExcludeTheRevisionsOwnDiff() {
    $dependencies =
      DifferentialMergeConflictStatusField::newCheckedDependencyPHIDs(
        $this->newStatusValue());

    $this->assertEqual(
      array('PHID-DIFF-parent'),
      idx($dependencies, 'diffPHIDs'),
      pht(
        'Only the ancestors below this revision need checking; a reader '.
        'being shown the verdict can already see this revision\'s own diff.'));

    $this->assertEqual(
      array(),
      idx($dependencies, 'revisionPHIDs'),
      pht(
        'A verdict that used the stack\'s own recorded base depended on no '.
        'revision outside the stack.'));
  }

  public function testCheckedDependenciesIncludeTheBaseRevision() {
    $value = $this->newStatusValue();
    $value[DifferentialMergeConflictStatusField::KEY_BASE_REVISION_PHID] =
      'PHID-DREV-landedparent';

    $dependencies =
      DifferentialMergeConflictStatusField::newCheckedDependencyPHIDs($value);

    $this->assertEqual(
      array('PHID-DREV-landedparent'),
      idx($dependencies, 'revisionPHIDs'),
      pht(
        'The reason names the revision whose landing commit was used as the '.
        'merge base, so it has to be checked before the reason is shown.'));
  }

  public function testStandaloneRevisionHasNoCheckedDependencies() {
    $value = $this->newStatusValue();
    $value[DifferentialMergeConflictStatusField::KEY_STACK_DIFF_PHIDS] =
      array('PHID-DIFF-active');

    $dependencies =
      DifferentialMergeConflictStatusField::newCheckedDependencyPHIDs($value);

    $this->assertEqual(
      array(),
      idx($dependencies, 'diffPHIDs'),
      pht(
        'A standalone revision\'s verdict names nothing but itself, so it '.
        'should cost no policy checks.'));
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
