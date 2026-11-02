<?php

final class DifferentialSetDiffPropertyConduitAPIMethod
  extends DifferentialConduitAPIMethod {

  public function getAPIMethodName() {
    return 'differential.setdiffproperty';
  }

  public function getMethodDescription() {
    return pht('Attach properties to Differential diffs.');
  }

  protected function defineParamTypes() {
    return array(
      'diff_id' => 'required diff_id',
      'name'    => 'required string',
      'data'    => 'required string',
    );
  }

  protected function defineReturnType() {
    return 'void';
  }

  protected function defineErrorTypes() {
    return array(
      'ERR_NOT_FOUND'    => pht('Diff was not found.'),
      'ERR_PERMISSIONS'  => pht('You do not have permission to modify this diff.'),
      'ERR_INVALID_DATA' => pht('Property data is not valid JSON.'),
      'ERR_NO_NAME'      => pht('Name cannot be empty.'),
    );
  }

  protected function execute(ConduitAPIRequest $request) {
    $viewer = $request->getUser();
    $diff_id = $request->getValue('diff_id');
    $name = $request->getValue('name');
    if (!phutil_nonempty_string($name)) {
      throw new ConduitException('ERR_NO_NAME');
    }

    try {
      $data = phutil_json_decode($request->getValue('data'));
    } catch (PhutilJSONParserException $ex) {
      throw new ConduitException('ERR_INVALID_DATA');
    }

    $diff = id(new DifferentialDiffQuery())
      ->setViewer($viewer)
      ->withIDs(array($diff_id))
      ->executeOne();
    if (!$diff) {
      throw new ConduitException('ERR_NOT_FOUND');
    }

    $revision_id = $diff->getRevisionID();
    if ($revision_id) {
      $revision = id(new DifferentialRevisionQuery())
        ->setViewer($viewer)
        ->withIDs(array($revision_id))
        ->requireCapabilities(
          array(
            PhabricatorPolicyCapability::CAN_VIEW,
            PhabricatorPolicyCapability::CAN_EDIT,
          ))
        ->executeOne();
      if (!$revision) {
        throw new ConduitException('ERR_PERMISSIONS');
      }
    } else {
      if ($diff->getAuthorPHID() !== $viewer->getPHID()) {
        throw new ConduitException('ERR_PERMISSIONS');
      }
    }

    self::updateDiffProperty($diff_id, $name, $data);

    if ($revision_id && $name === 'local:commits') {
      self::queueMergeConflictChecks($revision, $diff, $data);
    }
  }

  /**
   * moz-phab records `local:commits` after creating the revision, so the merge
   * check queued by that edit may already have run without the stack's first
   * public ancestor. Check again now that it's here.
   *
   * @phutil-external-symbol class RevisionMergeConflictEngine
   * @phutil-external-symbol class RevisionMergeConflictWorker
   */
  private static function queueMergeConflictChecks(
    DifferentialRevision $revision,
    DifferentialDiff $diff,
    $local_commits) {

    // Without a first public ancestor, the check has nothing new to use.
    if (RevisionMergeConflictEngine::getFirstPublicParent($local_commits) ===
        null) {
      return;
    }

    $repository = $revision->getRepository();
    if (!$repository ||
        !RevisionMergeConflictWorker::isEnabledForRepository($repository)) {
      return;
    }

    RevisionMergeConflictWorker::queueCheck(
      $revision->getPHID(),
      $diff->getPHID());

    RevisionMergeConflictWorker::queueDescendantChecks(
      PhabricatorUser::getOmnipotentUser(),
      array($revision->getPHID()));
  }

  private static function updateDiffProperty($diff_id, $name, $data) {
    $property = id(new DifferentialDiffProperty())->loadOneWhere(
      'diffID = %d AND name = %s',
      $diff_id,
      $name);
    if (!$property) {
      $property = new DifferentialDiffProperty();
      $property->setDiffID($diff_id);
      $property->setName($name);
    }
    $property->setData($data);
    $property->save();
    return $property;
  }

}
