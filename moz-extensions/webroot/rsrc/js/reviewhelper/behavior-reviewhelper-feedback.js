/**
 * @provides javelin-behavior-reviewhelper-feedback
 */

JX.behavior("reviewhelper-feedback", function (config) {
  var botUsername = config.botUsername;
  var feedbackURI = config.feedbackURI;

  function createFeedbackButton(icon, tooltip, feedbackType, commentId) {
    var button = JX.$N(
      "button",
      {
        className:
          "button phui-button-simple msl reviewhelper-feedback-" + feedbackType,
        type: "button",
        title: tooltip,
      },
      JX.$N("span", { className: "phui-icon-view phui-font-fa " + icon }),
    );

    JX.DOM.listen(button, "click", null, function (e) {
      e.kill();
      submitFeedback(button, commentId, feedbackType);
    });

    return button;
  }

  function isEnhanced(commentNode) {
    return !!commentNode.querySelector(".reviewhelper-feedback-footer");
  }

  function setButtonsDisabled(buttons, disabled) {
    for (var i = 0; i < buttons.length; i++) {
      buttons[i].disabled = disabled;
      JX.DOM.alterClass(buttons[i], "disabled", disabled);
    }
  }

  function submitFeedback(buttonNode, commentId, feedbackType) {
    var container = buttonNode.parentNode;
    var buttons = container.querySelectorAll("button");

    JX.DOM.alterClass(buttonNode, "loading", true);

    // Disable all buttons
    setButtonsDisabled(buttons, true);

    var request = new JX.Request(feedbackURI, function (response) {
      var colorClass = feedbackType === "up" ? "button-green" : "button-red";
      JX.DOM.alterClass(buttonNode, colorClass, true);
    });

    request.listen("error", function (error) {
      // Re-enable buttons on error (we keep them disabled on success)
      setButtonsDisabled(buttons, false);

      var message = error || "Failed to submit feedback to Review Helper.";
      new JX.Notification().setContent(message).setDuration(10000).show();
    });

    request.listen("finally", function () {
      JX.DOM.alterClass(buttonNode, "loading", false);
    });

    request.setData({
      commentID: commentId,
      feedbackType: feedbackType,
    });

    request.send();
  }

  function enhanceComment(commentNode) {
    var data = JX.Stratcom.getData(commentNode);

    var commentId = data.id;
    if (!commentId) {
      return;
    }

    // Avoid adding feedback UI multiple times
    if (isEnhanced(commentNode)) {
      return;
    }

    // We only want to enhance comments made by the Review Helper bot
    var authorName = data.snippet ? data.snippet.split(":")[0] : null;
    if (authorName !== botUsername) {
      return;
    }

    // Find the content div to insert footer after it
    var contentDiv = commentNode.querySelector(
      ".differential-inline-comment-content",
    );
    if (!contentDiv) {
      return;
    }

    var thumbsUpBtn = createFeedbackButton(
      "fa-thumbs-up",
      "Helpful",
      "up",
      commentId,
    );
    var thumbsDownBtn = createFeedbackButton(
      "fa-thumbs-down",
      "Not helpful",
      "down",
      commentId,
    );

    var feedbackFooter = JX.$N(
      "div",
      {
        className: "reviewhelper-feedback-footer pm",
      },
      [
        JX.$N("span", { className: "grey mmr" }, "Was this comment helpful?"),
        thumbsUpBtn,
        thumbsDownBtn,
      ],
    );

    // Insert after the content div
    contentDiv.parentNode.insertBefore(feedbackFooter, contentDiv.nextSibling);
  }

  function processExistingComments() {
    var comments = JX.DOM.scry(
      document.body,
      "div",
      "differential-inline-comment",
    );

    for (var i = 0; i < comments.length; i++) {
      enhanceComment(comments[i]);
    }
  }

  // Listen for resize events which fire when:
  // 1. Initial page load completes
  // 2. View changes between unified and side-by-side mode
  // 3. Inline comments are saved (after DOM is updated)
  JX.Stratcom.listen("resize", null, function () {
    processExistingComments();
  });
});
