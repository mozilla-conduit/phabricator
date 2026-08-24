<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

final class ResolveCommentsTestCase extends PhabricatorTestCase {

  public function testExtractSuggestionWithSuggestion() {
    $comment = new DifferentialTransactionComment();
    $comment->setAttribute('inline.state', array(
      'text' => 'change this',
      'hasSuggestion' => true,
      'suggestionText' => 'return $x + 1;',
    ));

    [$hasSuggestion, $suggestionText] = ResolveComments::extractSuggestion($comment);

    $this->assertTrue($hasSuggestion);
    $this->assertEqual('return $x + 1;', $suggestionText);
  }

  public function testExtractSuggestionWithoutInlineState() {
    $comment = new DifferentialTransactionComment();

    [$hasSuggestion, $suggestionText] = ResolveComments::extractSuggestion($comment);

    $this->assertFalse($hasSuggestion);
    $this->assertEqual('', $suggestionText);
  }

  public function testExtractSuggestionExplicitlyFalse() {
    $comment = new DifferentialTransactionComment();
    $comment->setAttribute('inline.state', array(
      'text' => 'just a comment',
      'hasSuggestion' => false,
      'suggestionText' => '',
    ));

    [$hasSuggestion, $suggestionText] = ResolveComments::extractSuggestion($comment);

    $this->assertFalse($hasSuggestion);
    $this->assertEqual('', $suggestionText);
  }
}
