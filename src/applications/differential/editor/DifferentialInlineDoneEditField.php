<?php

/**
 * Conduit-only field which marks a published inline comment on a revision as
 * "Done" or "Not Done" as part of a "differential.revision.edit" call.
 */
final class DifferentialInlineDoneEditField
  extends PhabricatorEditField {

  protected function newControl() {
    return null;
  }

  protected function newHTTPParameterType() {
    return null;
  }

  protected function newConduitParameterType() {
    return new ConduitWildParameterType();
  }

  protected function newEditType() {
    return new DifferentialInlineDoneEditType();
  }

}
