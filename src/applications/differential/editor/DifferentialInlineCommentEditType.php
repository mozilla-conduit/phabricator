<?php

/**
 * Builds a published inline comment transaction from a Conduit value.
 *
 * The value mirrors the "inline" transactions returned by "transaction.search"
 * so callers can read and write inline comments with the same shape.
 */
final class DifferentialInlineCommentEditType
  extends PhabricatorEditType {

  public function getTransactionValueFromConduit($value) {
    $field = $this->getEditField();
    $viewer = $field->getViewer();
    $revision = $field->getObject();

    if (!$revision->getID()) {
      throw new Exception(
        pht(
          'Inline comments can not be added while creating a revision. '.
          'Create the revision first, then add inline comments to it.'));
    }

    if (!is_array($value) || ($value && phutil_is_natural_list($value))) {
      throw new Exception(
        pht('Inline comment value must be a dictionary.'));
    }

    $valid_keys = array(
      'diff',
      'path',
      'line',
      'length',
      'isNewFile',
      'content',
      'suggestionText',
      'replyToCommentPHID',
    );
    $unknown_keys = array_diff(array_keys($value), $valid_keys);
    if ($unknown_keys) {
      throw new Exception(
        pht(
          'Inline comment value has unknown keys: %s. Valid keys are: %s.',
          implode(', ', $unknown_keys),
          implode(', ', $valid_keys)));
    }

    $content = idx($value, 'content', '');
    if (!is_string($content)) {
      throw new Exception(
        pht('Inline comment "content" must be a string.'));
    }

    $suggestion_text = idx($value, 'suggestionText');
    if ($suggestion_text !== null && !is_string($suggestion_text)) {
      throw new Exception(
        pht('Inline comment "suggestionText" must be a string.'));
    }

    // An empty suggestion is valid (it deletes the commented lines), but the
    // comment must say something.
    if (!strlen($content) && !strlen((string)$suggestion_text)) {
      throw new Exception(
        pht(
          'Inline comment must have a nonempty "content" or '.
          '"suggestionText".'));
    }

    $reply_to = null;
    $reply_phid = idx($value, 'replyToCommentPHID');
    if ($reply_phid !== null) {
      if (!is_string($reply_phid)) {
        throw new Exception(
          pht('Inline comment "replyToCommentPHID" must be a string.'));
      }

      $reply_to = id(new DifferentialDiffInlineCommentQuery())
        ->setViewer($viewer)
        ->withRevisionPHIDs(array($revision->getPHID()))
        ->withPHIDs(array($reply_phid))
        ->withPublishedComments(true)
        ->executeOne();
      if (!$reply_to) {
        throw new Exception(
          pht(
            'Inline comment "replyToCommentPHID" ("%s") is not a published '.
            'inline comment on this revision.',
            $reply_phid));
      }
    }

    $path = idx($value, 'path');
    $diff_ref = idx($value, 'diff');

    if ($path === null) {
      // A reply without a path goes on the same lines as the comment it
      // replies to.
      if (!$reply_to) {
        throw new Exception(
          pht('Inline comment must have a "path".'));
      }

      if ($diff_ref !== null) {
        throw new Exception(
          pht('Inline comment with a "diff" must also have a "path".'));
      }

      $changeset_id = $reply_to->getChangesetID();
      $default_line = (int)$reply_to->getLineNumber();
      $default_length = (int)$reply_to->getLineLength() + 1;
      $default_is_new = (bool)$reply_to->getIsNewFile();
    } else {
      if (!is_string($path) || !strlen($path)) {
        throw new Exception(
          pht('Inline comment "path" must be a nonempty string.'));
      }

      $diff = $this->loadDiff($revision, $diff_ref);
      $changeset = $this->findChangeset($diff, $path);
      if (!$changeset) {
        throw new Exception(
          pht(
            'Inline comment "path" ("%s") is not a file changed by diff %s.',
            $path,
            $diff->getID()));
      }

      $changeset_id = $changeset->getID();
      $default_line = null;
      $default_length = 1;
      $default_is_new = true;
    }

    $line = $this->readInt(idx($value, 'line', $default_line));
    if ($line === null) {
      throw new Exception(
        pht('Inline comment must have a "line".'));
    }
    if ($line === false || $line < 1) {
      throw new Exception(
        pht('Inline comment "line" must be a positive integer.'));
    }

    $length = $this->readInt(idx($value, 'length', $default_length));
    if (!is_int($length) || $length < 1) {
      throw new Exception(
        pht('Inline comment "length" must be a positive integer.'));
    }

    $is_new = $this->readBool(idx($value, 'isNewFile', $default_is_new));
    if ($is_new === null) {
      throw new Exception(
        pht('Inline comment "isNewFile" must be a boolean.'));
    }

    return array(
      'changesetID' => $changeset_id,
      'isNewFile' => $is_new,
      'lineNumber' => $line,
      // Storage counts the lines after the first, like "transaction.search"
      // reports "length" as this value plus one.
      'lineLength' => $length - 1,
      'content' => $content,
      'suggestionText' => $suggestion_text,
      'replyTo' => $reply_to,
    );
  }

  public function generateTransactions(
    PhabricatorApplicationTransaction $template,
    array $spec) {

    $field = $this->getEditField();
    $viewer = $field->getViewer();
    $revision = $field->getObject();

    $value = $spec['value'];
    $reply_to = $value['replyTo'];

    $inline = id(new DifferentialInlineComment())
      ->setRevision($revision)
      ->setChangesetID($value['changesetID'])
      ->setIsNewFile((int)$value['isNewFile'])
      ->setLineNumber($value['lineNumber'])
      ->setLineLength($value['lineLength']);

    if ($reply_to) {
      $inline->setReplyToCommentPHID($reply_to->getPHID());
    }

    $state = $inline->getContentState()
      ->setContentText($value['content']);
    if ($value['suggestionText'] !== null) {
      $state
        ->setContentHasSuggestion(true)
        ->setContentSuggestionText($value['suggestionText']);
    }
    $inline->setContentState($state);

    // Like the web UI, the author's own inlines start out as "Done".
    if ($viewer->getPHID() == $revision->getAuthorPHID()) {
      $inline->setFixedState(PhabricatorInlineComment::STATE_DONE);
    }

    $comment = $inline->getStorageObject()
      ->attachReplyToComment($reply_to);

    $xaction = $this->newTransaction($template)
      ->attachComment($comment);

    return array($xaction);
  }

  private function loadDiff(DifferentialRevision $revision, $diff_ref) {
    $viewer = $this->getEditField()->getViewer();

    $query = id(new DifferentialDiffQuery())
      ->setViewer($viewer)
      ->withRevisionIDs(array($revision->getID()))
      ->needChangesets(true);

    $diff_id = $this->readInt($diff_ref);
    if ($diff_ref === null) {
      $query->withPHIDs(array($revision->getActiveDiffPHID()));
    } else if (is_int($diff_id)) {
      $query->withIDs(array($diff_id));
    } else if (is_string($diff_ref) && strlen($diff_ref)) {
      $query->withPHIDs(array($diff_ref));
    } else {
      throw new Exception(
        pht('Inline comment "diff" must be a diff ID or PHID.'));
    }

    $diff = $query->executeOne();
    if (!$diff) {
      if ($diff_ref === null) {
        throw new Exception(
          pht('Unable to load the active diff for this revision.'));
      }

      throw new Exception(
        pht(
          'Inline comment "diff" ("%s") is not a diff of this revision.',
          $diff_ref));
    }

    return $diff;
  }

  private function findChangeset(DifferentialDiff $diff, $path) {
    foreach ($diff->getChangesets() as $changeset) {
      if ($changeset->getFilename() === $path) {
        return $changeset;
      }
    }

    // Also accept the display name that "transaction.search" reports.
    foreach ($diff->getChangesets() as $changeset) {
      if ($changeset->getDisplayFilename() === $path) {
        return $changeset;
      }
    }

    return null;
  }

  /**
   * Read an integer, also accepting the digit strings sent by form-encoded
   * Conduit calls. Returns null for null and false for anything else.
   */
  private function readInt($value) {
    if ($value === null || is_int($value)) {
      return $value;
    }

    if (is_string($value) && ctype_digit($value)) {
      return (int)$value;
    }

    return false;
  }

  /**
   * Read a boolean, also accepting the strings sent by form-encoded Conduit
   * calls. Returns null for anything else.
   */
  private function readBool($value) {
    if (is_bool($value)) {
      return $value;
    }

    $map = array(
      'true' => true,
      '1' => true,
      'false' => false,
      '0' => false,
    );

    if (is_string($value) && isset($map[$value])) {
      return $map[$value];
    }

    return null;
  }

}
