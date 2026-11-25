<?php

final class DifferentialDiffExtractionEngineTestCase
  extends PhabricatorTestCase {

  protected function getPhabricatorTestCaseConfiguration() {
    return array(
      self::PHABRICATOR_TESTCONFIG_BUILD_STORAGE_FIXTURES => true,
    );
  }

  public function testAttachBinaryFilesToDiffClearsTTL() {
    $ttl = PhabricatorTime::getNow() + phutil_units('48 hours in seconds');
    $params = array(
      'name' => 'test.whl',
      'ttl.absolute' => $ttl,
      'viewPolicy' => PhabricatorPolicies::POLICY_NOONE,
      'storageEngines' => array(
        new PhabricatorTestStorageEngine(),
      ),
    );

    $old_file = PhabricatorFile::newFromFileData('old', $params);
    $new_file = PhabricatorFile::newFromFileData('new', $params);

    $changeset = id(new DifferentialChangeset())
      ->setMetadata(
        array(
          'old:binary-phid' => $old_file->getPHID(),
          'new:binary-phid' => $new_file->getPHID(),
        ));

    $diff_phid = PhabricatorPHID::generateNewPHID(
      DifferentialDiffPHIDType::TYPECONST);
    $diff = id(new DifferentialDiff())
      ->setPHID($diff_phid)
      ->attachChangesets(array($changeset));

    id(new DifferentialDiffExtractionEngine())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->attachBinaryFilesToDiff($diff);

    $files = id(new PhabricatorFileQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withAttachedObjectPHIDs(array($diff_phid))
      ->execute();

    $this->assertEqual(
      2,
      count($files),
      pht('Both binary files should be attached to the `commit` diff.'));
    foreach ($files as $file) {
      $this->assertEqual(
        null,
        $file->getTTL(),
        pht(
          'Binary files attached to a `commit` diff should not expire, '.
          'or the garbage collector deletes them.'));
    }
  }

}
