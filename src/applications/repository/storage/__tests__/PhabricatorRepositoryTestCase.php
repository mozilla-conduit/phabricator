<?php

final class PhabricatorRepositoryTestCase
  extends PhabricatorTestCase {

  public function testRepositoryURIProtocols() {
    $tests = array(
      '/path/to/repo'               => 'file',
      'file:///path/to/repo'        => 'file',
      'ssh://user@domain.com/path'  => 'ssh',
      'git@example.com:path'        => 'ssh',
      'git://git@example.com/path'  => 'git',
      'https://example.com/repo/'   => 'https',
      'http://example.com/'         => 'http',
      'https://user@example.com/'   => 'https',
    );

    foreach ($tests as $uri => $expect) {
      $repository = new PhabricatorRepository();
      $repository->setDetail('remote-uri', $uri);

      $this->assertEqual(
        $expect,
        $repository->getRemoteProtocol(),
        pht("Protocol for '%s'.", $uri));
    }
  }

  public function testBranchFilter() {
    $git = PhabricatorRepositoryType::REPOSITORY_TYPE_GIT;

    $repo = new PhabricatorRepository();
    $repo->setVersionControlSystem($git);

    $this->assertTrue(
      $repo->shouldTrackBranch('imaginary'),
      pht('Track all branches by default.'));

    $repo->setTrackOnlyRules(array('master'));

    $this->assertTrue(
      $repo->shouldTrackBranch('master'),
      pht('Track listed branches.'));

    $this->assertFalse(
      $repo->shouldTrackBranch('imaginary'),
      pht('Do not track unlisted branches.'));
  }

  public function testRepositoryShortNameValidation() {
    $good = array(
      'sensible-repository',
      'AReasonableName',
      'ACRONYM-project',
      'sol-123',
      '46-helixes',
      'node.io',
      'internet.com',
      'www.internet-site.com.repository',
      'with_under-scores',

      // Can't win them all.
      'A-_._-_._-_._-_._-_._-_._-1',

      // 64-character names are fine.
      str_repeat('a', 64),
    );

    $poor = array(
      '',
      '1',
      '.',
      '-_-',
      'AAAA',
      '..',
      'a/b',
      '../../etc/passwd',
      '/',
      '!',
      '@',
      'ca$hmoney',
      'repo with spaces',
      'hyphen-',
      '-ated',
      '_underscores_',
      'yes!',
      'quack.git',
      'git.git',
      '.git.git.git',

      // 65-character names are no good.
      str_repeat('a', 65),
    );

    foreach ($good as $nice_name) {
      $actual = PhabricatorRepository::isValidRepositorySlug($nice_name);
      $this->assertEqual(
        true,
        $actual,
        pht(
          'Expected "%s" to be a valid repository short name.',
          $nice_name));
    }

    foreach ($poor as $poor_name) {
      $actual = PhabricatorRepository::isValidRepositorySlug($poor_name);
      $this->assertEqual(
        false,
        $actual,
        pht(
          'Expected "%s" to be rejected as an invalid repository '.
          'short name.',
          $poor_name));
    }
  }

}
