<?php


class PhabricatorStoryBuilder {
  public TransactionList $transactions;
  public EventKind $eventKind;
  public string $revisionPHID;
  public string $actorPHID;
  public bool $isBroadcastable;
  private DifferentialRevision $revision;
  private PhabricatorUser $actor;
  private string $key;
  private int $id;
  private int $timestamp;

  public function __construct(EventKind $eventKind, TransactionList $transactions, string $key, int $id, int $timestamp) {
    $this->eventKind = $eventKind;
    $this->transactions = $transactions;
    $this->revisionPHID = $eventKind->findMainTransaction($this->transactions)->getObjectPHID();
    $this->key = $key;
    $this->id = $id;
    $this->timestamp = $timestamp;
    $this->isBroadcastable = true;
  }

  public function associateRevision(DifferentialRevision $revision, string $actorPHID) {
    $this->revision = $revision;
    $this->actorPHID = $actorPHID;
  }

  public function associateActor(PhabricatorUser $actor) {
    $this->actor = $actor;
  }

  public function asStory(): PhabricatorStory
  {
    return new PhabricatorStory($this->eventKind, $this->transactions, $this->revision, $this->actor, $this->key, $this->id, $this->timestamp);
  }
}
