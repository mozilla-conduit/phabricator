<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

final class EmailInlineCommentTestCase extends PhabricatorTestCase {

  private function newMessage(): EmailCommentMessage {
    return new EmailCommentMessage('text', '<p>text</p>');
  }

  private function newCodeContext(): EmailCodeContext {
    return new EmailCodeContext([]);
  }

  public function testDefaultsToNoSuggestion() {
    $comment = new EmailInlineComment(
      '/src/foo.php:10',
      'https://example.com/D1',
      $this->newMessage(),
      'code',
      $this->newCodeContext()
    );

    $this->assertFalse($comment->hasSuggestion);
    $this->assertEqual('', $comment->suggestionText);
  }

  public function testStoresSuggestion() {
    $comment = new EmailInlineComment(
      '/src/foo.php:10',
      'https://example.com/D1',
      $this->newMessage(),
      'code',
      $this->newCodeContext(),
      true,
      'return $x + 1;'
    );

    $this->assertTrue($comment->hasSuggestion);
    $this->assertEqual('return $x + 1;', $comment->suggestionText);
  }

  public function testNoSuggestionWithExplicitFalse() {
    $comment = new EmailInlineComment(
      '/src/foo.php:10',
      'https://example.com/D1',
      $this->newMessage(),
      'code',
      $this->newCodeContext(),
      false,
      ''
    );

    $this->assertFalse($comment->hasSuggestion);
    $this->assertEqual('', $comment->suggestionText);
  }

  public function testSuggestionSurvivesContentStateRoundTrip() {
    $state = id(new PhabricatorDiffInlineCommentContentState())
      ->setContentText('change this')
      ->setContentHasSuggestion(true)
      ->setContentSuggestionText('return $x + 1;');

    $map = $state->newStorageMap();
    $map = json_decode(json_encode($map), true);

    $loaded = id(new PhabricatorDiffInlineCommentContentState())
      ->readStorageMap($map);

    $this->assertTrue($loaded->getContentHasSuggestion());
    $this->assertEqual('return $x + 1;', $loaded->getContentSuggestionText());
  }

  public function testFileContextAndLinkStored() {
    $comment = new EmailInlineComment(
      '/src/bar.php:42',
      'https://example.com/D2#inline-99',
      $this->newMessage(),
      'reply',
      $this->newCodeContext()
    );

    $this->assertEqual('/src/bar.php:42', $comment->fileContext);
    $this->assertEqual('https://example.com/D2#inline-99', $comment->link);
    $this->assertEqual('reply', $comment->contextKind);
  }
}
