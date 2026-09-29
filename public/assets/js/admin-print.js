/*
 * FILE PURPOSE: Browser-side behavior for Admin Print.
 * DATA FLOW: A rendered page loads this script after the HTML exists. It finds elements through documented selectors/data attributes and enhances interaction without owning server data.
 */

(function () {
  'use strict';
  const printButton = document.querySelector('.admin-print-button');
  if (printButton) {
    printButton.addEventListener('click', function () { window.print(); });
  }
}());
