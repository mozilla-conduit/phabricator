<?php


class EmailIDEndpointResponse {
  public EmailEndpointResponseData $data;
  public EmailIDEndpointResponseCursor $cursor;

  public function __construct(EmailEndpointResponseData $data, EmailIDEndpointResponseCursor $cursor) {
    $this->data = $data;
    $this->cursor = $cursor;
  }
}
