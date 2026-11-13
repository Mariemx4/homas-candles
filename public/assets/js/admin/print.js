(function () {
  'use strict';
  const printButton = document.querySelector('.admin-print-button');
  if (printButton) {
    printButton.addEventListener('click', function () {
      window.print();
    });
  }
})();
