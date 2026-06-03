/* Therum OS Pure — admin JS. v1 only wires the Code / Preview tab toggle
   in the page editor. Block-mode editor lands in a later release. */

(function () {
  document.querySelectorAll('.t-editor').forEach(function (editor) {
    var tabs    = editor.querySelectorAll('.t-tab');
    var code    = editor.querySelector('.t-editor-code');
    var preview = editor.querySelector('.t-editor-preview');
    if (!tabs.length || !code || !preview) return;

    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        tabs.forEach(function (t) { t.classList.remove('is-active'); });
        tab.classList.add('is-active');
        var which = tab.getAttribute('data-tab');
        if (which === 'preview') {
          preview.innerHTML = code.value;
          preview.hidden = false;
          code.hidden = true;
        } else {
          preview.hidden = true;
          code.hidden = false;
        }
      });
    });
  });
})();
