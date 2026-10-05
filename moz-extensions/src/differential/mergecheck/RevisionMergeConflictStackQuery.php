<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * Resolves the stack around a revision for merge-conflict checking.
 *
 * A revision in a stack only lands after every revision below it, so its real
 * mergeability is "target branch + each open ancestor's patch + my patch".
 * `loadAncestorChain` returns exactly that list of revisions, bottom-most
 * first, and `loadDescendantPHIDs` walks the other direction to find the
 * revisions whose answer changes when something below them changes.
 *
 * Walking upward stops at the first ancestor that has already landed (or was
 * abandoned): its patch is either already on the branch or will never be, so
 * the caller decides what to do with the chain it gets, and `getStopReason`
 * explains why the walk stopped.
 */
final class RevisionMergeConflictStackQuery extends Phobject {

  // Applying every ancestor patch costs a `git apply` each, so give up on
  // pathologically deep stacks rather than spending unbounded worker time.
  // The deepest stacks seen in practice are around 50 revisions.
  const MAX_ANCESTOR_DEPTH = 50;

  // Likewise, cap how many descendants a single changed revision can fan out
  // to. The budget for a call is this times the number of revisions passed in,
  // so batching candidates together does not make each one's fan-out smaller.
  const MAX_DESCENDANTS_PER_REVISION = 100;

  private ?PhabricatorUser $viewer = null;
  private ?DifferentialRevision $revision = null;
  private ?string $stopReason = null;
  private ?string $stopReasonCode = null;
  private ?DifferentialRevision $landedParent = null;

  public function setViewer(PhabricatorUser $viewer): self {
    $this->viewer = $viewer;
    return $this;
  }

  public function setRevision(DifferentialRevision $revision): self {
    $this->revision = $revision;
    return $this;
  }

/* -(  Ancestors  )---------------------------------------------------------- */

  /**
   * Returns the chain of open revisions that must land in order for this
   * revision to land, ordered bottom-most first and including the revision
   * itself. A standalone revision yields a single-element list.
   */
  public function loadAncestorChain(): array {
    $this->stopReason = null;
    $this->stopReasonCode = null;
    $this->landedParent = null;

    $chain = array($this->revision);
    $seen = array($this->revision->getPHID() => true);
    $cursor = $this->revision;

    while (count($chain) <= self::MAX_ANCESTOR_DEPTH) {
      $parent = $this->loadOpenParent($cursor);
      if (!$parent) {
        return $chain;
      }

      if (isset($seen[$parent->getPHID()])) {
        throw RevisionMergeConflictReasonException::newWithCode(
          RevisionMergeConflictReasonException::CODE_STACK_CYCLE,
          pht(
            'Stack contains a dependency cycle at %s.',
            $parent->getMonogram()));
      }

      $seen[$parent->getPHID()] = true;
      array_unshift($chain, $parent);
      $cursor = $parent;
    }

    throw RevisionMergeConflictReasonException::newWithCode(
      RevisionMergeConflictReasonException::CODE_STACK_TOO_DEEP,
      pht(
        'Stack is more than %s revisions deep.',
        new PhutilNumber(self::MAX_ANCESTOR_DEPTH)));
  }

  /**
   * Explains why `loadAncestorChain` stopped walking upward, or `null` if it
   * stopped because the bottom-most revision has no parent at all.
   */
  public function getStopReason(): ?string {
    return $this->stopReason;
  }

  /**
   * Names the same stop in a form that survives rewording, so a caller that
   * rethrows the reason can carry the cause with it.
   */
  public function getStopReasonCode(): ?string {
    return $this->stopReasonCode;
  }

  /**
   * Returns the already-landed parent revision the upward walk stopped at, if
   * that is why it stopped. The commit that closed it is the right thing to
   * merge the rest of the stack from, since the recorded base of the revision
   * above it only ever existed in the author's checkout.
   */
  public function getLandedParent(): ?DifferentialRevision {
    return $this->landedParent;
  }

  private function loadOpenParent(
    DifferentialRevision $revision): ?DifferentialRevision {
    $parent_phids = PhabricatorEdgeQuery::loadDestinationPHIDs(
      $revision->getPHID(),
      DifferentialRevisionDependsOnRevisionEdgeType::EDGECONST);

    if (!$parent_phids) {
      return null;
    }

    if (count($parent_phids) > 1) {
      throw RevisionMergeConflictReasonException::newWithCode(
        RevisionMergeConflictReasonException::CODE_STACK_NOT_LINEAR,
        pht(
          '%s has %s parent revisions; merge checks require a linear stack.',
          $revision->getMonogram(),
          new PhutilNumber(count($parent_phids))));
    }

    $parent = id(new DifferentialRevisionQuery())
      ->setViewer($this->viewer)
      ->withPHIDs($parent_phids)
      ->needActiveDiffs(true)
      ->executeOne();

    if (!$parent) {
      throw RevisionMergeConflictReasonException::newWithCode(
        RevisionMergeConflictReasonException::CODE_PARENT_LOAD_FAILED,
        pht(
          'Failed to load the parent revision of %s.',
          $revision->getMonogram()));
    }

    $stop_reason = self::newParentStopReason($revision, $parent);
    if ($stop_reason !== null) {
      $this->stopReason = $stop_reason['message'];
      $this->stopReasonCode = $stop_reason['code'];

      if (self::canUseParentAsMergeBase($parent)) {
        $this->landedParent = $parent;
      }

      return null;
    }

    return $parent;
  }

  /**
   * Decides whether the upward walk should continue through a candidate parent.
   * Returns `null` to accept the parent, or a map of `code` and `message`
   * naming why the walk has to stop there. Throws when the stack is shaped in a
   * way we can't check at all.
   */
  public static function newParentStopReason(
    DifferentialRevision $revision,
    DifferentialRevision $parent): ?array {

    if ($parent->isAbandoned()) {
      return array(
        'code' => RevisionMergeConflictReasonException::CODE_PARENT_ABANDONED,
        'message' => pht(
          'Parent revision %s was abandoned, so its changes will never reach '.
          'the target branch.',
          $parent->getMonogram()),
      );
    }

    if ($parent->isClosed()) {
      return array(
        'code' => RevisionMergeConflictReasonException::CODE_PARENT_LANDED,
        'message' => pht(
          'Parent revision %s has already landed. Update this revision on top '.
          'of the current target branch to check it.',
          $parent->getMonogram()),
      );
    }

    if ($parent->getRepositoryPHID() !== $revision->getRepositoryPHID()) {
      throw RevisionMergeConflictReasonException::newWithCode(
        RevisionMergeConflictReasonException::CODE_PARENT_OTHER_REPOSITORY,
        pht(
          'Parent revision %s belongs to a different repository.',
          $parent->getMonogram()));
    }

    if (!$parent->getActiveDiff()) {
      throw RevisionMergeConflictReasonException::newWithCode(
        RevisionMergeConflictReasonException::CODE_PARENT_NO_ACTIVE_DIFF,
        pht(
          'Parent revision %s has no active diff.',
          $parent->getMonogram()));
    }

    return null;
  }

  /**
   * Whether the commit that closed a parent revision can serve as the merge base
   * for the stack above it. A landed parent's commit is on the branch and is
   * what the stack above it was written on top of; an abandoned parent never
   * reaches the branch, so it gives us nothing to merge from.
   */
  public static function canUseParentAsMergeBase(
    DifferentialRevision $parent): bool {
    return ($parent->isClosed() && !$parent->isAbandoned());
  }

/* -(  Descendants  )-------------------------------------------------------- */

  /**
   * Returns the PHIDs of every revision stacked above the given revisions,
   * transitively, excluding the given revisions themselves.
   *
   * Closed revisions are traversed through rather than skipped, since an open
   * revision can sit above a landed one.
   */
  public static function loadDescendantPHIDs(array $revision_phids): array {
    $edge_type = DifferentialRevisionDependedOnByRevisionEdgeType::EDGECONST;

    $limit = self::newDescendantLimit(count($revision_phids));

    $seen = array_fuse($revision_phids);
    $found = array();
    $queue = $revision_phids;

    while ($queue && count($found) < $limit) {
      $children = id(new PhabricatorEdgeQuery())
        ->withSourcePHIDs($queue)
        ->withEdgeTypes(array($edge_type))
        ->execute();

      $queue = array();
      foreach ($children as $child_edges) {
        foreach (array_keys(idx($child_edges, $edge_type, array())) as $phid) {
          if (isset($seen[$phid])) {
            continue;
          }
          $seen[$phid] = $phid;
          $found[$phid] = $phid;
          $queue[] = $phid;
        }
      }
    }

    // Hitting the budget means revisions above the ones we found keep a stale
    // verdict with nothing to say so. Say so in the log, since the callers
    // queue work and have nowhere to report it.
    if (count($found) >= $limit) {
      phlog(
        pht(
          'Merge check descendant walk stopped at its budget of %s for %s '.
          'revision(s); revisions stacked above the ones found were not '.
          'queued.',
          new PhutilNumber($limit),
          new PhutilNumber(count($revision_phids))));
    }

    return array_values($found);
  }

  /**
   * The number of descendants a call is allowed to find, which scales with the
   * number of revisions it was asked about. A commit landing on a busy branch
   * passes its whole candidate set in one call, and a flat budget there would
   * spend the entire allowance on whichever candidates happened to be walked
   * first.
   */
  public static function newDescendantLimit(int $revision_count): int {
    return ($revision_count * self::MAX_DESCENDANTS_PER_REVISION);
  }

}
