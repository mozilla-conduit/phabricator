<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * The box above a revision's details showing its AI change state.
 *
 * Below Phabricator's own instructions, it shows the requester's notes on this
 * revision, then the AI's summary of the whole change, each in its own section
 * so reviewers can tell the AI-written text apart.
 */
final class DifferentialAIChangeView extends AphrontView {

  private $revision;

  public function setRevision(DifferentialRevision $revision) {
    $this->revision = $revision;
    return $this;
  }

  public function render() {
    $ai_change = DifferentialAIChange::newFromRevision($this->revision);
    $status = $ai_change->getStatus();

    if ($status === DifferentialAIChange::STATUS_NONE) {
      return null;
    }

    require_celerity_resource('ai-change-css', 'moz-extensions');

    $box = id(new PHUIInfoView())
      ->addClass('ai-change');

    switch ($status) {
      case DifferentialAIChange::STATUS_LAPSED:
        return $box
          ->setSeverity(PHUIInfoView::SEVERITY_NOTICE)
          ->setTitle(pht('AI change: approval no longer required'))
          ->appendChild(
            pht(
              'This revision was updated after the AI change, so normal '.
              'review covers it.'));
      case DifferentialAIChange::STATUS_APPROVED:
        $box
          ->setSeverity(PHUIInfoView::SEVERITY_SUCCESS)
          ->setTitle(
            pht(
              '%s approved',
              ucfirst(
                DifferentialAIChange::getKindName($ai_change->getKind()))))
          ->appendChild(
            phutil_tag(
              'p',
              array(),
              pht(
                '%s approved the change in diff %s.',
                $this->getViewer()->renderHandle(
                  $ai_change->getApproverPHID()),
                $ai_change->getChangeDiffID())));
        break;
      default:
        if ($this->revision->isClosed()) {
          // Nothing can land a closed revision, so don't ask for an approval.
          $box
            ->setSeverity(PHUIInfoView::SEVERITY_NOTICE)
            ->setTitle(
              pht(
                '%s not approved',
                ucfirst(
                  DifferentialAIChange::getKindName($ai_change->getKind()))))
            ->appendChild(
              phutil_tag(
                'p',
                array(),
                pht(
                  'This revision was closed before anyone approved the AI '.
                  'change.')));
          break;
        }

        $box
          ->setSeverity(PHUIInfoView::SEVERITY_WARNING)
          ->setTitle(
            pht(
              'This revision has an %s to approve',
              DifferentialAIChange::getKindName($ai_change->getKind())))
          ->appendChild(
            phutil_tag(
              'p',
              array(),
              pht(
                'Check the change, then use "Approve AI Change" in '.
                'the actions below. Lando won\'t land this revision until '.
                'someone approves it.')));
        break;
    }

    // The details are specific to this revision, while the summary may cover a
    // whole stack, so the details come first.
    $box->appendChild($this->renderDetails($ai_change));
    $box->appendChild($this->renderSummary($ai_change));

    $compare_button = $this->renderCompareButton($ai_change);
    if ($compare_button) {
      $box->addButton($compare_button);
    }

    return $box;
  }

  private function renderSummary(DifferentialAIChange $ai_change) {
    $summary = $ai_change->getSummary();
    $confidence = $ai_change->getConfidence();
    if (!strlen($summary ?? '') && !strlen($confidence ?? '')) {
      return null;
    }

    $parts = array();
    if (strlen($summary ?? '')) {
      $parts[] = $summary;
    }
    if (strlen($confidence ?? '')) {
      $parts[] = $this->renderConfidence($confidence);
    }

    return $this->renderSection(
      pht('AI summary'),
      phutil_tag(
        'p',
        array('class' => 'ai-change-summary'),
        phutil_implode_html(' ', $parts)));
  }

  /**
   * Render the requester's confidence as a tag, coloured by how sure it is.
   */
  private function renderConfidence($confidence) {
    $colors = array(
      'high' => PHUITagView::COLOR_GREEN,
      'medium' => PHUITagView::COLOR_ORANGE,
      'low' => PHUITagView::COLOR_RED,
    );
    $color = idx(
      $colors,
      phutil_utf8_strtolower($confidence),
      PHUITagView::COLOR_GREY);

    return id(new PHUITagView())
      ->setType(PHUITagView::TYPE_SHADE)
      ->setColor($color)
      ->addClass('ai-change-confidence')
      ->setName(pht('Confidence: %s', $confidence));
  }

  private function renderDetails(DifferentialAIChange $ai_change) {
    $details = $ai_change->getDetails();
    if (!strlen($details ?? '')) {
      return null;
    }

    return $this->renderSection(
      pht('About this revision'),
      phutil_tag(
        'div',
        array('class' => 'ai-change-details'),
        new PHUIRemarkupView($this->getViewer(), $details)));
  }

  /**
   * Render one titled section of the box, set apart from the text above it.
   */
  private function renderSection($title, $content) {
    return phutil_tag(
      'div',
      array('class' => 'ai-change-section'),
      array(
        phutil_tag('div', array('class' => 'ai-change-section-title'), $title),
        $content,
      ));
  }

  private function renderCompareButton(DifferentialAIChange $ai_change) {
    $base_diff_id = $ai_change->getBaseDiffID();
    if (!$base_diff_id) {
      return null;
    }

    $change_diff_id = $ai_change->getChangeDiffID();
    $uri = id(new PhutilURI($this->revision->getURI()))
      ->replaceQueryParam('vs', $base_diff_id)
      ->replaceQueryParam('id', $change_diff_id)
      ->setFragment('toc');

    return id(new PHUIButtonView())
      ->setTag('a')
      ->setColor(PHUIButtonView::GREY)
      ->setIcon('fa-columns')
      ->setHref($uri)
      ->setText(
        pht(
          'View AI changes (diff %s to %s)',
          $base_diff_id,
          $change_diff_id));
  }

}
