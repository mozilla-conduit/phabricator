<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * Covers the decisions the stack walk makes about a candidate parent.
 *
 * The stack itself is made of `DifferentialRevisionDependsOnRevisionEdgeType`
 * edges, which `loadOpenParent` reads from the database, so these tests build
 * unrelated revisions by hand and exercise the pure helpers the walk consults
 * at each step instead of the walk itself.
 */
final class RevisionMergeConflictStackQueryTestCase
  extends PhabricatorTestCase {

  public function testOpenParentIsWalkedThrough() {
    $revision = $this->newRevision(2, DifferentialRevisionStatus::NEEDS_REVIEW);
    $parent = $this->newRevision(1, DifferentialRevisionStatus::ACCEPTED);

    $this->assertEqual(
      null,
      RevisionMergeConflictStackQuery::newParentStopReason(
        $revision,
        $parent),
      pht('An open parent revision should be included in the stack.'));
  }

  public function testLandedParentStopsTheWalk() {
    $revision = $this->newRevision(2, DifferentialRevisionStatus::NEEDS_REVIEW);
    $parent = $this->newRevision(1, DifferentialRevisionStatus::PUBLISHED);

    $reason = RevisionMergeConflictStackQuery::newParentStopReason(
      $revision,
      $parent);

    $this->assertTrue(
      strpos($reason, 'D1') !== false,
      pht('A landed parent should stop the walk and name itself: %s', $reason));
  }

  public function testAbandonedParentStopsTheWalk() {
    $revision = $this->newRevision(2, DifferentialRevisionStatus::NEEDS_REVIEW);
    $parent = $this->newRevision(1, DifferentialRevisionStatus::ABANDONED);

    $reason = RevisionMergeConflictStackQuery::newParentStopReason(
      $revision,
      $parent);

    $this->assertTrue(
      strpos($reason, 'abandoned') !== false,
      pht(
        'An abandoned parent should stop the walk and say so: %s',
        $reason));
  }

  public function testParentInAnotherRepositoryIsNotCheckable() {
    $revision = $this->newRevision(2, DifferentialRevisionStatus::NEEDS_REVIEW);
    $parent = $this->newRevision(
      1,
      DifferentialRevisionStatus::NEEDS_REVIEW,
      'PHID-REPO-other');

    $this->assertExceptionMessage(
      'RevisionMergeConflictReasonException',
      'belongs to a different repository',
      function () use ($revision, $parent) {
        RevisionMergeConflictStackQuery::newParentStopReason(
          $revision,
          $parent);
      });
  }

  public function testParentWithoutActiveDiffIsNotCheckable() {
    $revision = $this->newRevision(2, DifferentialRevisionStatus::NEEDS_REVIEW);
    $parent = $this->newRevision(
      1,
      DifferentialRevisionStatus::NEEDS_REVIEW,
      'PHID-REPO-main',
      false);

    $this->assertExceptionMessage(
      'RevisionMergeConflictReasonException',
      'has no active diff',
      function () use ($revision, $parent) {
        RevisionMergeConflictStackQuery::newParentStopReason(
          $revision,
          $parent);
      });
  }

  public function testLandedParentCanBeUsedAsMergeBase() {
    $landed = $this->newRevision(1, DifferentialRevisionStatus::PUBLISHED);

    $this->assertTrue(
      RevisionMergeConflictStackQuery::canUseParentAsMergeBase($landed),
      pht(
        'A landed parent revision has a commit on the branch, so it can serve '.
        'as the merge base.'));
  }

  public function testAbandonedParentCannotBeUsedAsMergeBase() {
    $abandoned = $this->newRevision(1, DifferentialRevisionStatus::ABANDONED);

    $this->assertFalse(
      RevisionMergeConflictStackQuery::canUseParentAsMergeBase($abandoned),
      pht(
        'An abandoned parent never reaches the branch, so it cannot serve as '.
        'the merge base.'));
  }

  public function testOpenParentCannotBeUsedAsMergeBase() {
    $open = $this->newRevision(1, DifferentialRevisionStatus::ACCEPTED);

    $this->assertFalse(
      RevisionMergeConflictStackQuery::canUseParentAsMergeBase($open),
      pht(
        'An open parent has not landed, so its patch is applied to the stack '.
        'instead of being used as the merge base.'));
  }

  private function newRevision(
    int $id,
    string $status,
    string $repository_phid = 'PHID-REPO-main',
    bool $with_active_diff = true): DifferentialRevision {

    $active_diff = null;
    if ($with_active_diff) {
      $active_diff = id(new DifferentialDiff())
        ->setPHID('PHID-DIFF-'.$id);
    }

    return id(new DifferentialRevision())
      ->setID($id)
      ->setPHID('PHID-DREV-'.$id)
      ->setStatus($status)
      ->setRepositoryPHID($repository_phid)
      ->attachActiveDiff($active_diff);
  }

}
