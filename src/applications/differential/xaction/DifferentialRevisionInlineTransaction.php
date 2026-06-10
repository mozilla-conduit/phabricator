<?php

final class DifferentialRevisionInlineTransaction
  extends PhabricatorModularTransactionType {

  // NOTE: This class is NOT an actual Differential modular transaction type!
  // It does not extend "DifferentialRevisionTransactionType". Some day it
  // should, but for now it's just reducing the amount of hackiness around
  // supporting inline comments in the "transaction.search" Conduit API method.

  const TRANSACTIONTYPE = 'internal.pretend-inline';

  public function getTransactionTypeForConduit($xaction) {
    return 'inline';
  }

  public function loadTransactionTypeConduitData(array $xactions) {
    $viewer = $this->getViewer();

    $changeset_ids = array();
    $comment_map = array();
    foreach ($xactions as $xaction) {
      $comment = $xaction->getComment();
      $changeset_ids[] = $comment->getChangesetID();
      $comment_map[$comment->getID()] = $comment;
    }

    $changesets = id(new DifferentialChangesetQuery())
      ->setViewer($viewer)
      ->withIDs($changeset_ids)
      ->execute();

    $changesets = mpull($changesets, null, 'getID');

    // Load inline contexts using the same infrastructure as the web UI.
    // This handles changeset+hunk loading, simple-hunk checks, corpus
    // extraction, and caching internally.
    $inlines = id(new DifferentialDiffInlineCommentQuery())
      ->setViewer($viewer)
      ->withIDs(array_keys($comment_map))
      ->needInlineContext(true)
      ->execute();
    $inlines = mpull($inlines, null, 'getID');

    foreach ($comment_map as $id => $comment) {
      $inline = idx($inlines, $id);
      if ($inline) {
        $comment->attachInlineContext($inline->getInlineContext());
      } else {
        $comment->attachInlineContext(null);
      }
    }

    return $changesets;
  }

  public function getFieldValuesForConduit($object, $data) {
    $comment = $object->getComment();

    $changeset = $data[$comment->getChangesetID()];
    $diff = $changeset->getDiff();

    $is_done = false;
    switch ($comment->getFixedState()) {
      case PhabricatorInlineComment::STATE_DONE:
      case PhabricatorInlineComment::STATE_UNDRAFT:
        $is_done = true;
        break;
    }

    $inline = $comment->newInlineCommentObject();
    $content_state = $inline->getContentState();
    $has_suggestion = $content_state->getContentHasSuggestion();

    $suggestion_original = null;
    if ($has_suggestion) {
      $context = $comment->getInlineContext();
      if ($context) {
        $body_lines = $context->getBodyLines();
        $suggestion_original = implode('', $body_lines);
      }
    }

    return array(
      'diff' => array(
        'id' => (int)$diff->getID(),
        'phid' => $diff->getPHID(),
      ),
      'path' => $changeset->getDisplayFilename(),
      'line' => (int)$comment->getLineNumber(),
      'length' => (int)($comment->getLineLength() + 1),
      'replyToCommentPHID' => $comment->getReplyToCommentPHID(),
      'isDone' => $is_done,
      'hasSuggestion' => $has_suggestion,
      'suggestionText' => $has_suggestion
        ? $content_state->getContentSuggestionText()
        : null,
      'suggestionOriginal' => $suggestion_original,
    );
  }

}
