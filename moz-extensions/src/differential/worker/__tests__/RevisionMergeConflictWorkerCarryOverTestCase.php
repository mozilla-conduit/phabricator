<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * Exercises carrying a stored verdict over to a newer branch tip against a real
 * repository and a diff in storage, since deciding whether a landing matters
 * reads both the stack's changesets and the branch history.
 *
 * Every test starts from a stack that moves `a/moved.txt` to `b/moved.txt`,
 * checked cleanly against a branch whose tip is the stack's base.
 */
final class RevisionMergeConflictWorkerCarryOverTestCase
  extends PhabricatorTestCase {

  const RAW_MOVE_DIFF = <<<EODIFF
diff --git a/a/moved.txt b/b/moved.txt
similarity index 100%
rename from a/moved.txt
rename to b/moved.txt
EODIFF;

  protected function getPhabricatorTestCaseConfiguration() {
    return array(
      self::PHABRICATOR_TESTCONFIG_BUILD_STORAGE_FIXTURES => true,
    );
  }

  public function testVerdictIsCarriedOverPastAnUnrelatedLanding() {
    $fixture = PhutilDirectoryFixture::newEmptyFixture();
    $path = $fixture->getPath();
    $diff = $this->newMoveDiff($path);
    $stored = $this->newStoredResult($path, $diff);

    Filesystem::writeFile($path.'/unrelated.txt', 'changed');
    $new_tip = $this->commit($path, 'unrelated landing');

    $result = RevisionMergeConflictWorker::newCarriedOverResult(
      $stored,
      $diff,
      $this->newEngine($path, $diff));

    $this->assertEqual(
      DifferentialMergeConflictStatusField::STATUS_CLEAN,
      idx($result, 'status'),
      pht(
        'A landing that changed nothing the stack touches should carry the '.
        'stored verdict over.'));

    $this->assertEqual(
      $new_tip,
      idx($result, 'targetCommit'),
      pht(
        'A carried-over verdict should record the new tip, so the next '.
        'comparison starts from it.'));

    $this->assertTrue(
      strpos(idx($result, 'reason'), substr($new_tip, 0, 12)) !== false,
      pht(
        'The reason should name the tip the verdict now stands for: %s',
        idx($result, 'reason')));
  }

  public function testChangeToAMovedFileIsNotCarriedOver() {
    $fixture = PhutilDirectoryFixture::newEmptyFixture();
    $path = $fixture->getPath();
    $diff = $this->newMoveDiff($path);
    $stored = $this->newStoredResult($path, $diff);

    Filesystem::writeFile($path.'/a/moved.txt', 'edited');
    $this->commit($path, 'edit the moved file');

    $this->assertEqual(
      null,
      RevisionMergeConflictWorker::newCarriedOverResult(
        $stored,
        $diff,
        $this->newEngine($path, $diff)),
      pht(
        'A landing that edits the old path of a file the stack moves should '.
        'cost a full check.'));
  }

  public function testFileAddedAtAMoveDestinationIsNotCarriedOver() {
    $fixture = PhutilDirectoryFixture::newEmptyFixture();
    $path = $fixture->getPath();
    $diff = $this->newMoveDiff($path);
    $stored = $this->newStoredResult($path, $diff);

    Filesystem::createDirectory($path.'/b');
    Filesystem::writeFile($path.'/b/moved.txt', 'added');
    $this->commit($path, 'add a file where the stack moves one');

    $engine = $this->newEngine($path, $diff);

    $this->assertEqual(
      null,
      RevisionMergeConflictWorker::newCarriedOverResult(
        $stored,
        $diff,
        $engine),
      pht(
        'A landing that adds a file at the new path of a file the stack '.
        'moves should cost a full check.'));

    $this->assertEqual(
      DifferentialMergeConflictStatusField::STATUS_CONFLICT,
      idx($engine->executeCheck(), 'status'),
      pht('The full check should find the conflict the landing introduced.'));
  }

  public function testFileAddedBesideAMovedFileIsNotCarriedOver() {
    $fixture = PhutilDirectoryFixture::newEmptyFixture();
    $path = $fixture->getPath();
    $diff = $this->newMoveDiff($path);
    $stored = $this->newStoredResult($path, $diff);

    Filesystem::writeFile($path.'/a/sibling.txt', 'sibling');
    $this->commit($path, 'add a file beside the moved file');

    $engine = $this->newEngine($path, $diff);

    $this->assertEqual(
      null,
      RevisionMergeConflictWorker::newCarriedOverResult(
        $stored,
        $diff,
        $engine),
      pht(
        'A landing that adds a file beside one the stack moves should cost a '.
        'full check, though it touches no path in the stack.'));

    $this->assertEqual(
      DifferentialMergeConflictStatusField::STATUS_CONFLICT,
      idx($engine->executeCheck(), 'status'),
      pht(
        'The full check should report the directory rename conflict, since '.
        'moving the only file out of `a/` renames the directory.'));
  }

  public function testVerdictForAnotherDiffIsNotCarriedOver() {
    $fixture = PhutilDirectoryFixture::newEmptyFixture();
    $path = $fixture->getPath();
    $diff = $this->newMoveDiff($path);
    $stored = $this->newStoredResult($path, $diff);
    $stored[DifferentialMergeConflictStatusField::KEY_DIFF_PHID] =
      'PHID-DIFF-older';

    $this->assertEqual(
      null,
      RevisionMergeConflictWorker::newCarriedOverResult(
        $stored,
        $diff,
        $this->newEngine($path, $diff)),
      pht('A verdict for an older diff should never be carried over.'));
  }

  /**
   * Commits the stack's base to a new repository at `$path`, and returns a
   * stored diff, based on it, that moves `a/moved.txt` to `b/moved.txt`.
   */
  private function newMoveDiff(string $path): DifferentialDiff {
    execx('git -C %s init -q -b autoland', $path);
    Filesystem::createDirectory($path.'/a');
    Filesystem::writeFile($path.'/a/moved.txt', 'moved');
    Filesystem::writeFile($path.'/unrelated.txt', 'unrelated');
    $base = $this->commit($path, 'base');

    $changes = id(new ArcanistDiffParser())->parseDiff(self::RAW_MOVE_DIFF);

    return DifferentialDiff::newFromRawChanges(
      $this->generateNewTestUser(),
      $changes)
      ->setSourceControlBaseRevision($base)
      ->setLintStatus(DifferentialLintStatus::LINT_AUTO_SKIP)
      ->setUnitStatus(DifferentialUnitStatus::UNIT_AUTO_SKIP)
      ->attachRevision(null)
      ->save();
  }

  /**
   * Runs a full check against the current tip and returns the status a worker
   * would have stored for it.
   */
  private function newStoredResult(
    string $path,
    DifferentialDiff $diff): array {

    $engine = $this->newEngine($path, $diff);
    $result = $engine->executeCheck();

    $this->assertEqual(
      DifferentialMergeConflictStatusField::STATUS_CLEAN,
      idx($result, 'status'),
      pht(
        'The stack should merge cleanly before anything lands: %s',
        idx($result, 'reason')));

    return DifferentialMergeConflictStatusField::newStatusValue(
      $result,
      $diff,
      $engine->getStackDiffPHIDs(),
      PhabricatorTime::getNow());
  }

  private function newEngine(
    string $path,
    DifferentialDiff $diff): RevisionMergeConflictEngine {

    $repository = id(new PhabricatorRepository())
      ->setVersionControlSystem(PhabricatorRepositoryType::REPOSITORY_TYPE_GIT)
      ->setLocalPath($path)
      ->setDetail('default-branch', 'autoland');

    // A revision nothing depends on, so the stack is this diff alone.
    $revision = id(new DifferentialRevision())
      ->setPHID('PHID-DREV-carryover');

    return id(new RevisionMergeConflictEngine())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->setRevision($revision)
      ->setDiff($diff)
      ->setRepository($repository);
  }

  private function commit(string $path, string $message): string {
    execx('git -C %s add -A', $path);
    execx(
      'git -C %s -c user.name=Test -c user.email=test@example.com '.
      'commit -q --allow-empty -m %s',
      $path,
      $message);

    list($stdout) = execx('git -C %s rev-parse HEAD', $path);
    return trim($stdout);
  }

}
