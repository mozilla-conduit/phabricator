<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * An explanation, written by the merge check itself, of why it cannot answer.
 *
 * The message is rendered on the revision and handed to Lando, so it is written
 * for that audience. Anything else that escapes the check is logged and
 * replaced with a fixed string instead, since an arbitrary exception message
 * can carry server paths and other details that have no business being shown to
 * a reader of the revision.
 *
 * The reason code names the same thing in a form that survives rewording. It is
 * stored with the verdict and handed to Lando so callers can branch on the
 * cause without matching English, and it is what selects the hint shown next to
 * the verdict.
 */
final class RevisionMergeConflictReasonException extends Exception {

  // Set when an exception reaches storage without naming its own cause.
  const CODE_UNSPECIFIED = 'unspecified';

  // The base the patch was written on top of.
  const CODE_BASE_MISSING = 'base-missing';
  const CODE_BASE_NOT_ANCESTOR = 'base-not-ancestor';
  const CODE_NO_RECORDED_BASE = 'no-recorded-base';

  // The patches themselves.
  const CODE_PATCH_TOO_LARGE = 'patch-too-large';
  const CODE_PATCH_EMPTY = 'patch-empty';
  const CODE_PATCH_DOES_NOT_APPLY = 'patch-does-not-apply';
  const CODE_DIFF_RELOAD_FAILED = 'diff-reload-failed';

  // The shape of the stack.
  const CODE_STACK_TOO_DEEP = 'stack-too-deep';
  const CODE_STACK_NOT_LINEAR = 'stack-not-linear';
  const CODE_STACK_CYCLE = 'stack-cycle';
  const CODE_PARENT_ABANDONED = 'parent-abandoned';
  const CODE_PARENT_LANDED = 'parent-landed';
  const CODE_PARENT_OTHER_REPOSITORY = 'parent-other-repository';
  const CODE_PARENT_NO_ACTIVE_DIFF = 'parent-no-active-diff';
  const CODE_PARENT_LOAD_FAILED = 'parent-load-failed';

  // The repository and the tools used to check it.
  const CODE_NOT_GIT = 'not-git';
  const CODE_NO_DEFAULT_BRANCH = 'no-default-branch';
  const CODE_GIT_TOO_OLD = 'git-too-old';
  const CODE_GIT_TIMEOUT = 'git-timeout';
  const CODE_MERGE_TREE_EXIT = 'merge-tree-exit';

  // Set by the catch-alls in `executeCheck`, where the detail reaches the
  // daemon log rather than the revision.
  const CODE_COMMAND_FAILED = 'command-failed';
  const CODE_INTERNAL_ERROR = 'internal-error';

  private string $reasonCode = self::CODE_UNSPECIFIED;

  /**
   * Builds an exception that names its own cause. Preferred over the plain
   * constructor everywhere the cause is known, which is everywhere the check
   * raises one itself.
   */
  public static function newWithCode(
    string $reason_code,
    string $message): self {

    $exception = new self($message);
    $exception->reasonCode = $reason_code;

    return $exception;
  }

  public function getReasonCode(): string {
    return $this->reasonCode;
  }

}
