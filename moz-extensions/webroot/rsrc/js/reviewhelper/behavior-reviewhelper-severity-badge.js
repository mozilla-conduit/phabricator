/**
 * @provides javelin-behavior-reviewhelper-severity-badge
 */

// Show the severity frontmatter of inline comments as a badge in the comment
// header. This only runs for comments by accounts in
// `reviewhelper.severity-badge-usernames`, so other users don't get a badge.
JX.behavior("reviewhelper-severity-badge", function (config) {
  var usernames = config.usernames;

  function enhanceComment(commentNode) {
    var data = JX.Stratcom.getData(commentNode);
    var authorName = data.snippet ? data.snippet.split(":")[0] : null;
    if (usernames.indexOf(authorName) === -1) {
      return;
    }

    var contentDiv = commentNode.querySelector(
      ".differential-inline-comment-content",
    );
    if (!contentDiv) {
      return;
    }

    addSeverityBadge(commentNode, contentDiv);
  }

  // Move the severity label rendered from the `reviewhelper:` frontmatter into
  // a badge in the comment header.
  function addSeverityBadge(commentNode, contentDiv) {
    // The rule only matches at the start of a comment, so the comment's own
    // label is a direct child of its Remarkup container. Labels deeper down
    // belong to quoted comments.
    var label = contentDiv.querySelector(
      ':scope > .phabricator-remarkup > .reviewhelper-severity-label');
    if (!label) {
      return;
    }

    var headLeft = commentNode.querySelector('.inline-head-left');
    if (!headLeft) {
      return;
    }

    // The `ReviewHelperFrontmatter` dictionary rendered by
    // `ReviewHelperFrontmatterRemarkupRule`.
    var frontmatter;
    try {
      frontmatter = JSON.parse(
        label.getAttribute('data-reviewhelper-frontmatter'));
    } catch (error) {
      return;
    }
    if (!frontmatter || !frontmatter.severity) {
      return;
    }

    var badge = JX.$N(
      'span',
      {
        className:
          'reviewhelper-severity-badge reviewhelper-severity-' +
          frontmatter.severity,
        title: frontmatter.confidence ?
          'Confidence: ' + frontmatter.confidence :
          ''
      },
      label.textContent);

    headLeft.appendChild(badge);
    JX.DOM.remove(label);
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

  // Inline comments are rendered, re-rendered when the view mode changes, and
  // added after saving, each followed by a resize event.
  JX.Stratcom.listen("resize", null, function () {
    processExistingComments();
  });
});
