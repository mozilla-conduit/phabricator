<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

final class RevisionMergeConflictEngineTestCase extends PhabricatorTestCase {

  public function testParseGitVersion() {
    $cases = array(
      'git version 2.30.2' => '2.30.2',
      'git version 2.39.5 (Apple Git-154)' => '2.39.5',
      "git version 2.45.1\n" => '2.45.1',
      'unexpected output' => '0.0.0',
      '' => '0.0.0',
    );

    foreach ($cases as $input => $expected) {
      $this->assertEqual(
        $expected,
        RevisionMergeConflictEngine::parseGitVersion($input),
        pht('Parsing version from "%s".', $input));
    }
  }

  public function testMinimumGitVersion() {
    // `--write-tree` needs git 2.38 and `--merge-base` needs 2.40, so 2.40 is
    // the floor. Anything older cannot be checked at all.
    $modern = RevisionMergeConflictEngine::MINIMUM_GIT_VERSION;

    $this->assertTrue(
      version_compare('2.40.0', $modern, '>='),
      pht('git 2.40.0 meets the minimum.'));
    $this->assertTrue(
      version_compare('2.45.1', $modern, '>='),
      pht('git 2.45.1 meets the minimum.'));
    $this->assertFalse(
      version_compare('2.39.5', $modern, '>='),
      pht('git 2.39.5 is below the minimum and must be rejected.'));
    $this->assertFalse(
      version_compare('2.30.2', $modern, '>='),
      pht('git 2.30.2 is below the minimum and must be rejected.'));
    $this->assertFalse(
      version_compare('0.0.0', $modern, '>='),
      pht('An unparseable version must be rejected rather than assumed good.'));
  }

  public function testHasContainingBranch() {
    $this->assertTrue(
      RevisionMergeConflictEngine::hasContainingBranch("refs/heads/main\n"),
      pht(
        'A base on a fetched branch other than the target is checked, since '.
        'the merge answers whether the stack rebases onto the target.'));

    $this->assertTrue(
      RevisionMergeConflictEngine::hasContainingBranch(
        "refs/heads/autoland\nrefs/heads/main\n"),
      pht('A base on several fetched branches is checked.'));

    $this->assertFalse(
      RevisionMergeConflictEngine::hasContainingBranch(''),
      pht(
        'A base on no fetched branch, such as a force-pushed commit, must '.
        'not be checked.'));

    $this->assertFalse(
      RevisionMergeConflictEngine::hasContainingBranch("\n"),
      pht('Blank output means no branch contains the base.'));
  }

  public function testIsAncestorExitCode() {
    $this->assertTrue(
      RevisionMergeConflictEngine::isAncestorExitCode(0),
      pht('A zero exit proves the base is on the target branch.'));

    $this->assertFalse(
      RevisionMergeConflictEngine::isAncestorExitCode(1),
      pht('An exit code of `1` means the base is not on the target branch.'));

    $this->assertFalse(
      RevisionMergeConflictEngine::isAncestorExitCode(128),
      pht(
        'A git error means ancestry is unproven, which must be treated the '.
        'same as a base that is not on the target branch.'));
  }

  public function testBaseOnTargetBranchIsAccepted() {
    $fixture = PhutilDirectoryFixture::newEmptyFixture();
    $path = $fixture->getPath();

    execx('git -C %s init -q -b autoland', $path);
    $root = $this->commit($path, 'root');
    $target_tip = $this->commit($path, 'autoland tip');

    $this->newEngine($path)->requireBaseOnFetchedBranch($root, $target_tip);

    $this->assertTrue(
      true,
      pht('A base that is an ancestor of the target tip is checked.'));
  }

  public function testBaseOnAnotherFetchedBranchIsAccepted() {
    $fixture = PhutilDirectoryFixture::newEmptyFixture();
    $path = $fixture->getPath();

    execx('git -C %s init -q -b autoland', $path);
    $this->commit($path, 'root');
    execx('git -C %s checkout -q -b main', $path);
    $main_only = $this->commit($path, 'main only');
    execx('git -C %s checkout -q autoland', $path);
    $target_tip = $this->commit($path, 'autoland tip');

    $this->newEngine($path)->requireBaseOnFetchedBranch(
      $main_only,
      $target_tip);

    $this->assertTrue(
      true,
      pht(
        'A base on `main` but not `autoland` is checked, since the merge '.
        'answers whether the stack rebases onto the target.'));
  }

  public function testBaseOnNoFetchedBranchIsRefused() {
    $fixture = PhutilDirectoryFixture::newEmptyFixture();
    $path = $fixture->getPath();

    execx('git -C %s init -q -b autoland', $path);
    $root = $this->commit($path, 'root');
    $target_tip = $this->commit($path, 'autoland tip');

    // `commit-tree` writes a commit that no branch points at, like one left
    // behind by a force-push.
    list($stdout) = execx(
      'git -C %s -c user.name=Test -c user.email=test@example.com '.
      'commit-tree %s -p %s -m %s',
      $path,
      $root.'^{tree}',
      $root,
      'unreachable');
    $unreachable = trim($stdout);

    $caught = null;
    try {
      $this->newEngine($path)->requireBaseOnFetchedBranch(
        $unreachable,
        $target_tip);
    } catch (RevisionMergeConflictReasonException $ex) {
      $caught = $ex;
    }

    $this->assertTrue(
      ($caught instanceof RevisionMergeConflictReasonException),
      pht('A base on no fetched branch must not be checked.'));
  }

  public function testTemporaryIndexIsRemovedWhenClosed() {
    $engine = new RevisionMergeConflictEngine();

    $index_path = $engine->openTemporaryIndex();
    $directory = dirname($index_path);

    $this->assertTrue(
      Filesystem::pathExists($directory),
      pht('Opening the temporary index should create its directory.'));

    $this->assertFalse(
      Filesystem::pathExists($index_path),
      pht(
        'The index should not exist yet, since git rejects an empty '.
        'placeholder file.'));

    Filesystem::writeFile($index_path, 'index written by git');
    Filesystem::writeFile($index_path.'.lock', 'lock left by a killed git');

    $engine->closeTemporaryIndex();

    $this->assertFalse(
      Filesystem::pathExists($directory),
      pht(
        'Closing the temporary index should remove its directory and '.
        'everything git wrote there, without waiting for the process to '.
        'exit.'));

    $engine->closeTemporaryIndex();
  }

  public function testHasRelevantChangedPath() {
    $stack_paths = array(
      'dom/base/Document.cpp' => true,
      'moz.configure' => true,
    );

    $cases = array(
      array(
        array('README.md' => 'M', 'dom/base/Document.cpp' => 'M'),
        true,
        pht('A changed file the stack touches should be relevant.'),
      ),
      array(
        array('README.md' => 'M', 'dom/base/Document.h' => 'M'),
        false,
        pht(
          'Modifying files the stack does not touch, even beside a stack '.
          'file, cannot change how the stack merges.'),
      ),
      array(
        array('dom/base/Document.h' => 'A'),
        true,
        pht(
          'A file added beside a stack file can make `merge-tree` detect a '.
          'directory rename.'),
      ),
      array(
        array('dom/base/test/test_old.html' => 'D'),
        true,
        pht(
          'A file deleted anywhere under a stack file\'s directory can '.
          'complete a directory rename.'),
      ),
      array(
        array('dom/base' => 'A'),
        true,
        pht('A file added where the stack has a directory clashes with it.'),
      ),
      array(
        array('moz.configure/extra' => 'A'),
        true,
        pht('A file added below a stack file clashes with it.'),
      ),
      array(
        array('dom/events/Event.cpp' => 'A', 'NEWS' => 'D'),
        false,
        pht(
          'Files added or deleted outside the directories holding stack '.
          'files are irrelevant, even under a shared parent directory or at '.
          'the root.'),
      ),
    );

    foreach ($cases as $case) {
      list($changed_paths, $expected, $message) = $case;

      $this->assertEqual(
        $expected,
        RevisionMergeConflictEngine::hasRelevantChangedPath(
          $changed_paths,
          $stack_paths),
        $message);
    }
  }

  public function testGetAncestorDirectories() {
    $this->assertEqual(
      array('dom/base', 'dom'),
      RevisionMergeConflictEngine::getAncestorDirectories(
        'dom/base/Document.cpp'),
      pht('Directories should be listed nearest first.'));

    $this->assertEqual(
      array(),
      RevisionMergeConflictEngine::getAncestorDirectories('moz.configure'),
      pht('A file at the root should have no directories listed.'));
  }

  public function testListChangedPathsBetweenTips() {
    $fixture = PhutilDirectoryFixture::newEmptyFixture();
    $path = $fixture->getPath();

    execx('git -C %s init -q -b autoland', $path);
    Filesystem::writeFile($path.'/kept.txt', 'kept');
    Filesystem::writeFile($path.'/edited.txt', 'edited');
    Filesystem::writeFile($path.'/renamed.txt', 'renamed');
    execx('git -C %s add -A', $path);
    $old_tip = $this->commit($path, 'old tip');

    Filesystem::createDirectory($path.'/dir');
    Filesystem::writeFile($path.'/dir/added.txt', 'added');
    Filesystem::writeFile($path.'/edited.txt', 'edited again');
    execx('git -C %s add -A', $path);
    execx('git -C %s mv renamed.txt moved.txt', $path);
    $new_tip = $this->commit($path, 'new tip');

    $changed_paths = $this->newEngine($path)->listChangedPaths(
      $old_tip,
      $new_tip);
    ksort($changed_paths);

    $this->assertEqual(
      array(
        'dir/added.txt' => 'A',
        'edited.txt' => 'M',
        'moved.txt' => 'A',
        'renamed.txt' => 'D',
      ),
      $changed_paths,
      pht(
        'Changed files should be listed by full path with their status, a '.
        'rename as a deletion and an addition, and unchanged files left out.'));

    $this->assertEqual(
      array(),
      $this->newEngine($path)->listChangedPaths($new_tip, $new_tip),
      pht('A tip that has not moved should have no changed files.'));
  }

  public function testListChangedPathsAfterAForcePush() {
    $fixture = PhutilDirectoryFixture::newEmptyFixture();
    $path = $fixture->getPath();

    execx('git -C %s init -q -b autoland', $path);
    $root = $this->commit($path, 'root');
    $old_tip = $this->commit($path, 'discarded tip');
    execx('git -C %s reset -q --hard %s', $path, $root);
    $new_tip = $this->commit($path, 'replacement tip');

    $this->assertEqual(
      null,
      $this->newEngine($path)->listChangedPaths($old_tip, $new_tip),
      pht(
        'A tip that does not descend from the old one should be reported as '.
        'unknown, so the stored verdict is not reused.'));
  }

  private function commit(string $path, string $message): string {
    execx(
      'git -C %s -c user.name=Test -c user.email=test@example.com '.
      'commit -q --allow-empty -m %s',
      $path,
      $message);

    list($stdout) = execx('git -C %s rev-parse HEAD', $path);
    return trim($stdout);
  }

  private function newEngine(string $path): RevisionMergeConflictEngine {
    $repository = id(new PhabricatorRepository())
      ->setVersionControlSystem(PhabricatorRepositoryType::REPOSITORY_TYPE_GIT)
      ->setLocalPath($path);

    return id(new RevisionMergeConflictEngine())
      ->setRepository($repository);
  }

}
