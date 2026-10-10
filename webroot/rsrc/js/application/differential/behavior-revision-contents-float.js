/**
 * @provides javelin-behavior-differential-revision-contents-float
 * @requires javelin-behavior
 *           javelin-stratcom
 *           javelin-dom
 *           javelin-vector
 *           phabricator-keyboard-shortcut
 */

JX.behavior('differential-revision-contents-float', function(config) {
  var box = JX.$(config.boxID);
  var button = JX.DOM.find(box, 'a', 'differential-revision-contents-float');
  var floating = false;
  var dragging = null;

  function find_by_class(root, tag, class_name) {
    var nodes = JX.DOM.scry(root, tag);
    for (var ii = 0; ii < nodes.length; ii++) {
      var classes = ' ' + nodes[ii].className + ' ';
      if (classes.indexOf(' ' + class_name + ' ') !== -1) {
        return nodes[ii];
      }
    }
    return null;
  }

  var label = find_by_class(button, 'div', 'phui-button-text');
  var icon = find_by_class(button, 'span', 'phui-icon-view');

  var resizer = JX.$N(
    'div',
    {className: 'differential-revision-contents-resizer'});
  box.appendChild(resizer);

  function toggle() {
    floating = !floating;
    JX.DOM.alterClass(box, 'differential-revision-contents-floating', floating);
    if (!floating) {
      box.style.width = '';
      box.style.height = '';
    }

    JX.DOM.setContent(label, floating ? config.dockLabel : config.floatLabel);
    JX.DOM.alterClass(icon, 'fa-window-restore', !floating);
    JX.DOM.alterClass(icon, 'fa-compress', floating);
  }

  JX.Stratcom.listen(
    'click',
    'differential-revision-contents-float',
    function(e) {
      e.kill();
      toggle();
    });

  // The panel is anchored to the right edge of the window, so it is resized
  // from its bottom-left corner: dragging left makes it wider.
  JX.DOM.listen(resizer, 'mousedown', null, function(e) {
    if (!e.isNormalMouseEvent()) {
      return;
    }

    dragging = {
      start: JX.$V(e),
      size: JX.Vector.getDim(box)
    };
    JX.DOM.alterClass(
      document.body,
      'differential-revision-contents-resizing',
      true);

    e.kill();
  });

  JX.Stratcom.listen('mousemove', null, function(e) {
    if (!dragging) {
      return;
    }

    var p = JX.$V(e);
    box.style.width = (dragging.size.x + dragging.start.x - p.x) + 'px';
    box.style.height = (dragging.size.y + p.y - dragging.start.y) + 'px';
  });

  JX.Stratcom.listen('mouseup', null, function() {
    if (!dragging) {
      return;
    }

    dragging = null;
    JX.DOM.alterClass(
      document.body,
      'differential-revision-contents-resizing',
      false);
  });

  new JX.KeyboardShortcut('s', config.shortcutLabel)
    .setHandler(toggle)
    .register();
});
