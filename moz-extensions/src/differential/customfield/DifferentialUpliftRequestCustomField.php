<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
* Extends Differential with a 'Uplift Request' field.
*/
final class DifferentialUpliftRequestCustomField
    extends DifferentialStoredCustomField {

    // How each field is formatted in ReMarkup:
    // a bullet point with text in bold.
    const QUESTION_FORMATTING = "- **%s** %s";

    private $proxy;

    /* -(  Core Properties and Field Identity  )--------------------------------- */

    public function readValueFromRequest(AphrontRequest $request) {
        $uplift_data = $request->getJSONMap($this->getFieldKey());
        $this->setValue($uplift_data);
    }

    public function getFieldKey() {
        return 'differential:uplift-request';
    }

    public function getFieldKeyForConduit() {
        return 'uplift.request';
    }

    public function getFieldValue() {
        return $this->getValue();
    }

    public function getFieldName() {
        return pht('Uplift Request form');
    }

    public function getFieldDescription() {
        // Rendered in 'Config > Differential > differential.fields'
        return pht('Renders uplift request form.');
    }

    public function isFieldEnabled() {
        return true;
    }

    public function canDisableField() {
        // Field can't be switched off in configuration
        return false;
    }

    /* -(  ApplicationTransactions  )-------------------------------------------- */

    public function shouldAppearInApplicationTransactions() {
        // Required to be editable
        return true;
    }

    /* -(  Edit View  )---------------------------------------------------------- */

    public function shouldAppearInEditView() {
        // Should the field appear in Edit Revision feature
        return true;
    }

    // How the uplift text is rendered in the "Details" section.
    public function renderPropertyViewValue(array $handles) {
        if (empty($this->getValue())) {
            return null;
        }

        return new PHUIRemarkupView($this->getViewer(), $this->getRemarkup());
    }

    // How the field can be edited in the "Edit Revision" menu.
    public function renderEditControl(array $handles) {
        return null;
    }

    // Convert `bool` types to readable text, or return base text.
    private function valueAsAnswer($value): string {
        if ($value === true) {
            return "yes";
        } else if ($value === false) {
            return "no";
        } else {
            return $value;
        }
    }

    private function getRemarkup(): string {
        $questions = array();

        $value = $this->getValue();
        foreach ($value as $question => $answer) {
            $answer_readable = $this->valueAsAnswer($answer);
            $questions[] = sprintf(
                self::QUESTION_FORMATTING, $question, $answer_readable
            );
        }

        return implode("\n", $questions);
    }

    public function newCommentAction() {
        return null;
    }

    // When storing the value convert the question => answer mapping to a JSON string.
    public function getValueForStorage(): string {
        return phutil_json_encode($this->getValue());
    }

    public function setValueFromStorage($value) {
        try {
            $this->setValue(phutil_json_decode($value));
        } catch (PhutilJSONParserException $ex) {
            $this->setValue(array());
        }
        return $this;
    }

    public function setValueFromApplicationTransactions($value) {
        $this->setValue($value);
        return $this;
    }

    public function setValue($value) {
        if (is_array($value)) {
            parent::setValue($value);
            return;
        }

        try {
            parent::setValue(phutil_json_decode($value));
        } catch (Exception $e) {
            parent::setValue(array());
        }
    }


    /* -(  Property View  )------------------------------------------------------ */

    public function shouldAppearInPropertyView() {
        return true;
    }

    /* -(  Global Search  )------------------------------------------------------ */

    public function shouldAppearInGlobalSearch() {
        return true;
    }

    /* -(  Conduit  )------------------------------------------------------------ */

    public function shouldAppearInConduitDictionary() {
        // Should the field appear in `differential.revision.search`
        return true;
    }

    public function shouldAppearInConduitTransactions() {
        // Required if needs to be saved via Conduit (i.e. from `arc diff`)
        return true;
    }

    protected function newConduitSearchParameterType() {
        return new ConduitStringParameterType();
    }

    protected function newConduitEditParameterType() {
        // Define the type of the parameter for Conduit
        return new ConduitStringParameterType();
    }

    public function readFieldValueFromConduit(string $value) {
        return $value;
    }

    public function isFieldEditable() {
        // Has to be editable to be written from `arc diff`
        return true;
    }

    public function shouldDisableByDefault() {
        return false;
    }

    public function shouldOverwriteWhenCommitMessageIsEdited() {
        return false;
    }

    public function getApplicationTransactionTitle(
        PhabricatorApplicationTransaction $xaction) {

        if($this->proxy) {
            return $this->proxy->getApplicationTransactionTitle($xaction);
        }

        $author_phid = $xaction->getAuthorPHID();

        return pht('%s updated the uplift request field.', $xaction->renderHandleLink($author_phid));
    }

    public function getApplicationTransactionTitleForFeed(
        PhabricatorApplicationTransaction $xaction) {

        if($this->proxy) {
            return $this->proxy->getApplicationTransactionTitle($xaction);
        }

        $author_phid = $xaction->getAuthorPHID();
        $object_phid = $xaction->getObjectPHID();

        return pht(
            '%s updated the uplift request field for %s.',
            $xaction->renderHandleLink($author_phid),
            $xaction->renderHandleLink($object_phid)
        );
    }
}

