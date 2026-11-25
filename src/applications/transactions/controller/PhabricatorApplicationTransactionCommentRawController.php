<?php

final class PhabricatorApplicationTransactionCommentRawController
  extends PhabricatorApplicationTransactionController {

  public function handleRequest(AphrontRequest $request) {
    $viewer = $this->getViewer();
    $phid = $request->getURIData('phid');

    $xaction = id(new PhabricatorObjectQuery())
      ->withPHIDs(array($phid))
      ->setViewer($viewer)
      ->executeOne();

    if (!$xaction) {
      return new Aphront404Response();
    }

    if (!$xaction->getComment()) {
      // You can't view a raw comment if there is no comment.
      return new Aphront404Response();
    }

    if ($xaction->getComment()->getIsRemoved()) {
      // You can't view a raw comment if the comment is deleted.
      return new Aphront400Response();
    }

    $obj_phid = $xaction->getObjectPHID();
    $obj_handle = id(new PhabricatorHandleQuery())
      ->setViewer($viewer)
      ->withPHIDs(array($obj_phid))
      ->executeOne();

    $title = pht('Raw Comment');
    $body = $xaction->getComment()->getContent();

    $dialog = id(new AphrontDialogView())
      ->setUser($viewer)
      ->addCancelButton($obj_handle->getURI())
      ->setTitle($title);

    $dialog
      ->addHiddenInput('anchor', $request->getStr('anchor'))
      ->appendChild(
        id(new PHUIFormLayoutView())
        ->setFullWidth(true)
        ->appendChild(
          id(new AphrontFormTextAreaControl())
          ->setReadOnly(true)
          ->setValue($body)));

    return id(new AphrontDialogResponse())->setDialog($dialog);
  }

  public function shouldAllowPublic() {
    return true;
  }

}
