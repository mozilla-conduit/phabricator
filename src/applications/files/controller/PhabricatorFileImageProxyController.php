<?php

final class PhabricatorFileImageProxyController
  extends PhabricatorFileController {

  public function shouldAllowPublic() {
    return true;
  }

  public function handleRequest(AphrontRequest $request) {
    $viewer = $request->getViewer();
    $img_uri = $request->getStr('uri');

    // Validate the URI before doing anything, including DNS resolution and
    // outbound blacklist check, to fail fast before consuming rate limit tokens.
    PhabricatorEnv::requireValidRemoteURIForFetch(
      $img_uri,
      array('http', 'https'));
    $uri = new PhutilURI($img_uri);

    // Check if we already have the specified image URI downloaded
    $cached_request = id(new PhabricatorFileExternalRequest())->loadOneWhere(
      'uriIndex = %s',
      PhabricatorHash::digestForIndex($img_uri));

    if ($cached_request) {
      return $this->getExternalResponse($cached_request);
    }

    $ttl = PhabricatorTime::getNow() + phutil_units('7 days in seconds');
    $external_request = id(new PhabricatorFileExternalRequest())
      ->setURI($img_uri)
      ->setTTL($ttl);

    // Cache missed, so we'll need to validate and download the image.
    $unguarded = AphrontWriteGuard::beginScopedUnguardedWrites();
    $save_request = false;
    try {
      // Rate limit outbound fetches by remote IP to make this mechanism less
      // useful for scanning networks and ports. Using IP (not PHID) ensures
      // anonymous users are not all collapsed into a single actor.
      PhabricatorSystemActionEngine::willTakeAction(
        array(PhabricatorSystemActionEngine::newActorFromRequest($request)),
        new PhabricatorFilesOutboundRequestAction(),
        1);

      $file = PhabricatorFile::newFromFileDownload(
        $uri,
        array(
          'viewPolicy' => PhabricatorPolicies::POLICY_NOONE,
          'canCDN' => true,
        ));

      if (!$file->isViewableImage()) {
        $mime_type = $file->getMimeType();
        $engine = new PhabricatorDestructionEngine();
        $engine->destroyObject($file);
        $file = null;
        phlog(pht(
          'Image proxy rejected "%s": not a valid image (MIME type: "%s").',
          $uri,
          $mime_type));
        throw new Exception(
          pht(
            'The URI does not correspond to a valid image file. '.
            'You must specify the URI of a valid image file.'));
      }

      $file->save();

      $external_request
        ->setIsSuccessful(1)
        ->setFilePHID($file->getPHID());

      $save_request = true;
    } catch (HTTPFutureHTTPResponseStatus $status) {
      $external_request
        ->setIsSuccessful(0)
        ->setResponseMessage($status->getMessage());

      $save_request = true;
    } catch (Exception $ex) {
      // Not actually saving the request in this case
      $external_request->setResponseMessage($ex->getMessage());
    }

    if ($save_request) {
      try {
        $external_request->save();
      } catch (AphrontDuplicateKeyQueryException $ex) {
        // We may have raced against another identical request. If we did,
        // just throw our result away and use the winner's result.
        $external_request = $external_request->loadOneWhere(
          'uriIndex = %s',
          PhabricatorHash::digestForIndex($img_uri));
        if (!$external_request) {
          throw new Exception(
            pht(
              'Hit duplicate key collision when saving proxied image, but '.
              'failed to load duplicate row (for URI "%s").',
              $img_uri));
        }
      }
    }

    unset($unguarded);


    return $this->getExternalResponse($external_request);
  }

  private function getExternalResponse(
    PhabricatorFileExternalRequest $request) {
    if (!$request->getIsSuccessful()) {
      phlog(pht(
        'Image proxy failed for "%s": %s',
        $request->getURI(),
        $request->getResponseMessage()));
      throw new Exception(
        pht('Unable to load the requested image.'));
    }

    $file = id(new PhabricatorFileQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs(array($request->getFilePHID()))
      ->executeOne();
    if (!$file) {
      throw new Exception(
        pht(
          'The underlying file does not exist, but the cached request was '.
          'successful. This likely means the file record was manually '.
          'deleted by an administrator.'));
    }

    return id(new AphrontAjaxResponse())
      ->setContent(
        array(
          'imageURI' => $file->getViewURI(),
        ));
  }
}
