<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * The box above a revision's details showing its AI change state.
 *
 * Below Phabricator's own instructions, it shows a short AI summary and its
 * confidence. The requester's notes on this revision and the full AI summary are
 * folded away under "Show details", each in its own labelled section so
 * reviewers can tell the AI-written text apart.
 */
final class DifferentialAIChangeView extends AphrontView {

  // How much of the AI summary shows before the details are expanded.
  const SHORT_SUMMARY_GLYPHS = 200;

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

    $box->appendChild($this->renderShortSummary($ai_change));
    $box->appendChild($this->renderMore($ai_change));

    $compare_button = $this->renderCompareButton($ai_change);
    if ($compare_button) {
      $box->addButton($compare_button);
    }

    return $box;
  }

  /**
   * Render the confidence and the start of the AI summary, shown by default.
   */
  private function renderShortSummary(DifferentialAIChange $ai_change) {
    $summary = $ai_change->getSummary() ?? '';
    $confidence = $ai_change->getConfidence() ?? '';
    if (!strlen($summary) && !strlen($confidence)) {
      return null;
    }

    $parts = array();
    if (strlen($confidence)) {
      $parts[] = $this->renderConfidence($confidence);
    }
    if (strlen($summary)) {
      $parts[] = $this->shortenSummary($summary);
    }

    return phutil_tag(
      'p',
      array('class' => 'ai-change-summary'),
      phutil_implode_html(' ', $parts));
  }

  private function shortenSummary($summary) {
    return id(new PhutilUTF8StringTruncator())
      ->setMaximumGlyphs(self::SHORT_SUMMARY_GLYPHS)
      ->truncateString($summary);
  }

  /**
   * Render the revision's details and the full AI summary, folded away.
   *
   * The details are specific to this revision, while the summary may cover a
   * whole stack, so the details come first. The full summary is only repeated
   * when the short one had to be cut.
   */
  private function renderMore(DifferentialAIChange $ai_change) {
    $sections = array_filter(array(
      $this->renderDetails($ai_change),
      $this->renderFullSummary($ai_change),
    ));
    if (!$sections) {
      return null;
    }

    return phutil_tag(
      'details',
      array('class' => 'ai-change-more'),
      array(
        phutil_tag('summary', array(), pht('Show details')),
        $sections,
      ));
  }

  private function renderFullSummary(DifferentialAIChange $ai_change) {
    $summary = $ai_change->getSummary() ?? '';
    if ($this->shortenSummary($summary) === $summary) {
      return null;
    }

    return $this->renderSection(
      pht('AI summary'),
      phutil_tag('p', array('class' => 'ai-change-summary'), $summary));
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
