<?php

final class PhabricatorPeopleQuery
  extends PhabricatorCursorPagedPolicyAwareQuery {

  private $usernames;
  private $realnames;
  private $emails;
  private $phids;
  private $ids;
  private $dateCreatedAfter;
  private $dateCreatedBefore;
  private $isAdmin;
  private $isSystemAgent;
  private $isMailingList;
  private $isDisabled;
  private $isApproved;
  private $nameLike;
  private $nameTokens;
  private $namePrefixes;
  private $isEnrolledInMultiFactor;

  private $needPrimaryEmail;
  private $needProfile;
  private $needProfileImage;
  private $cacheKeys = array();

  public function withIDs(array $ids) {
    $this->ids = $ids;
    return $this;
  }

  public function withPHIDs(array $phids) {
    $this->phids = $phids;
    return $this;
  }

  public function withEmails(array $emails) {
    $this->emails = $emails;
    return $this;
  }

  public function withRealnames(array $realnames) {
    $this->realnames = $realnames;
    return $this;
  }

  public function withUsernames(array $usernames) {
    $this->usernames = $usernames;
    return $this;
  }

  public function withDateCreatedBefore($date_created_before) {
    $this->dateCreatedBefore = $date_created_before;
    return $this;
  }

  public function withDateCreatedAfter($date_created_after) {
    $this->dateCreatedAfter = $date_created_after;
    return $this;
  }

  public function withIsAdmin($admin) {
    $this->isAdmin = $admin;
    return $this;
  }

  public function withIsSystemAgent($system_agent) {
    $this->isSystemAgent = $system_agent;
    return $this;
  }

  public function withIsMailingList($mailing_list) {
    $this->isMailingList = $mailing_list;
    return $this;
  }

  public function withIsDisabled($disabled) {
    $this->isDisabled = $disabled;
    return $this;
  }

  public function withIsApproved($approved) {
    $this->isApproved = $approved;
    return $this;
  }

  public function withNameLike($like) {
    $this->nameLike = $like;
    return $this;
  }

  public function withNameTokens(array $tokens) {
    $this->nameTokens = array_values($tokens);
    return $this;
  }

  public function withNamePrefixes(array $prefixes) {
    $this->namePrefixes = $prefixes;
    return $this;
  }

  public function withIsEnrolledInMultiFactor($enrolled) {
    $this->isEnrolledInMultiFactor = $enrolled;
    return $this;
  }

  public function needPrimaryEmail($need) {
    $this->needPrimaryEmail = $need;
    return $this;
  }

  public function needProfile($need) {
    $this->needProfile = $need;
    return $this;
  }

  public function needProfileImage($need) {
    $cache_key = PhabricatorUserProfileImageCacheType::KEY_URI;

    if ($need) {
      $this->cacheKeys[$cache_key] = true;
    } else {
      unset($this->cacheKeys[$cache_key]);
    }

    return $this;
  }

  public function needUserSettings($need) {
    $cache_key = PhabricatorUserPreferencesCacheType::KEY_PREFERENCES;

    if ($need) {
      $this->cacheKeys[$cache_key] = true;
    } else {
      unset($this->cacheKeys[$cache_key]);
    }

    return $this;
  }

  public function newResultObject() {
    return new PhabricatorUser();
  }

  protected function didFilterPage(array $users) {
    if ($this->needProfile) {
      $user_list = mpull($users, null, 'getPHID');
      $profiles = new PhabricatorUserProfile();
      $profiles = $profiles->loadAllWhere(
        'userPHID IN (%Ls)',
        array_keys($user_list));

      $profiles = mpull($profiles, null, 'getUserPHID');
      foreach ($user_list as $user_phid => $user) {
        $profile = idx($profiles, $user_phid);

        if (!$profile) {
          $profile = PhabricatorUserProfile::initializeNewProfile($user);
        }

        $user->attachUserProfile($profile);
      }
    }

    $this->fillUserCaches($users);

    return $users;
  }

  protected function shouldGroupQueryResultRows() {
    if ($this->nameTokens) {
      return true;
    }

    return parent::shouldGroupQueryResultRows();
  }

  protected function buildJoinClauseParts(AphrontDatabaseConnection $conn) {
    $joins = parent::buildJoinClauseParts($conn);

    if ($this->emails) {
      $email_table = new PhabricatorUserEmail();
      $joins[] = qsprintf(
        $conn,
        'JOIN %T email ON email.userPHID = user.PHID',
        $email_table->getTableName());
    }

    if ($this->nameTokens) {
      foreach ($this->nameTokens as $key => $token) {
        $token_table = 'token_'.$key;
        $joins[] = qsprintf(
          $conn,
          'JOIN %T %T ON %T.userID = user.id AND %T.token LIKE %>',
          PhabricatorUser::NAMETOKEN_TABLE,
          $token_table,
          $token_table,
          $token_table,
          $token);
      }
    }

    return  $joins;
  }

  protected function buildWhereClauseParts(AphrontDatabaseConnection $conn) {
    $where = parent::buildWhereClauseParts($conn);

    if ($this->usernames !== null) {
      $where[] = qsprintf(
        $conn,
        'user.userName IN (%Ls)',
        $this->usernames);
    }

    if ($this->namePrefixes) {
      $parts = array();
      foreach ($this->namePrefixes as $name_prefix) {
        $parts[] = qsprintf(
          $conn,
          'user.username LIKE %>',
          $name_prefix);
      }
      $where[] = qsprintf($conn, '%LO', $parts);
    }

    if ($this->emails !== null) {
      $where[] = qsprintf(
        $conn,
        'email.address IN (%Ls)',
        $this->emails);
    }

    if ($this->realnames !== null) {
      $where[] = qsprintf(
        $conn,
        'user.realName IN (%Ls)',
        $this->realnames);
    }

    if ($this->phids !== null) {
      $where[] = qsprintf(
        $conn,
        'user.phid IN (%Ls)',
        $this->phids);
    }

    if ($this->ids !== null) {
      $where[] = qsprintf(
        $conn,
        'user.id IN (%Ld)',
        $this->ids);
    }

    if ($this->dateCreatedAfter) {
      $where[] = qsprintf(
        $conn,
        'user.dateCreated >= %d',
        $this->dateCreatedAfter);
    }

    if ($this->dateCreatedBefore) {
      $where[] = qsprintf(
        $conn,
        'user.dateCreated <= %d',
        $this->dateCreatedBefore);
    }

    if ($this->isAdmin !== null) {
      $where[] = qsprintf(
        $conn,
        'user.isAdmin = %d',
        (int)$this->isAdmin);
    }

    if ($this->isDisabled !== null) {
      $where[] = qsprintf(
        $conn,
        'user.isDisabled = %d',
        (int)$this->isDisabled);
    }

    if ($this->isApproved !== null) {
      $where[] = qsprintf(
        $conn,
        'user.isApproved = %d',
        (int)$this->isApproved);
    }

    if ($this->isSystemAgent !== null) {
      $where[] = qsprintf(
        $conn,
        'user.isSystemAgent = %d',
        (int)$this->isSystemAgent);
    }

    if ($this->isMailingList !== null) {
      $where[] = qsprintf(
        $conn,
        'user.isMailingList = %d',
        (int)$this->isMailingList);
    }

    if ($this->nameLike !== null) {
      $where[] = qsprintf(
        $conn,
        'user.username LIKE %~ OR user.realname LIKE %~',
        $this->nameLike,
        $this->nameLike);
    }

    if ($this->isEnrolledInMultiFactor !== null) {
      $where[] = qsprintf(
        $conn,
        'user.isEnrolledInMultiFactor = %d',
        (int)$this->isEnrolledInMultiFactor);
    }

    return $where;
  }

  protected function getPrimaryTableAlias() {
    return 'user';
  }

  public function getQueryApplicationClass() {
    return 'PhabricatorPeopleApplication';
  }

  public function getOrderableColumns() {
    return parent::getOrderableColumns() + array(
      'username' => array(
        'table' => 'user',
        'column' => 'username',
        'type' => 'string',
        'reverse' => true,
        'unique' => true,
      ),
    );
  }

  protected function newPagingMapFromPartialObject($object) {
    return array(
      'id' => (int)$object->getID(),
      'username' => $object->getUsername(),
    );
  }

  private function fillUserCaches(array $users) {
    if (!$this->cacheKeys) {
      return;
    }

    $user_map = mpull($users, null, 'getPHID');
    $keys = array_keys($this->cacheKeys);

    $hashes = array();
    foreach ($keys as $key) {
      $hashes[] = PhabricatorHash::digestForIndex($key);
    }

    $types = PhabricatorUserCacheType::getAllCacheTypes();

    // First, pull any available caches. If we wanted to be particularly clever
    // we could do this with JOINs in the main query.

    $cache_table = new PhabricatorUserCache();
    $cache_conn = $cache_table->establishConnection('r');

    $cache_data = queryfx_all(
      $cache_conn,
      'SELECT cacheKey, userPHID, cacheData, cacheType FROM %T
        WHERE cacheIndex IN (%Ls) AND userPHID IN (%Ls)',
      $cache_table->getTableName(),
      $hashes,
      array_keys($user_map));

    $skip_validation = array();

    // After we read caches from the database, discard any which have data that
    // invalid or out of date. This allows cache types to implement TTLs or
    // versions instead of or in addition to explicit cache clears.
    foreach ($cache_data as $row_key => $row) {
      $cache_type = $row['cacheType'];

      if (isset($skip_validation[$cache_type])) {
        continue;
      }

      if (empty($types[$cache_type])) {
        unset($cache_data[$row_key]);
        continue;
      }

      $type = $types[$cache_type];
      if (!$type->shouldValidateRawCacheData()) {
        $skip_validation[$cache_type] = true;
        continue;
      }

      $user = $user_map[$row['userPHID']];
      $raw_data = $row['cacheData'];
      if (!$type->isRawCacheDataValid($user, $row['cacheKey'], $raw_data)) {
        unset($cache_data[$row_key]);
        continue;
      }
    }

    $need = array();

    $cache_data = igroup($cache_data, 'userPHID');
    foreach ($user_map as $user_phid => $user) {
      $raw_rows = idx($cache_data, $user_phid, array());
      $raw_data = ipull($raw_rows, 'cacheData', 'cacheKey');

      foreach ($keys as $key) {
        if (isset($raw_data[$key]) || array_key_exists($key, $raw_data)) {
          continue;
        }
        $need[$key][$user_phid] = $user;
      }

      $user->attachRawCacheData($raw_data);
    }

    // If we missed any cache values, bulk-construct them now. This is
    // usually much cheaper than generating them on-demand for each user
    // record.

    if (!$need) {
      return;
    }

    $writes = array();
    foreach ($need as $cache_key => $need_users) {
      $type = PhabricatorUserCacheType::getCacheTypeForKey($cache_key);
      if (!$type) {
        continue;
      }

      $data = $type->newValueForUsers($cache_key, $need_users);

      foreach ($data as $user_phid => $raw_value) {
        $data[$user_phid] = $raw_value;
        $writes[] = array(
          'userPHID' => $user_phid,
          'key' => $cache_key,
          'type' => $type,
          'value' => $raw_value,
        );
      }

      foreach ($need_users as $user_phid => $user) {
        if (isset($data[$user_phid]) || array_key_exists($user_phid, $data)) {
          $user->attachRawCacheData(
            array(
              $cache_key => $data[$user_phid],
            ));
        }
      }
    }

    PhabricatorUserCache::writeCaches($writes);
  }
}
