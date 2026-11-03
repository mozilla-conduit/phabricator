<?php

abstract class HeraldRuleField
  extends HeraldField {

  public function getFieldGroupKey() {
    return HeraldBasicFieldGroup::FIELDGROUPKEY;
  }

  public function supportsObject($object) {
    return ($object instanceof HeraldRule);
  }

}
