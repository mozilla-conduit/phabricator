/**
 * @provides javelin-behavior-testing-policy
 */

/**
 * Reminds reviewers to set a testing policy project tag when accepting a
 * revision, and starts a comment explaining the testing exception for the tags
 * which require one.
 */
JX.behavior('testing-policy', function (config) {
  var testingTags = config.testingTags;
  var projectPHIDs = config.projectPHIDs;
  var commentTagPHIDs = config.commentTagPHIDs;

  var noticeNode = null;
  // The project tags in the "Change Project Tags" action the last time it
  // changed, or null if the action isn't stacked.
  var lastActionPHIDs = null;
  // Whether "Accept Revision" was stacked the last time the actions changed,
  // or null before the first change.
  var wasAccepted = null;
  var searchPending = false;

  function hasTestingTag(phids) {
    for (var i = 0; i < phids.length; i++) {
      if (testingTags.hasOwnProperty(phids[i])) {
        return true;
      }
    }
    return false;
  }

  function getNotice() {
    if (!noticeNode) {
      noticeNode = JX.$N('div', { className: 'testing-policy-notice' }, [
        'Please add a ',
        JX.$N(
          'a',
          { href: config.policyURI, target: '_blank' },
          'testing policy'
        ),
        ' project tag.'
      ]);
    }
    return noticeNode;
  }

  function showNotice(actionNode) {
    var notice = getNotice();
    if (notice.parentNode) {
      return;
    }

    var actionBar = actionNode.closest('.phui-comment-action-bar');
    actionBar.parentNode.insertBefore(notice, actionBar.nextSibling);
  }

  function hideNotice() {
    if (noticeNode && noticeNode.parentNode) {
      JX.DOM.remove(noticeNode);
    }
  }

  // Stack the "Change Project Tags" action, so the reviewer can pick a tag
  // right away.
  function openProjectAction(actionNode) {
    var option = actionNode.querySelector('option[value="projectPHIDs"]');
    if (!option || option.disabled) {
      return;
    }

    searchPending = true;
    actionNode.value = 'projectPHIDs';
    actionNode.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function startTestingSearch(rowNode) {
    // Let the action selector finish handling the change first.
    setTimeout(function () {
      var input = rowNode.querySelector('.jx-tokenizer-input');
      if (!input) {
        return;
      }

      input.focus();
      input.value = 'testing-';
      input.dispatchEvent(new Event('keydown', { bubbles: true }));
    }, 0);
  }

  function addExceptionComment(formNode, rowNode, phid) {
    var textarea = formNode.querySelector('textarea.remarkup-assist-textarea');
    if (!textarea) {
      return;
    }

    var label = '{nav, icon=tags, name=' + testingTags[phid] + '}: ';
    if (textarea.value.indexOf(label) !== -1) {
      return;
    }

    textarea.value = textarea.value ? textarea.value + '\n\n' + label : label;

    // Setting the value doesn't fire any event, so ask the form to update its
    // preview and draft.
    JX.DOM.invoke(formNode, 'shouldRefresh');

    // Move the focus once the tokenizer is done with the selection: it puts the
    // focus back on its input, or the body when the result was clicked, since
    // the typeahead doesn't prevent the mousedown default. Leave it alone if
    // the reviewer has moved on or the form is gone.
    setTimeout(function () {
      if (!document.body.contains(textarea)) {
        return;
      }
      if (!document.body.contains(rowNode)) {
        return;
      }

      var active = document.activeElement;
      if (active && active !== document.body && !rowNode.contains(active)) {
        return;
      }

      textarea.focus();
      textarea.selectionStart = textarea.value.length;
      textarea.selectionEnd = textarea.value.length;
    }, 0);
  }

  JX.Stratcom.listen('comment-actions-change', null, function (e) {
    var data = e.getData();
    var actions = data.actions;
    var projectAction = actions.projectPHIDs || null;

    if (projectAction) {
      var currentPHIDs = projectAction.value;

      // Only react to tags the reviewer just added, not to the ones restored
      // from a draft or already on the revision when the action was stacked.
      if (lastActionPHIDs !== null) {
        for (var i = 0; i < currentPHIDs.length; i++) {
          var phid = currentPHIDs[i];
          if (
            commentTagPHIDs.indexOf(phid) !== -1 &&
            lastActionPHIDs.indexOf(phid) === -1
          ) {
            addExceptionComment(data.formNode, projectAction.node, phid);
          }
        }
      }

      lastActionPHIDs = currentPHIDs;

      if (searchPending) {
        searchPending = false;
        startTestingSearch(projectAction.node);
      }
    } else {
      lastActionPHIDs = null;
    }

    var isAccepted = actions.hasOwnProperty('accept');
    var justAccepted = isAccepted && wasAccepted === false;
    wasAccepted = isAccepted;

    var tagged = hasTestingTag(
      projectAction ? projectAction.value : projectPHIDs
    );

    if (!isAccepted || tagged) {
      hideNotice();
      return;
    }

    showNotice(data.actionNode);

    // Only open the project tags action when the reviewer picks "Accept", so
    // that it doesn't come back when they remove it, and doesn't steal the
    // focus when an accepting draft is restored on page load.
    if (justAccepted && !projectAction) {
      openProjectAction(data.actionNode);
    }
  });
});
