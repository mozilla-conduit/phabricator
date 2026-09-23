<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

final class DifferentialTransactionCommentTestCase extends PhabricatorTestCase {

  public function testSuggestionText() {
    $comment = new DifferentialTransactionComment();
    $comment->setAttribute('inline.state', array(
      'text' => 'change this',
      'hasSuggestion' => true,
      'suggestionText' => 'return $x + 1;',
    ));

    $this->assertEqual('return $x + 1;', $comment->getSuggestionText());
  }

  public function testSuggestionTextForSuggestionOnlyComment() {
    $comment = new DifferentialTransactionComment();
    $comment->setAttribute('inline.state', array(
      'text' => '',
      'hasSuggestion' => true,
      'suggestionText' => 'return $x + 1;',
    ));

    $this->assertEqual('return $x + 1;', $comment->getSuggestionText());
  }

  public function testSuggestionTextForDeletion() {
    $comment = new DifferentialTransactionComment();
    $comment->setAttribute('inline.state', array(
      'text' => 'drop this line',
      'hasSuggestion' => true,
      'suggestionText' => '',
    ));

    $this->assertEqual('', $comment->getSuggestionText());
  }

  public function testSuggestionTextWithoutSuggestion() {
    $comment = new DifferentialTransactionComment();
    $comment->setAttribute('inline.state', array(
      'text' => 'just a comment',
      'hasSuggestion' => false,
      'suggestionText' => '',
    ));

    $this->assertEqual(null, $comment->getSuggestionText());
  }

  public function testSuggestionTextWithoutContentState() {
    $comment = new DifferentialTransactionComment();

    $this->assertEqual(null, $comment->getSuggestionText());
  }

  public function testSuggestionTextSurvivesStorageSerialization() {
    $state = id(new PhabricatorDiffInlineCommentContentState())
      ->setContentText('change this')
      ->setContentHasSuggestion(true)
      ->setContentSuggestionText('return $x + 1;');

    $storage_map = json_decode(json_encode($state->newStorageMap()), true);

    $comment = new DifferentialTransactionComment();
    $comment->setAttribute('inline.state', $storage_map);

    $this->assertEqual('return $x + 1;', $comment->getSuggestionText());
  }

}
