<?php

final class ManiphestEditEngine
  extends PhabricatorEditEngine {

  const ENGINECONST = 'maniphest.task';

  public function getEngineName() {
    return pht('Maniphest Tasks');
  }

  public function getSummaryHeader() {
    return pht('Configure Maniphest Task Forms');
  }

  public function getSummaryText() {
    return pht('Configure how users create and edit tasks.');
  }

  public function getEngineApplicationClass() {
    return 'PhabricatorManiphestApplication';
  }

  public function isDefaultQuickCreateEngine() {
    return true;
  }

  public function getQuickCreateOrderVector() {
    return id(new PhutilSortVector())->addInt(100);
  }

  protected function newEditableObject() {
    return ManiphestTask::initializeNewTask($this->getViewer());
  }

  protected function newObjectQuery() {
    return id(new ManiphestTaskQuery());
  }

  protected function getObjectCreateTitleText($object) {
    return pht('Create New Task');
  }

  protected function getObjectEditTitleText($object) {
    return pht('Edit Task: %s', $object->getTitle());
  }

  protected function getObjectEditShortText($object) {
    return $object->getMonogram();
  }

  protected function getObjectCreateShortText() {
    return pht('Create Task');
  }

  protected function getObjectName() {
    return pht('Task');
  }

  protected function getEditorURI() {
    return $this->getApplication()->getApplicationURI('task/edit/');
  }

  protected function getCommentViewHeaderText($object) {
    return pht('Weigh In');
  }

  protected function getCommentViewButtonText($object) {
    return pht('Set Sail for Adventure');
  }

  protected function getObjectViewURI($object) {
    return '/'.$object->getMonogram();
  }

  protected function buildCustomEditFields($object) {
    $status_map = $this->getTaskStatusMap($object);
    $priority_map = $this->getTaskPriorityMap($object);

    $alias_map = ManiphestTaskPriority::getTaskPriorityAliasMap();

    if ($object->isClosed()) {
      $default_status = ManiphestTaskStatus::getDefaultStatus();
    } else {
      $default_status = ManiphestTaskStatus::getDefaultClosedStatus();
    }

    if ($object->getOwnerPHID()) {
      $owner_value = array($object->getOwnerPHID());
    } else {
      $owner_value = array($this->getViewer()->getPHID());
    }

    $fields = array(
      id(new PhabricatorHandlesEditField())
        ->setKey('parent')
        ->setLabel(pht('Parent Task'))
        ->setDescription(pht('Task to make this a subtask of.'))
        ->setConduitDescription(pht('Create as a subtask of another task.'))
        ->setConduitTypeDescription(pht('PHID of the parent task.'))
        ->setAliases(array('parentPHID'))
        ->setTransactionType(ManiphestTaskParentTransaction::TRANSACTIONTYPE)
        ->setHandleParameterType(new ManiphestTaskListHTTPParameterType())
        ->setSingleValue(null)
        ->setIsReorderable(false)
        ->setIsDefaultable(false)
        ->setIsLockable(false),
      id(new PhabricatorTextEditField())
        ->setKey('title')
        ->setLabel(pht('Title'))
        ->setBulkEditLabel(pht('Set title to'))
        ->setDescription(pht('Name of the task.'))
        ->setConduitDescription(pht('Rename the task.'))
        ->setConduitTypeDescription(pht('New task name.'))
        ->setTransactionType(ManiphestTaskTitleTransaction::TRANSACTIONTYPE)
        ->setIsRequired(true)
        ->setValue($object->getTitle()),
      id(new PhabricatorUsersEditField())
        ->setKey('owner')
        ->setAliases(array('ownerPHID', 'assign', 'assigned'))
        ->setLabel(pht('Assigned To'))
        ->setBulkEditLabel(pht('Assign to'))
        ->setDescription(pht('User who is responsible for the task.'))
        ->setConduitDescription(pht('Reassign the task.'))
        ->setConduitTypeDescription(
          pht('New task owner, or `null` to unassign.'))
        ->setTransactionType(ManiphestTaskOwnerTransaction::TRANSACTIONTYPE)
        ->setIsCopyable(true)
        ->setIsNullable(true)
        ->setSingleValue($object->getOwnerPHID())
        ->setCommentActionLabel(pht('Assign / Claim'))
        ->setCommentActionValue($owner_value),
      id(new PhabricatorSelectEditField())
        ->setKey('status')
        ->setLabel(pht('Status'))
        ->setBulkEditLabel(pht('Set status to'))
        ->setDescription(pht('Status of the task.'))
        ->setConduitDescription(pht('Change the task status.'))
        ->setConduitTypeDescription(pht('New task status constant.'))
        ->setTransactionType(ManiphestTaskStatusTransaction::TRANSACTIONTYPE)
        ->setIsCopyable(true)
        ->setValue($object->getStatus())
        ->setOptions($status_map)
        ->setCommentActionLabel(pht('Change Status'))
        ->setCommentActionValue($default_status),
      id(new PhabricatorSelectEditField())
        ->setKey('priority')
        ->setLabel(pht('Priority'))
        ->setBulkEditLabel(pht('Set priority to'))
        ->setDescription(pht('Priority of the task.'))
        ->setConduitDescription(pht('Change the priority of the task.'))
        ->setConduitTypeDescription(pht('New task priority constant.'))
        ->setTransactionType(ManiphestTaskPriorityTransaction::TRANSACTIONTYPE)
        ->setIsCopyable(true)
        ->setValue($object->getPriorityKeyword())
        ->setOptions($priority_map)
        ->setOptionAliases($alias_map)
        ->setCommentActionLabel(pht('Change Priority')),
    );

    if (ManiphestTaskPoints::getIsEnabled()) {
      $points_label = ManiphestTaskPoints::getPointsLabel();
      $action_label = ManiphestTaskPoints::getPointsActionLabel();

      $fields[] = id(new PhabricatorPointsEditField())
        ->setKey('points')
        ->setLabel($points_label)
        ->setBulkEditLabel($action_label)
        ->setDescription(pht('Point value of the task.'))
        ->setConduitDescription(pht('Change the task point value.'))
        ->setConduitTypeDescription(pht('New task point value.'))
        ->setTransactionType(ManiphestTaskPointsTransaction::TRANSACTIONTYPE)
        ->setIsCopyable(true)
        ->setValue($object->getPoints())
        ->setCommentActionLabel($action_label);
    }

    $fields[] = id(new PhabricatorRemarkupEditField())
      ->setKey('description')
      ->setLabel(pht('Description'))
      ->setBulkEditLabel(pht('Set description to'))
      ->setDescription(pht('Task description.'))
      ->setConduitDescription(pht('Update the task description.'))
      ->setConduitTypeDescription(pht('New task description.'))
      ->setTransactionType(ManiphestTaskDescriptionTransaction::TRANSACTIONTYPE)
      ->setValue($object->getDescription())
      ->setPreviewPanel(
        id(new PHUIRemarkupPreviewPanel())
          ->setHeader(pht('Description Preview')));

    $parent_type = ManiphestTaskDependedOnByTaskEdgeType::EDGECONST;
    $subtask_type = ManiphestTaskDependsOnTaskEdgeType::EDGECONST;
    $commit_type = ManiphestTaskHasCommitEdgeType::EDGECONST;

    $src_phid = $object->getPHID();
    if ($src_phid) {
      $edge_query = id(new PhabricatorEdgeQuery())
        ->withSourcePHIDs(array($src_phid))
        ->withEdgeTypes(
          array(
            $parent_type,
            $subtask_type,
            $commit_type,
          ));
      $edge_query->execute();

      $parent_phids = $edge_query->getDestinationPHIDs(
        array($src_phid),
        array($parent_type));

      $subtask_phids = $edge_query->getDestinationPHIDs(
        array($src_phid),
        array($subtask_type));

      $commit_phids = $edge_query->getDestinationPHIDs(
        array($src_phid),
        array($commit_type));
    } else {
      $parent_phids = array();
      $subtask_phids = array();
      $commit_phids = array();
    }

    $fields[] = id(new PhabricatorHandlesEditField())
      ->setKey('parents')
      ->setLabel(pht('Parents'))
      ->setDescription(pht('Parent tasks.'))
      ->setConduitDescription(pht('Change the parents of this task.'))
      ->setConduitTypeDescription(pht('List of parent task PHIDs.'))
      ->setUseEdgeTransactions(true)
      ->setIsFormField(false)
      ->setTransactionType(PhabricatorTransactions::TYPE_EDGE)
      ->setMetadataValue('edge:type', $parent_type)
      ->setValue($parent_phids);

    $fields[] = id(new PhabricatorHandlesEditField())
      ->setKey('subtasks')
      ->setLabel(pht('Subtasks'))
      ->setDescription(pht('Subtasks.'))
      ->setConduitDescription(pht('Change the subtasks of this task.'))
      ->setConduitTypeDescription(pht('List of subtask PHIDs.'))
      ->setUseEdgeTransactions(true)
      ->setIsFormField(false)
      ->setTransactionType(PhabricatorTransactions::TYPE_EDGE)
      ->setMetadataValue('edge:type', $subtask_type)
      ->setValue($subtask_phids);

    $fields[] = id(new PhabricatorHandlesEditField())
      ->setKey('commits')
      ->setLabel(pht('Commits'))
      ->setDescription(pht('Related commits.'))
      ->setConduitDescription(pht('Change the related commits for this task.'))
      ->setConduitTypeDescription(pht('List of related commit PHIDs.'))
      ->setUseEdgeTransactions(true)
      ->setIsFormField(false)
      ->setTransactionType(PhabricatorTransactions::TYPE_EDGE)
      ->setMetadataValue('edge:type', $commit_type)
      ->setValue($commit_phids);

    return $fields;
  }

  private function getTaskStatusMap(ManiphestTask $task) {
    $status_map = ManiphestTaskStatus::getTaskStatusMap();

    $current_status = $task->getStatus();

    // If the current status is something we don't recognize (maybe an older
    // status which was deleted), put a dummy entry in the status map so that
    // saving the form doesn't destroy any data by accident.
    if (idx($status_map, $current_status) === null) {
      $status_map[$current_status] = pht('<Unknown: %s>', $current_status);
    }

    $dup_status = ManiphestTaskStatus::getDuplicateStatus();
    foreach ($status_map as $status => $status_name) {
      // Always keep the task's current status.
      if ($status == $current_status) {
        continue;
      }

      // Don't allow tasks to be changed directly into "Closed, Duplicate"
      // status. Instead, you have to merge them. See T4819.
      if ($status == $dup_status) {
        unset($status_map[$status]);
        continue;
      }

      // Don't let new or existing tasks be moved into a disabled status.
      if (ManiphestTaskStatus::isDisabledStatus($status)) {
        unset($status_map[$status]);
        continue;
      }
    }

    return $status_map;
  }

  private function getTaskPriorityMap(ManiphestTask $task) {
    $priority_map = ManiphestTaskPriority::getTaskPriorityMap();
    $priority_keywords = ManiphestTaskPriority::getTaskPriorityKeywordsMap();
    $current_priority = $task->getPriority();
    $results = array();

    foreach ($priority_map as $priority => $priority_name) {
      $disabled = ManiphestTaskPriority::isDisabledPriority($priority);
      if ($disabled && !($priority == $current_priority)) {
        continue;
      }

      $keyword = head(idx($priority_keywords, $priority));
      $results[$keyword] = $priority_name;
    }

    // If the current value isn't a legitimate one, put it in the dropdown
    // anyway so saving the form doesn't cause any side effects.
    if (idx($priority_map, $current_priority) === null) {
      $results[ManiphestTaskPriority::UNKNOWN_PRIORITY_KEYWORD] = pht(
        '<Unknown: %s>',
        $current_priority);
    }

    return $results;
  }

}
