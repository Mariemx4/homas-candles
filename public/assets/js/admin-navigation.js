/*
 * FILE PURPOSE: Browser-side behavior for Admin Navigation.
 * DATA FLOW: A rendered page loads this script after the HTML exists. It finds elements through documented selectors/data attributes and enhances interaction without owning server data.
 */

// An immediately invoked function expression (IIFE) creates a private scope, so
// these variable/function names do not become globals shared with other scripts.
(function () {
  'use strict';

  // Cache the DOM elements used repeatedly instead of querying on every click.
  const body = document.body;
  const navigation = document.getElementById('admin-navigation');
  const openButton = document.querySelector('.admin-menu-button');
  const closeButton = document.querySelector('.admin-nav-close');
  const backdrop = document.querySelector('.admin-nav-backdrop');
  const mobileQuery = window.matchMedia('(max-width: 56rem)');

  // Progressive enhancement guard: if this page lacks the admin navigation
  // markup, stop quietly instead of throwing a JavaScript error.
  if (!navigation || !openButton || !closeButton || !backdrop) {
    return;
  }

  let lastFocusedElement = null;
  const focusableSelector = 'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])';

  /** Read the CSS state class that represents an open mobile menu. */
  /** FUNCTION PURPOSE: Handle is open as one named browser-interaction step. */
  function isOpen() {
    return body.classList.contains('admin-nav-open');
  }

  /** Open the drawer, update assistive text, and move keyboard focus inside. */
  /** FUNCTION PURPOSE: Open navigation and update its accessibility/focus state. */
  function openNavigation() {
    lastFocusedElement = document.activeElement;
    body.classList.add('admin-nav-open');
    openButton.setAttribute('aria-expanded', 'true');
    openButton.querySelector('.admin-menu-button__label').textContent = 'Navigation open';
    closeButton.focus();
  }

  /** Close the drawer and optionally return focus to the element used before it. */
  /** FUNCTION PURPOSE: Close navigation and restore its neutral accessibility/focus state. */
  function closeNavigation(restoreFocus) {
    body.classList.remove('admin-nav-open');
    openButton.setAttribute('aria-expanded', 'false');
    openButton.querySelector('.admin-menu-button__label').textContent = 'Open navigation';
    if (restoreFocus && lastFocusedElement instanceof HTMLElement) {
      lastFocusedElement.focus();
    }
  }

  /**
   * Keep Tab/Shift+Tab cycling inside the open mobile drawer. Without this,
   * keyboard users could focus hidden page controls behind the modal menu.
   */
  function trapFocus(event) {
    if (event.key !== 'Tab' || !isOpen() || !mobileQuery.matches) {
      return;
    }
    // querySelectorAll returns a NodeList; Array.from enables first/last indexing.
    const focusable = Array.from(navigation.querySelectorAll(focusableSelector));
    if (focusable.length === 0) {
      event.preventDefault();
      return;
    }
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  // Event wiring: mouse/touch controls, Escape/Tab keyboard behavior, and
  // automatic cleanup when the viewport leaves mobile size.
  openButton.addEventListener('click', openNavigation);
  closeButton.addEventListener('click', function () { closeNavigation(true); });
  backdrop.addEventListener('click', function () { closeNavigation(true); });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && isOpen()) {
      closeNavigation(true);
      return;
    }
    trapFocus(event);
  });
  mobileQuery.addEventListener('change', function (event) {
    if (!event.matches) {
      closeNavigation(false);
    }
  });
}());
