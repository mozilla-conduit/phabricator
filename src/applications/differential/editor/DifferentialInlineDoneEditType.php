<?php

/**
 * Builds an inline state transaction from a Conduit value, marking one
 * published inline comment as "Done" or "Not Done".
 */
final class DifferentialInlineDoneEditType
  extends PhabricatorEditType {

  public function getTransactionValueFromConduit($value) {
    $field = $this->getEditField();
    $viewer = $field->getViewer();
    $revision = $field->getObject();

    if (!$revision->getID()) {
      throw new Exception(
        pht(
          'Inline comments can not be marked as done while creating a '.
          'revision.'));
    }

    if (!is_array($value) || ($value && phutil_is_natural_list($value))) {
      throw new Exception(
        pht('Inline done value must be a dictionary.'));
    }

    $valid_keys = array(
      'commentPHID',
      'done',
    );
    $unknown_keys = array_diff(array_keys($value), $valid_keys);
    if ($unknown_keys) {
      throw new Exception(
        pht(
          'Inline done value has unknown keys: %s. Valid keys are: %s.',
          implode(', ', $unknown_keys),
          implode(', ', $valid_keys)));
    }

    $comment_phid = idx($value, 'commentPHID');
    if (!is_string($comment_phid) || !strlen($comment_phid)) {
      throw new Exception(
        pht('Inline done value must have a "commentPHID" string.'));
    }

    $is_done = $this->readBool(idx($value, 'done'));
    if ($is_done === null) {
      throw new Exception(
        pht('Inline done value must have a boolean "done".'));
    }

    // Like the web UI, only the revision author can mark published inline
    // comments as "Done".
    if ($viewer->getPHID() != $revision->getAuthorPHID()) {
      throw new Exception(
        pht(
          'Only the author of a revision can mark its inline comments as '.
          'done.'));
    }

    $inline = id(new DifferentialDiffInlineCommentQuery())
      ->setViewer($viewer)
      ->withRevisionPHIDs(array($revision->getPHID()))
      ->withPHIDs(array($comment_phid))
      ->withPublishedComments(true)
      ->executeOne();
    if (!$inline) {
      throw new Exception(
        pht(
          'Inline done "commentPHID" ("%s") is not a published inline '.
          'comment on this revision.',
          $comment_phid));
    }

    if ($is_done) {
      $state = PhabricatorInlineComment::STATE_DONE;
    } else {
      $state = PhabricatorInlineComment::STATE_UNDONE;
    }

    return array(
      'inline' => $inline,
      'state' => $state,
    );
  }

  public function generateTransactions(
    PhabricatorApplicationTransaction $template,
    array $spec) {

    $value = $spec['value'];
    $inline = $value['inline'];
    $phid = $inline->getPHID();

    // See PHI995. Like the automatic inline state transaction, record the
    // inline authors so marking your own inlines as "Done" is not rendered.
    $inline_details = array(
      $phid => array(
        'authorPHID' => $inline->getAuthorPHID(),
      ),
    );

    $xaction = $this->newTransaction($template)
      ->setIgnoreOnNoEffect(true)
      ->setMetadataValue('inline.explicit', true)
      ->setMetadataValue('inline.details', $inline_details)
      ->setOldValue(array($phid => $inline->getFixedState()))
      ->setNewValue(array($phid => $value['state']));

    return array($xaction);
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
