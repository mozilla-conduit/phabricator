<?php


class BugStore {
  /** @var array<string, string> */
  private array $cache;

  public function __construct() {
    $this->cache = [];
  }

  public function resolveBug(DifferentialRevision $rawRevision): ?SecureEmailBug {
    $bugzillaField = new DifferentialBugzillaBugIDField();
    $bugzillaField->setObject($rawRevision);
    (new PhabricatorCustomFieldStorageQuery())
      ->addField($bugzillaField)
      ->execute();
    if (!$bugzillaField->getValue()) {
      return null;
    } else {
      $id = intval($bugzillaField->getValue());
      $link = (string) (new PhutilURI(PhabricatorEnv::getEnvConfig('bugzilla.url')))
        ->setPath('/show_bug.cgi')
        ->appendQueryParam('id', $id);
      return new SecureEmailBug($id, $link);
    }
  }

  public function queryName(string $id) {
    if (array_key_exists($id, $this->cache)) {
      return $this->cache[$id];
    }

    $bugApiURI = (new PhutilURI(PhabricatorEnv::getEnvConfig('bugzilla.url')))
      ->setPath('/rest/bug/' . $id)
      ->appendQueryParam('include_fields', 'summary');

    $future = (new HTTPSFuture((string)$bugApiURI))
      ->setMethod('GET')
      ->addHeader('X-Bugzilla-API-Key', PhabricatorEnv::getEnvConfig('bugzilla.automation_api_key'))
      ->addHeader('Accept', 'application/json')
      ->addHeader('User-Agent', 'Phabricator')
      ->setTimeout(PhabricatorEnv::getEnvConfig('bugzilla.timeout'));

    // resolve() reports HTTP errors, timeouts and transport failures through
    // the status object rather than throwing, so check the code explicitly.
    list($status, $rawBugResponse) = $future->resolve();
    if ((int)$status->getStatusCode() !== 200) {
      return null;
    }

    $jsonBugResponse = json_decode($rawBugResponse);
    $bugName = current($jsonBugResponse->bugs)->summary;
    $this->cache[$id] = $bugName;
    return $bugName;
  }
}