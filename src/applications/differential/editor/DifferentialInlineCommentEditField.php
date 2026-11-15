<?php

/**
 * Conduit-only field which publishes a new inline comment on a revision as
 * part of a "differential.revision.edit" call.
 */
final class DifferentialInlineCommentEditField
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
    return new DifferentialInlineCommentEditType();
  }

}
