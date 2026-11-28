<?php

final class DiffusionDiffQueryConduitAPIMethod
  extends DiffusionQueryConduitAPIMethod {

  private $effectiveCommit;

  public function getAPIMethodName() {
    return 'diffusion.diffquery';
  }

  public function getMethodDescription() {
    return pht(
      'Get diff information from a repository for a specific path at an '.
      '(optional) commit.');
  }

  protected function defineReturnType() {
    return 'array';
  }

  protected function defineCustomParamTypes() {
    return array(
      'path' => 'required string',
      'commit' => 'optional string',
    );
  }

  protected function getResult(ConduitAPIRequest $request) {
    $result = parent::getResult($request);

    return array(
      'changes' => mpull($result, 'toDictionary'),
      'effectiveCommit' => $this->getEffectiveCommit($request),
    );
  }

  protected function getGitResult(ConduitAPIRequest $request) {
    return $this->getGitOrMercurialResult($request);
  }

  protected function getMercurialResult(ConduitAPIRequest $request) {
    return $this->getGitOrMercurialResult($request);
  }

  private function getEffectiveCommit(ConduitAPIRequest $request) {
    if ($this->effectiveCommit === null) {
      $drequest = $this->getDiffusionRequest();

      $path = $drequest->getPath();
      $result = DiffusionQuery::callConduitWithDiffusionRequest(
        $request->getUser(),
        $drequest,
        'diffusion.lastmodifiedquery',
        array(
          'paths' => array($path => $drequest->getStableCommit()),
        ));

      $this->effectiveCommit = idx($result, $path);
    }

    return $this->effectiveCommit;
  }

  private function getGitOrMercurialResult(ConduitAPIRequest $request) {
    $drequest = $this->getDiffusionRequest();
    $repository = $drequest->getRepository();

    $effective_commit = $this->getEffectiveCommit($request);
    if (!$effective_commit) {
      return $this->getEmptyResult();
    }

    $raw_query = DiffusionRawDiffQuery::newFromDiffusionRequest($drequest)
      ->setAnchorCommit($effective_commit);

    $raw_diff = $raw_query->executeInline();
    if (!$raw_diff) {
      return $this->getEmptyResult();
    }

    $parser = $this->getDefaultParser();
    $changes = $parser->parseDiff($raw_diff);

    return $changes;
  }

  private function getDefaultParser() {
    $drequest = $this->getDiffusionRequest();
    $repository = $drequest->getRepository();

    $parser = new ArcanistDiffParser();
    $try_encoding = $repository->getDetail('encoding');
    if ($try_encoding) {
      $parser->setTryEncoding($try_encoding);
    }
    $parser->setDetectBinaryFiles(true);

    return $parser;
  }

  private function getEmptyResult() {
    return array();
  }

}
