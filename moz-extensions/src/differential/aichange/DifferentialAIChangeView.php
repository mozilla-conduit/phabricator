<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * The box above a revision's details showing its AI change state.
 *
 * Below Phabricator's own instructions, it shows the AI's confidence. The
 * requester's notes on this revision and the AI summary are folded away under
 * "Show details", each in its own labelled section so reviewers can tell the
 * AI-written text apart.
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

    $kind_name = ucfirst(
      DifferentialAIChange::getKindName($ai_change->getKind()));
    $approval = $this->renderApproval($ai_change);

    if ($this->revision->isClosed()) {
      // Landing attaches a new diff, so a closed revision's change looks
      // lapsed. Say whether it was approved instead.
      if ($approval) {
        $box
          ->setSeverity(PHUIInfoView::SEVERITY_SUCCESS)
          ->setTitle(pht('%s approved', $kind_name))
          ->appendChild($approval);
      } else {
        $box
          ->setSeverity(PHUIInfoView::SEVERITY_NOTICE)
          ->setTitle(pht('%s not approved', $kind_name))
          ->appendChild(
            phutil_tag(
              'p',
              array(),
              pht(
                'This revision was closed before anyone approved the AI '.
                'change.')));
      }
    } else if ($status === DifferentialAIChange::STATUS_LAPSED) {
      $box
        ->setSeverity(PHUIInfoView::SEVERITY_NOTICE)
        ->setTitle(pht('AI change: approval no longer required'))
        ->appendChild(
          phutil_tag(
            'p',
            array(),
            pht(
              'This revision was updated after the AI change, so normal '.
              'review covers it.')))
        ->appendChild($approval);
    } else if ($status === DifferentialAIChange::STATUS_APPROVED) {
      $box
        ->setSeverity(PHUIInfoView::SEVERITY_SUCCESS)
        ->setTitle(pht('%s approved', $kind_name))
        ->appendChild($approval);
    } else {
      $box
        ->setSeverity(PHUIInfoView::SEVERITY_WARNING)
        ->setTitle(
          pht(
            'This revision has an %s to approve',
            DifferentialAIChange::getKindName($ai_change->getKind())))
        ->appendChild(phutil_tag('p', array(), $this->renderPendingText()));
    }

    $box->appendChild($this->renderConfidenceLine($ai_change));
    $box->appendChild($this->renderMore($ai_change));

    $compare_button = $this->renderCompareButton($ai_change);
    if ($compare_button) {
      $box->addButton($compare_button);
    }

    return $box;
  }

  /**
   * Point viewers who can approve to the action, and tell everyone else why
   * the revision is waiting.
   */
  private function renderPendingText() {
    $can_approve = id(new DifferentialRevisionAIChangeApproveTransaction())
      ->isActionAvailable($this->revision, $this->getViewer());
    if (!$can_approve) {
      return pht(
        'Lando won\'t land this revision until someone approves the AI '.
        'change.');
    }

    return pht(
      'Check the change, then use "Approve AI Change" in the actions '.
      'below. Lando won\'t land this revision until someone approves it.');
  }

  /**
   * Render who approved the AI change, or `null` if nobody has.
   */
  private function renderApproval(DifferentialAIChange $ai_change) {
    $approver_phid = $ai_change->getApproverPHID();
    if (!$approver_phid) {
      return null;
    }

    return phutil_tag(
      'p',
      array(),
      pht(
        '%s approved the change in diff %s.',
        $this->getViewer()->renderHandle($approver_phid),
        $ai_change->getChangeDiffID()));
  }

  /**
   * Render the AI's confidence, shown before the details are expanded.
   */
  private function renderConfidenceLine(DifferentialAIChange $ai_change) {
    $confidence = $ai_change->getConfidence() ?? '';
    if (!strlen($confidence)) {
      return null;
    }

    return phutil_tag(
      'p',
      array('class' => 'ai-change-confidence-line'),
      $this->renderConfidence($confidence));
  }

  /**
   * Render the revision's details and the AI summary, folded away.
   *
   * The details are specific to this revision, while the summary may cover a
   * whole stack, so the details come first.
   */
  private function renderMore(DifferentialAIChange $ai_change) {
    $sections = array_filter(array(
      $this->renderDetails($ai_change),
      $this->renderSummary($ai_change),
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

  private function renderSummary(DifferentialAIChange $ai_change) {
    $summary = $ai_change->getSummary() ?? '';
    if (!strlen($summary)) {
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
