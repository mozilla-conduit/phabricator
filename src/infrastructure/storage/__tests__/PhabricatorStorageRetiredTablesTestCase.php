<?php

final class PhabricatorStorageRetiredTablesTestCase extends PhabricatorTestCase {

  public function testRetiredTableMapUsesFullDatabaseNames() {
    $patches = array(
      $this->newPatch('db', 'file', array('file_imagemacro')),
      $this->newPatch('db', 'user', array()),
      $this->newPatch('sql', 'patch.sql', array()),
    );

    // `setNamespace()` also pushes a global storage namespace, which must be
    // popped so later tests keep using their fixtures.
    $api = id(new PhabricatorStorageManagementAPI())
      ->setNamespace('retired');
    try {
      $map = $api->getRetiredTableMap($patches);
    } finally {
      PhabricatorLiskDAO::popStorageNamespace();
    }

    $this->assertEqual(
      array(
        'retired_file' => array('file_imagemacro' => 'file_imagemacro'),
      ),
      $map,
      pht(
        '`%s` should key retired tables by the namespaced database name '.
        'and skip databases without any.',
        'getRetiredTableMap()'));
  }

  private function newPatch($type, $name, array $retired) {
    return new PhabricatorStoragePatch(
      array(
        'key' => $name,
        'fullKey' => 'phabricator:'.$name,
        'type' => $type,
        'name' => $name,
        'after' => array(),
        'legacy' => false,
        'dead' => false,
        'retired' => $retired,
        'phase' => PhabricatorStoragePatch::getDefaultPhase(),
      ));
  }

}
