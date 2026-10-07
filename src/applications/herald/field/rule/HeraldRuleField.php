<?php

abstract class HeraldRuleField
  extends HeraldField {

  public function getFieldGroupKey() {
    return HeraldRuleFieldGroup::FIELDGROUPKEY;
  }

  public function supportsObject($object) {
    return ($object instanceof HeraldRule);
  }

}
