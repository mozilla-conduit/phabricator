<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

final class ReviewHelperRequestConduitAPIMethod extends ConduitAPIMethod {

  public function getAPIMethodName() {
    return 'reviewhelper.request';
  }

  public function getMethodDescription() {
    return pht('Request an AI-assisted code review for a revision.');
  }

  public function getMethodStatus() {
    return self::METHOD_STATUS_UNSTABLE;
  }

  protected function defineParamTypes() {
    return array(
      'revisionID' => 'required int',
    );
  }

  protected function defineReturnType() {
    return 'map<string, wild>';
  }

  protected function defineErrorTypes() {
    return array(
      'ERR-REVISION-NOT-FOUND' => pht('Revision not found or not visible.'),
      'ERR-INELIGIBLE-REVISION' => pht(
        'This revision is not eligible for AI review.'
      ),
      'ERR-SERVICE-ERROR' => pht('Review Helper service returned an error.'),
    );
  }

  protected function execute(ConduitAPIRequest $request) {
    $viewer = $request->getUser();
    $revision_id = $request->getValue('revisionID');

    $revision = id(new DifferentialRevisionQuery())
      ->setViewer($viewer)
      ->withIDs(array($revision_id))
      ->needDiffIDs(true)
      ->needReviewers(true)
      ->executeOne();

    if (!$revision) {
      throw id(new ConduitException('ERR-REVISION-NOT-FOUND'))
        ->setErrorDescription(
          pht('Revision D%d not found or not visible.', $revision_id)
        );
    }

    try {
      $data = ReviewHelperService::requestReview($viewer, $revision);
    } catch (ReviewHelperIneligibleRevisionException $ex) {
      throw id(new ConduitException('ERR-INELIGIBLE-REVISION'))
        ->setErrorDescription($ex->getMessage());
    } catch (ReviewHelperServiceException $ex) {
      MozLogger::log(
        'Review Helper Conduit request failed',
        'reviewhelper.conduit.request.error',
        array('Fields' => array(
          'revision_id' => $revision_id,
          'error' => $ex->getMessage(),
        ))
      );
      throw id(new ConduitException('ERR-SERVICE-ERROR'))
        ->setErrorDescription($ex->getMessage());
    }

    return $data;
  }
}
