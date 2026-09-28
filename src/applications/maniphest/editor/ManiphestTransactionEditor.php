<?php

final class ManiphestTransactionEditor
  extends PhabricatorApplicationTransactionEditor {


  public function getEditorApplicationClass() {
    return 'PhabricatorManiphestApplication';
  }

  public function getEditorObjectsDescription() {
    return pht('Maniphest Tasks');
  }

  public function getTransactionTypes() {
    $types = parent::getTransactionTypes();

    $types[] = PhabricatorTransactions::TYPE_COMMENT;
    $types[] = PhabricatorTransactions::TYPE_EDGE;
    $types[] = PhabricatorTransactions::TYPE_VIEW_POLICY;
    $types[] = PhabricatorTransactions::TYPE_EDIT_POLICY;

    return $types;
  }

  public function getCreateObjectTitle($author, $object) {
    return pht('%s created this task.', $author);
  }

  public function getCreateObjectTitleForFeed($author, $object) {
    return pht('%s created %s.', $author, $object);
  }

  protected function applyFinalEffects(
    PhabricatorLiskDAO $object,
    array $xactions) {

    // When we change the status of a task, update tasks this tasks blocks
    // with a message to the effect of "alincoln resolved blocking task Txxx."
    $unblock_xaction = null;
    foreach ($xactions as $xaction) {
      switch ($xaction->getTransactionType()) {
        case ManiphestTaskStatusTransaction::TRANSACTIONTYPE:
          $unblock_xaction = $xaction;
          break;
      }
    }

    if ($unblock_xaction !== null) {
      $blocked_phids = PhabricatorEdgeQuery::loadDestinationPHIDs(
        $object->getPHID(),
        ManiphestTaskDependedOnByTaskEdgeType::EDGECONST);
      if ($blocked_phids) {
        // In theory we could apply these through policies, but that seems a
        // little bit surprising. For now, use the actor's vision.
        $blocked_tasks = id(new ManiphestTaskQuery())
          ->setViewer($this->getActor())
          ->withPHIDs($blocked_phids)
          ->needSubscriberPHIDs(true)
          ->needProjectPHIDs(true)
          ->execute();

        $old = $unblock_xaction->getOldValue();
        $new = $unblock_xaction->getNewValue();

        foreach ($blocked_tasks as $blocked_task) {
          $parent_xaction = id(new ManiphestTransaction())
            ->setTransactionType(
              ManiphestTaskUnblockTransaction::TRANSACTIONTYPE)
            ->setOldValue(array($object->getPHID() => $old))
            ->setNewValue(array($object->getPHID() => $new));

          if ($this->getIsNewObject()) {
            $parent_xaction->setMetadataValue('blocker.new', true);
          }

          $this->newSubEditor()
            ->setContinueOnNoEffect(true)
            ->setContinueOnMissingFields(true)
            ->applyTransactions($blocked_task, array($parent_xaction));
        }
      }
    }

    return $xactions;
  }

  protected function shouldSendMail(
    PhabricatorLiskDAO $object,
    array $xactions) {
    return true;
  }

  protected function getMailSubjectPrefix() {
    return pht('[Maniphest]');
  }

  protected function getMailThreadID(PhabricatorLiskDAO $object) {
    return 'maniphest-task-'.$object->getPHID();
  }

  protected function getMailTo(PhabricatorLiskDAO $object) {
    $phids = array();

    if ($object->getOwnerPHID()) {
      $phids[] = $object->getOwnerPHID();
    }
    $phids[] = $this->getActingAsPHID();

    return $phids;
  }

  public function getMailTagsMap() {
    return array(
      ManiphestTransaction::MAILTAG_STATUS =>
        pht("A task's status changes."),
      ManiphestTransaction::MAILTAG_OWNER =>
        pht("A task's owner changes."),
      ManiphestTransaction::MAILTAG_PRIORITY =>
        pht("A task's priority changes."),
      ManiphestTransaction::MAILTAG_CC =>
        pht("A task's subscribers change."),
      ManiphestTransaction::MAILTAG_PROJECTS =>
        pht("A task's associated projects change."),
      ManiphestTransaction::MAILTAG_UNBLOCK =>
        pht("One of a task's subtasks changes status."),
      ManiphestTransaction::MAILTAG_COMMENT =>
        pht('Someone comments on a task.'),
      ManiphestTransaction::MAILTAG_OTHER =>
        pht('Other task activity not listed above occurs.'),
    );
  }

  protected function buildReplyHandler(PhabricatorLiskDAO $object) {
    return id(new ManiphestReplyHandler())
      ->setMailReceiver($object);
  }

  protected function buildMailTemplate(PhabricatorLiskDAO $object) {
    $id = $object->getID();
    $title = $object->getTitle();

    return id(new PhabricatorMetaMTAMail())
      ->setSubject("T{$id}: {$title}");
  }

  protected function getObjectLinkButtonLabelForMail(
    PhabricatorLiskDAO $object) {
    return pht('View Task');
  }

  protected function buildMailBody(
    PhabricatorLiskDAO $object,
    array $xactions) {

    $body = parent::buildMailBody($object, $xactions);

    if ($this->getIsNewObject()) {
      $body->addRemarkupSection(
        pht('TASK DESCRIPTION'),
        $object->getDescription());
    }

    $body->addLinkSection(
      pht('TASK DETAIL'),
      $this->getObjectLinkButtonURIForMail($object));

    return $body;
  }

  protected function shouldPublishFeedStory(
    PhabricatorLiskDAO $object,
    array $xactions) {
    return true;
  }

  protected function supportsSearch() {
    return true;
  }

  protected function shouldApplyHeraldRules(
    PhabricatorLiskDAO $object,
    array $xactions) {
    return true;
  }

  protected function buildHeraldAdapter(
    PhabricatorLiskDAO $object,
    array $xactions) {

    return id(new HeraldManiphestTaskAdapter())
      ->setTask($object);
  }

  protected function adjustObjectForPolicyChecks(
    PhabricatorLiskDAO $object,
    array $xactions) {

    $copy = parent::adjustObjectForPolicyChecks($object, $xactions);
    foreach ($xactions as $xaction) {
      switch ($xaction->getTransactionType()) {
        case ManiphestTaskOwnerTransaction::TRANSACTIONTYPE:
          $copy->setOwnerPHID($xaction->getNewValue());
          break;
        default:
          break;
      }
    }

    return $copy;
  }

  protected function validateAllTransactions(
    PhabricatorLiskDAO $object,
    array $xactions) {

    $errors = parent::validateAllTransactions($object, $xactions);

    foreach ($this->getLockValidationErrors($object, $xactions) as $error) {
      $errors[] = $error;
    }

    return $errors;
  }

  protected function expandTransactions(
    PhabricatorLiskDAO $object,
    array $xactions) {

    $actor = $this->getActor();
    $actor_phid = $actor->getPHID();

    $results = parent::expandTransactions($object, $xactions);

    $is_unassigned = ($object->getOwnerPHID() === null);

    $any_assign = false;
    foreach ($xactions as $xaction) {
      if ($xaction->getTransactionType() ==
        ManiphestTaskOwnerTransaction::TRANSACTIONTYPE) {
        $any_assign = true;
        break;
      }
    }

    $is_open = !$object->isClosed();

    $new_status = null;
    foreach ($xactions as $xaction) {
      switch ($xaction->getTransactionType()) {
        case ManiphestTaskStatusTransaction::TRANSACTIONTYPE:
          $new_status = $xaction->getNewValue();
          break;
      }
    }

    if ($new_status === null) {
      $is_closing = false;
    } else {
      $is_closing = ManiphestTaskStatus::isClosedStatus($new_status);
    }

    // If the task is not assigned, not being assigned, currently open, and
    // being closed, try to assign the actor as the owner.
    if ($is_unassigned && !$any_assign && $is_open && $is_closing) {
      $is_claim = ManiphestTaskStatus::isClaimStatus($new_status);

      // Don't assign the actor if they aren't a real user.
      // Don't claim the task if the status is configured to not claim.
      if ($actor_phid && $is_claim) {
        $results[] = id(new ManiphestTransaction())
          ->setTransactionType(ManiphestTaskOwnerTransaction::TRANSACTIONTYPE)
          ->setNewValue($actor_phid);
      }
    }

    // Automatically subscribe the author when they create a task.
    if ($this->getIsNewObject()) {
      if ($actor_phid) {
        $results[] = id(new ManiphestTransaction())
          ->setTransactionType(PhabricatorTransactions::TYPE_SUBSCRIBERS)
          ->setNewValue(
            array(
              '+' => array($actor_phid => $actor_phid),
            ));
      }
    }

    return $results;
  }

  protected function expandTransaction(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {

    $results = parent::expandTransaction($object, $xaction);

    $type = $xaction->getTransactionType();
    switch ($type) {
      case ManiphestTaskOwnerTransaction::TRANSACTIONTYPE:
        // If this is a no-op update, don't expand it.
        $old_value = $object->getOwnerPHID();
        $new_value = $xaction->getNewValue();
        if ($old_value === $new_value) {
          break;
        }

        // When a task is reassigned, move the old owner to the subscriber
        // list so they're still in the loop.
        if ($old_value) {
          $results[] = id(new ManiphestTransaction())
            ->setTransactionType(PhabricatorTransactions::TYPE_SUBSCRIBERS)
            ->setIgnoreOnNoEffect(true)
            ->setNewValue(
              array(
                '+' => array($old_value => $old_value),
              ));
        }
        break;
    }

    return $results;
  }

  private function getLockValidationErrors($object, array $xactions) {
    $errors = array();

    $old_owner = $object->getOwnerPHID();
    $old_status = $object->getStatus();

    $new_owner = $old_owner;
    $new_status = $old_status;

    $owner_xaction = null;
    $status_xaction = null;

    foreach ($xactions as $xaction) {
      switch ($xaction->getTransactionType()) {
        case ManiphestTaskOwnerTransaction::TRANSACTIONTYPE:
          $new_owner = $xaction->getNewValue();
          $owner_xaction = $xaction;
          break;
        case ManiphestTaskStatusTransaction::TRANSACTIONTYPE:
          $new_status = $xaction->getNewValue();
          $status_xaction = $xaction;
          break;
      }
    }

    $actor_phid = $this->getActingAsPHID();

    $was_locked = ManiphestTaskStatus::areEditsLockedInStatus(
      $old_status);
    $now_locked = ManiphestTaskStatus::areEditsLockedInStatus(
      $new_status);

    if (!$now_locked) {
      // If we're not ending in an edit-locked status, everything is good.
    } else if ($new_owner !== null) {
      // If we ending the edit with some valid owner, this is allowed for
      // now. We might need to revisit this.
    } else {
      // The edits end with the task locked and unowned. No one will be able
      // to edit it, so we forbid this. We try to be specific about what the
      // user did wrong.

      $owner_changed = ($old_owner && !$new_owner);
      $status_changed = ($was_locked !== $now_locked);
      $message = null;

      if ($status_changed && $owner_changed) {
        $message = pht(
          'You can not lock this task and unassign it at the same time '.
          'because no one will be able to edit it anymore. Lock the task '.
          'or remove the owner, but not both.');
        $problem_xaction = $status_xaction;
      } else if ($status_changed) {
        $message = pht(
          'You can not lock this task because it does not have an owner. '.
          'No one would be able to edit the task. Assign the task to an '.
          'owner before locking it.');
        $problem_xaction = $status_xaction;
      } else if ($owner_changed) {
        $message = pht(
          'You can not remove the owner of this task because it is locked '.
          'and no one would be able to edit the task. Reassign the task or '.
          'unlock it before removing the owner.');
        $problem_xaction = $owner_xaction;
      } else {
        // If the task was already broken, we don't have a transaction to
        // complain about so just let it through. In theory, this is
        // impossible since policy rules should kick in before we get here.
      }

      if ($message) {
        $errors[] = new PhabricatorApplicationTransactionValidationError(
          $problem_xaction->getTransactionType(),
          pht('Lock Error'),
          $message,
          $problem_xaction);
      }
    }

    return $errors;
  }

}
