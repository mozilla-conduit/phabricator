<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * Adds the AI change state to `differential.revision.search` results, so
 * Lando can block landing until it's approved.
 */
final class DifferentialAIChangeSearchEngineExtension
  extends PhabricatorSearchEngineExtension {

  const EXTENSIONKEY = 'mozilla.ai-change';

  public function isExtensionEnabled() {
    return true;
  }

  public function getExtensionName() {
    return pht('AI Change Approval');
  }

  public function getExtensionOrder() {
    return 9002;
  }

  public function supportsObject($object) {
    return ($object instanceof DifferentialRevision);
  }

  public function getFieldSpecificationsForConduit($object) {
    return array(
      id(new PhabricatorConduitSearchFieldSpecification())
        ->setKey('aiChange')
        ->setType('map<string, wild>')
        ->setDescription(
          pht(
            'AI change state. "status" is "none", '.
            '"pending", "approved" or "lapsed" (updated since the request). '.
            '"kind" is why the approval was requested.')),
    );
  }

  public function getFieldValuesForConduit($object, $data) {
    $ai_change = DifferentialAIChange::newFromRevision($object);

    return array(
      'aiChange' => array(
        'status' => $ai_change->getStatus(),
        'kind' => $ai_change->getKind(),
        'changeDiffID' => $ai_change->getChangeDiffID(),
        'baseDiffID' => $ai_change->getBaseDiffID(),
        'requesterPHID' => $ai_change->getRequesterPHID(),
        'requestEpoch' => $ai_change->getRequestEpoch(),
        'approverPHID' => $ai_change->getApproverPHID(),
        'approvalEpoch' => $ai_change->getApprovalEpoch(),
      ),
    );
  }

}
