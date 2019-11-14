<?php

final class PhabricatorRepositoryIdentityQuery
  extends PhabricatorCursorPagedPolicyAwareQuery {

  private $ids;
  private $phids;
  private $identityNames;
  private $emailAddresses;
  private $assignedPHIDs;
  private $effectivePHIDs;
  private $identityNameLike;
  private $hasEffectivePHID;

  public function withIDs(array $ids) {
    $this->ids = $ids;
    return $this;
  }

  public function withPHIDs(array $phids) {
    $this->phids = $phids;
    return $this;
  }

  public function withIdentityNames(array $names) {
    $this->identityNames = $names;
    return $this;
  }

  public function withIdentityNameLike($name_like) {
    $this->identityNameLike = $name_like;
    return $this;
  }

  public function withEmailAddresses(array $addresses) {
    $this->emailAddresses = $addresses;
    return $this;
  }

  public function withAssignedPHIDs(array $assigned) {
    $this->assignedPHIDs = $assigned;
    return $this;
  }

  public function withEffectivePHIDs(array $effective) {
    $this->effectivePHIDs = $effective;
    return $this;
  }

  public function withHasEffectivePHID($has_effective_phid) {
    $this->hasEffectivePHID = $has_effective_phid;
    return $this;
  }

  public function newResultObject() {
    return new PhabricatorRepositoryIdentity();
  }

  protected function getPrimaryTableAlias() {
     return 'repository_identity';
   }

  protected function loadPage() {
    return $this->loadStandardPage($this->newResultObject());
  }

  protected function buildWhereClauseParts(AphrontDatabaseConnection $conn) {
    $where = parent::buildWhereClauseParts($conn);

    if ($this->ids !== null) {
      $where[] = qsprintf(
        $conn,
        'repository_identity.id IN (%Ld)',
        $this->ids);
    }

    if ($this->phids !== null) {
      $where[] = qsprintf(
        $conn,
        'repository_identity.phid IN (%Ls)',
        $this->phids);
    }

    if ($this->assignedPHIDs !== null) {
      $where[] = qsprintf(
        $conn,
        'repository_identity.manuallySetUserPHID IN (%Ls)',
        $this->assignedPHIDs);
    }

    if ($this->effectivePHIDs !== null) {
      $where[] = qsprintf(
        $conn,
        'repository_identity.currentEffectiveUserPHID IN (%Ls)',
        $this->effectivePHIDs);
    }

    if ($this->hasEffectivePHID !== null) {
      if ($this->hasEffectivePHID) {
        $where[] = qsprintf(
          $conn,
          'repository_identity.currentEffectiveUserPHID IS NOT NULL');
      } else {
        $where[] = qsprintf(
          $conn,
          'repository_identity.currentEffectiveUserPHID IS NULL');
      }
    }

    if ($this->identityNames !== null) {
      $name_hashes = array();
      foreach ($this->identityNames as $name) {
        $name_hashes[] = PhabricatorHash::digestForIndex($name);
      }

      $where[] = qsprintf(
        $conn,
        'repository_identity.identityNameHash IN (%Ls)',
        $name_hashes);
    }

    if ($this->emailAddresses !== null) {
      $where[] = qsprintf(
        $conn,
        'repository_identity.emailAddress IN (%Ls)',
        $this->emailAddresses);
    }

    if ($this->identityNameLike != null) {
      $where[] = qsprintf(
        $conn,
        'repository_identity.identityNameRaw LIKE %~',
        $this->identityNameLike);
    }

    return $where;
  }

  public function getQueryApplicationClass() {
    return 'PhabricatorDiffusionApplication';
  }

}
