<?php

final class PhabricatorMacroEditController extends PhabricatorMacroController {

  public function handleRequest(AphrontRequest $request) {
    return id(new PhabricatorMacroEditEngine())
      ->setController($this)
      ->buildResponse();
  }
}
