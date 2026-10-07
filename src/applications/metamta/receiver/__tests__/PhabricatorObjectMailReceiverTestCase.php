<?php

final class PhabricatorObjectMailReceiverTestCase
  extends PhabricatorTestCase {

  protected function getPhabricatorTestCaseConfiguration() {
    return array(
      self::PHABRICATOR_TESTCONFIG_BUILD_STORAGE_FIXTURES => true,
    );
  }

  public function testDropUnconfiguredPublicMail() {
    list($file, $user, $mail) = $this->buildMail('public');

    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('metamta.public-replies', false);

    $mail->save();
    $mail->processReceivedMail();

    $this->assertEqual(
      MetaMTAReceivedMailStatus::STATUS_NO_PUBLIC_MAIL,
      $mail->getStatus());
  }

  public function testDropPolicyViolationMail() {
    list($file, $user, $mail) = $this->buildMail('policy');

    $file
      ->setViewPolicy(PhabricatorPolicies::POLICY_NOONE)
      ->save();

    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('metamta.public-replies', true);

    $mail->save();
    $mail->processReceivedMail();

    $this->assertEqual(
      MetaMTAReceivedMailStatus::STATUS_POLICY_PROBLEM,
      $mail->getStatus());
  }

  public function testDropInvalidObjectMail() {
    list($file, $user, $mail) = $this->buildMail('404');

    $mail->save();
    $mail->processReceivedMail();

    $this->assertEqual(
      MetaMTAReceivedMailStatus::STATUS_NO_SUCH_OBJECT,
      $mail->getStatus());
  }

  public function testDropUserMismatchMail() {
    list($file, $user, $mail) = $this->buildMail('baduser');

    $mail->save();
    $mail->processReceivedMail();

    $this->assertEqual(
      MetaMTAReceivedMailStatus::STATUS_USER_MISMATCH,
      $mail->getStatus());
  }

  public function testDropHashMismatchMail() {
    list($file, $user, $mail) = $this->buildMail('badhash');

    $mail->save();
    $mail->processReceivedMail();

    $this->assertEqual(
      MetaMTAReceivedMailStatus::STATUS_HASH_MISMATCH,
      $mail->getStatus());
  }

  private function buildMail($style) {
    $user = $this->generateNewTestUser();

    // The file is authored by someone else, so the sender does not get an
    // automatic view capability on it.
    $author = $this->generateNewTestUser();
    $file = PhabricatorFile::newFromFileData(
      Filesystem::readRandomCharacters(64),
      array(
        'name' => 'mail.dat',
        'viewPolicy' => PhabricatorPolicies::POLICY_USER,
        'authorPHID' => $author->getPHID(),
        'storageEngines' => array(
          new PhabricatorTestStorageEngine(),
        ),
      ));

    $is_public = ($style === 'public');
    $is_bad_hash = ($style == 'badhash');
    $is_bad_user = ($style == 'baduser');
    $is_404_object = ($style == '404');

    if ($is_public) {
      $user_identifier = 'public';
    } else if ($is_bad_user) {
      $user_identifier = $user->getID() + 1;
    } else {
      $user_identifier = $user->getID();
    }

    if ($is_bad_hash) {
      $hash = PhabricatorObjectMailReceiver::computeMailHash('x', 'y');
    } else {

      $mail_key = PhabricatorMetaMTAMailProperties::loadMailKey($file);

      $hash = PhabricatorObjectMailReceiver::computeMailHash(
        $mail_key,
        $is_public ? $file->getPHID() : $user->getPHID());
    }

    if ($is_404_object) {
      $file_identifier = 'F'.($file->getID() + 1);
    } else {
      $file_identifier = 'F'.$file->getID();
    }

    $to = $file_identifier.'+'.$user_identifier.'+'.$hash.'@example.com';

    $mail = new PhabricatorMetaMTAReceivedMail();
    $mail->setHeaders(
      array(
        'Message-ID' => 'test@example.com',
        'From'       => $user->loadPrimaryEmail()->getAddress(),
        'To'         => $to,
      ));

    $mail->setBodies(
      array(
        'text' => 'test',
      ));

    return array($file, $user, $mail);
  }


}
