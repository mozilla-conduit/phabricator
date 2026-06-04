<?php


class EmailInlineComment {
  public string $fileContext;
  public string $link;
  public EmailCommentMessage $message;
  /** "reply" if context is EmailReplyContext, otherwise "code" */
  public string $contextKind;
  /** @var EmailReplyContext|EmailCodeContext */
  public $context;
  public bool $hasSuggestion;
  public string $suggestionText;

  public function __construct(string $fileContext, string $link, EmailCommentMessage $message, string $contextKind, $context, bool $hasSuggestion = false, string $suggestionText = '') {
    $this->fileContext = $fileContext;
    $this->link = $link;
    $this->message = $message;
    $this->contextKind = $contextKind;
    $this->context = $context;
    $this->hasSuggestion = $hasSuggestion;
    $this->suggestionText = $suggestionText;
  }
}
