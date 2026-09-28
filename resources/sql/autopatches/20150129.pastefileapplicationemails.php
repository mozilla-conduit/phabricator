<?php

$key_files = 'metamta.files.public-create-email';
echo pht(
  "Migrating `%s` to new application email infrastructure...\n",
  $key_files);

$value_files = PhabricatorEnv::getEnvConfigIfExists($key_files);
$files_app = new PhabricatorFilesApplication();

if ($value_files) {
  try {
    PhabricatorMetaMTAApplicationEmail::initializeNewAppEmail(
      PhabricatorUser::getOmnipotentUser())
      ->setAddress($value_files)
      ->setApplicationPHID($files_app->getPHID())
      ->save();
  } catch (AphrontDuplicateKeyQueryException $ex) {
    // Already migrated?
  }
}

echo pht('Done.')."\n";
