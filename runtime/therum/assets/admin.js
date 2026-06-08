/* Therum OS Pure — admin JS.
   - Code / Preview tab toggle in the page editor
   - Delegated confirm() guard on destructive forms (marked data-confirm). */

(function () {
  // Editor tabs
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

  // Delegated confirm guard. Forms render `data-confirm="message"` and we
  // intercept submit at the document level — replaces the inline onsubmit
  // attributes the templates used to ship.
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || !form.dataset || !form.dataset.confirm) return;
    if (!window.confirm(form.dataset.confirm)) {
      e.preventDefault();
    }
  });
})();
