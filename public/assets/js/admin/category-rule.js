// Opens and closes the pricing-rule dialog on the Products page.
// The dialog is a native <dialog>, so the browser handles focus, Escape and the backdrop.
(function () {
  'use strict';

  function showDialog(dialog) {
    if (typeof dialog.showModal === 'function') {
      dialog.showModal();
    } else {
      dialog.setAttribute('open', '');
    }
  }

  document.querySelectorAll('[data-dialog-open]').forEach(function (button) {
    button.addEventListener('click', function () {
      var dialog = document.getElementById(button.getAttribute('data-dialog-open'));
      if (dialog) {
        showDialog(dialog);
      }
    });
  });

  document.querySelectorAll('[data-dialog-close]').forEach(function (button) {
    button.addEventListener('click', function () {
      var dialog = document.getElementById(button.getAttribute('data-dialog-close'));
      if (dialog && typeof dialog.close === 'function') {
        dialog.close();
      }
    });
  });

  // A save that failed reopens the dialog so the administrator can correct the text.
  document.querySelectorAll('dialog[data-open-on-load]').forEach(showDialog);
})();
