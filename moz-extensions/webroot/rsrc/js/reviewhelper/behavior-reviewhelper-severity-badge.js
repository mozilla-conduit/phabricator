/**
 * @provides javelin-behavior-reviewhelper-severity-badge
 */

// Show the severity and category frontmatter of inline comments as badges in
// the comment header. This only runs for comments by accounts in
// `reviewhelper.severity-badge-user-phids`, so other users don't get a badge.
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

    addBadges(commentNode, contentDiv);
  }

  // Move the severity and category parts of the label rendered from the
  // `reviewhelper:` frontmatter into separate badges in the comment header.
  function addBadges(commentNode, contentDiv) {
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

    var severityPart = label.querySelector('.reviewhelper-severity-part');
    if (!severityPart) {
      return;
    }

    headLeft.appendChild(newBadge(
      severityPart,
      'reviewhelper-severity-badge reviewhelper-severity-' +
        frontmatter.severity));

    var categoryPart = label.querySelector('.reviewhelper-category-part');
    if (categoryPart) {
      headLeft.appendChild(
        newBadge(categoryPart, 'reviewhelper-category-badge'));
    }

    JX.DOM.remove(label);
  }

  // Build a badge holding the child nodes of a label part.
  function newBadge(part, className) {
    return JX.$N(
      'span',
      {className: className},
      JX.$A(part.childNodes));
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
