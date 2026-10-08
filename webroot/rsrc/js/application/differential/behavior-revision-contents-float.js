/**
 * @provides javelin-behavior-differential-revision-contents-float
 * @requires javelin-behavior
 *           javelin-stratcom
 *           javelin-dom
 *           phabricator-keyboard-shortcut
 */

JX.behavior('differential-revision-contents-float', function(config) {
  var box = JX.$(config.boxID);
  var button = JX.DOM.find(box, 'a', 'differential-revision-contents-float');
  var floating = false;

  function toggle() {
    floating = !floating;
    JX.DOM.alterClass(box, 'differential-revision-contents-floating', floating);
    if (!floating) {
      box.style.width = '';
      box.style.height = '';
    }

    var label = JX.DOM.scry(button, 'div')[0];
    var icon = JX.DOM.scry(button, 'span')[0];

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

  new JX.KeyboardShortcut('s', config.shortcutLabel)
    .setHandler(toggle)
    .register();
});
