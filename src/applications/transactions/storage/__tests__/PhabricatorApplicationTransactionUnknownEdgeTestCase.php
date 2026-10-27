<?php

final class PhabricatorApplicationTransactionUnknownEdgeTestCase
  extends PhabricatorTestCase {

  public function testUnknownEdgeTypeRendersGenericTitles() {
    $author_phid = 'PHID-USER-author';
    $revision_phid = 'PHID-DREV-revision';
    $document_phid = 'PHID-LEGD-document';

    // Edge type 49 belonged to the removed Legalpad application.
    $xaction = id(new DifferentialTransaction())
      ->setTransactionType(PhabricatorTransactions::TYPE_EDGE)
      ->setAuthorPHID($author_phid)
      ->setObjectPHID($revision_phid)
      ->setOldValue(array())
      ->setNewValue(array($document_phid))
      ->setMetadataValue('edge:type', 49)
      ->setRenderingTarget(PhabricatorApplicationTransaction::TARGET_TEXT)
      ->setHandles(
        array(
          $author_phid => $this->newHandle($author_phid, 'alice'),
          $revision_phid => $this->newHandle($revision_phid, 'D123'),
          $document_phid => $this->newHandle($document_phid, 'L1'),
        ));

    $this->assertEqual(
      'alice edited an edge.',
      (string)$xaction->getTitle(),
      pht('`getTitle()` should recover from an unknown edge type.'));

    $this->assertEqual(
      'alice edited an edge of D123.',
      (string)$xaction->getTitleForFeed(),
      pht('`getTitleForFeed()` should recover from an unknown edge type.'));
  }

  private function newHandle($phid, $name) {
    return id(new PhabricatorObjectHandle())
      ->setPHID($phid)
      ->setName($name);
  }

}
