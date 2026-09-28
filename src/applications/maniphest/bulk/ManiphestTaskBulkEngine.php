<?php

final class ManiphestTaskBulkEngine
  extends PhabricatorBulkEngine {

  public function newSearchEngine() {
    return new ManiphestTaskSearchEngine();
  }

  public function newEditEngine() {
    return new ManiphestEditEngine();
  }

}
