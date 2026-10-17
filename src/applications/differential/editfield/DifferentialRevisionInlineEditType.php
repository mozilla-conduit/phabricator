<?php

/**
 * Builds a published inline comment from an "inline" transaction sent to
 * "differential.revision.edit".
 *
 * The transaction value uses the same keys that "transaction.search" reports
 * for inline comments (see @{class:DifferentialRevisionInlineTransaction}),
 * so clients read and write inlines in the same shape. The comment is saved
 * by the transaction editor when the transaction is applied, so it is
 * published together with any other transactions in the same call.
 */
final class DifferentialRevisionInlineEditType
  extends PhabricatorEditType {

  public function generateTransactions(
    PhabricatorApplicationTransaction $template,
    array $spec) {

    $value = idx($spec, 'value');
    if (!is_array($value)) {
      throw new Exception(
        pht('Inline comment transaction value must be a map.'));
    }

    $valid_keys = array(
      'diff',
      'path',
      'line',
      'length',
      'isNewFile',
      'content',
      'replyToCommentPHID',
      'hasSuggestion',
      'suggestionText',
    );
    $unknown_keys = array_diff_key($value, array_fuse($valid_keys));
    if ($unknown_keys) {
      throw new Exception(
        pht(
          'Inline comment has unknown keys: %s. Valid keys are: %s.',
          implode(', ', array_keys($unknown_keys)),
          implode(', ', $valid_keys)));
    }

    $edit_field = $this->getEditField();
    $viewer = $edit_field->getViewer();
    $revision = $edit_field->getObject();

    if (!$revision->getID()) {
      throw new Exception(
        pht('Inline comments can not be added while creating a revision.'));
    }

    $reply_phid = idx($value, 'replyToCommentPHID');
    if ($reply_phid !== null) {
      $parent = $this->loadReplyToComment($viewer, $revision, $reply_phid);
      $location = $this->readReplyLocation($viewer, $parent, $value);
    } else {
      $parent = null;
      $location = $this->readLocation($viewer, $revision, $value);
    }

    $inline = id(new DifferentialInlineComment())
      ->setRevision($revision)
      ->setChangesetID($location['changeset']->getID())
      ->setIsNewFile((int)$location['isNewFile'])
      ->setLineNumber($location['line'])
      ->setLineLength($location['length'] - 1)
      ->setReplyToCommentPHID($reply_phid)
      ->setContentState($this->readContentState($value));

    $comment = $inline->getStorageObject()
      ->attachReplyToComment($parent);

    $xaction = $this->newTransaction($template)
      ->attachComment($comment);

    return array($xaction);
  }

  private function readLocation(
    PhabricatorUser $viewer,
    DifferentialRevision $revision,
    array $value) {

    // Load the active diff with a query too, rather than using the one
    // attached to the revision: that one has no revision attached, so only
    // its author could see its changesets.
    $diff_phid = idx($value, 'diff');
    if ($diff_phid === null) {
      $diff_phid = $revision->getActiveDiff()->getPHID();
    }

    if (!is_string($diff_phid)) {
      throw new Exception(
        pht('Inline comment key "diff" must be a diff PHID.'));
    }

    $diff = id(new DifferentialDiffQuery())
      ->setViewer($viewer)
      ->withPHIDs(array($diff_phid))
      ->executeOne();
    if (!$diff || ($diff->getRevisionID() != $revision->getID())) {
      throw new Exception(
        pht(
          'Diff "%s" does not exist or does not belong to this revision.',
          $diff_phid));
    }

    $path = idx($value, 'path');
    if (!is_string($path) || !strlen($path)) {
      throw new Exception(
        pht('Inline comment key "path" is required and must be a string.'));
    }

    $changesets = id(new DifferentialChangesetQuery())
      ->setViewer($viewer)
      ->withDiffs(array($diff))
      ->execute();

    $changeset = null;
    foreach ($changesets as $candidate) {
      if ($this->isChangesetPath($candidate, $path)) {
        $changeset = $candidate;
        break;
      }
    }

    if (!$changeset) {
      throw new Exception(
        pht(
          'Path "%s" is not changed in diff "%s".',
          $path,
          $diff->getPHID()));
    }

    $line = $this->readPositiveInteger($value, 'line');
    if ($line === null) {
      throw new Exception(pht('Inline comment key "line" is required.'));
    }

    $length = $this->readPositiveInteger($value, 'length');
    if ($length === null) {
      $length = 1;
    }

    $is_new_file = idx($value, 'isNewFile');
    if (!is_bool($is_new_file)) {
      throw new Exception(
        pht(
          'Inline comment key "isNewFile" is required and must be a '.
          'boolean.'));
    }

    return array(
      'changeset' => $changeset,
      'line' => $line,
      'length' => $length,
      'isNewFile' => $is_new_file,
    );
  }

  private function loadReplyToComment(
    PhabricatorUser $viewer,
    DifferentialRevision $revision,
    $reply_phid) {

    if (!is_string($reply_phid)) {
      throw new Exception(
        pht(
          'Inline comment key "replyToCommentPHID" must be an inline '.
          'comment PHID.'));
    }

    $parent = id(new DifferentialDiffInlineCommentQuery())
      ->setViewer($viewer)
      ->withPHIDs(array($reply_phid))
      ->withRevisionPHIDs(array($revision->getPHID()))
      ->executeOne();
    if (!$parent) {
      throw new Exception(
        pht(
          'Inline comment "%s" does not exist on this revision.',
          $reply_phid));
    }

    if (!$parent->getTransactionPHID()) {
      throw new Exception(
        pht(
          'Inline comment "%s" is an unpublished draft and can not be '.
          'replied to.',
          $reply_phid));
    }

    if ($parent->getIsDeleted()) {
      throw new Exception(
        pht(
          'Inline comment "%s" has been deleted and can not be replied to.',
          $reply_phid));
    }

    return $parent;
  }

  /**
   * A reply is always placed where the comment it replies to is, the same
   * way the web UI does it (see T10562), so replies stay attached to their
   * thread as comments are ported across diffs. Location keys may be omitted,
   * but if they are given they must agree with the parent comment.
   */
  private function readReplyLocation(
    PhabricatorUser $viewer,
    DifferentialTransactionComment $parent,
    array $value) {

    $changeset = id(new DifferentialChangesetQuery())
      ->setViewer($viewer)
      ->withIDs(array($parent->getChangesetID()))
      ->executeOne();
    if (!$changeset) {
      throw new Exception(
        pht(
          'Unable to load the changeset for inline comment "%s".',
          $parent->getPHID()));
    }

    $location = array(
      'changeset' => $changeset,
      'line' => (int)$parent->getLineNumber(),
      'length' => (int)$parent->getLineLength() + 1,
      'isNewFile' => (bool)$parent->getIsNewFile(),
    );

    $mismatch = array();

    $diff_phid = idx($value, 'diff');
    if ($diff_phid !== null) {
      if ($diff_phid !== $changeset->getDiff()->getPHID()) {
        $mismatch[] = 'diff';
      }
    }

    $path = idx($value, 'path');
    if ($path !== null) {
      if (!is_string($path) || !$this->isChangesetPath($changeset, $path)) {
        $mismatch[] = 'path';
      }
    }

    foreach (array('line', 'length', 'isNewFile') as $key) {
      $given = idx($value, $key);
      if ($given !== null && $given !== $location[$key]) {
        $mismatch[] = $key;
      }
    }

    if ($mismatch) {
      throw new Exception(
        pht(
          'A reply is placed at the same location as the comment it '.
          'replies to. These keys do not match inline comment "%s": %s. '.
          'Omit them or use the values of the parent comment.',
          $parent->getPHID(),
          implode(', ', $mismatch)));
    }

    return $location;
  }

  private function readContentState(array $value) {
    $content = idx($value, 'content', '');
    if (!is_string($content)) {
      throw new Exception(
        pht('Inline comment key "content" must be a string.'));
    }

    $suggestion_text = idx($value, 'suggestionText');
    if ($suggestion_text !== null && !is_string($suggestion_text)) {
      throw new Exception(
        pht('Inline comment key "suggestionText" must be a string.'));
    }

    // "transaction.search" reports "suggestionText" as an empty string when
    // there is no suggestion, so "hasSuggestion" decides when it is given.
    // An empty suggestion is valid, and suggests deleting the lines.
    $has_suggestion = idx($value, 'hasSuggestion');
    if ($has_suggestion === null) {
      $has_suggestion = ($suggestion_text !== null);
    } else if (!is_bool($has_suggestion)) {
      throw new Exception(
        pht('Inline comment key "hasSuggestion" must be a boolean.'));
    }

    $state = id(new PhabricatorDiffInlineCommentContentState())
      ->setContentText($content);

    if ($has_suggestion) {
      $state
        ->setContentHasSuggestion(true)
        ->setContentSuggestionText((string)$suggestion_text);
    }

    if ($state->isEmptyContentState()) {
      throw new Exception(
        pht(
          'Inline comment must have "content" or a non-empty suggestion.'));
    }

    return $state;
  }

  private function readPositiveInteger(array $value, $key) {
    $number = idx($value, $key);
    if ($number === null) {
      return null;
    }

    if (!is_int($number) || ($number < 1)) {
      throw new Exception(
        pht('Inline comment key "%s" must be a positive integer.', $key));
    }

    return $number;
  }

  private function isChangesetPath(DifferentialChangeset $changeset, $path) {
    return ($path === $changeset->getFilename()) ||
      ($path === $changeset->getDisplayFilename());
  }

}
