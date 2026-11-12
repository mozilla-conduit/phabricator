<?php

abstract class PhabricatorApplicationTransactionReplyHandler
  extends PhabricatorMailReplyHandler {

  abstract public function getObjectPrefix();

  public function getPrivateReplyHandlerEmailAddress(
    PhabricatorUser $user) {
    return $this->getDefaultPrivateReplyHandlerEmailAddress(
      $user,
      $this->getObjectPrefix());
  }

  public function getPublicReplyHandlerEmailAddress() {
    return $this->getDefaultPublicReplyHandlerEmailAddress(
      $this->getObjectPrefix());
  }

}
