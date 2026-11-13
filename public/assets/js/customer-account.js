(() => {
  'use strict';

  // Hash links are real URLs and work without JavaScript. Reflect the current
  // section in navigation when a customer jumps directly to orders/addresses.
  const reflectLocation = () => {
    const page = location.pathname.split('/').pop();
    const activeRoute =
      page === 'profile.php'
        ? 'profile.php' + (location.hash === '#addresses' ? '#addresses' : '')
        : 'account.php' + (location.hash === '#orders' ? '#orders' : '');
    document.querySelectorAll('.account-navigation a').forEach((link) => {
      const url = new URL(link.href);
      const route = url.pathname.split('/').pop() + url.hash;
      if (route === activeRoute) link.setAttribute('aria-current', 'page');
      else link.removeAttribute('aria-current');
    });
  };
  reflectLocation();
  window.addEventListener('hashchange', reflectLocation);

  const form = document.querySelector('[data-account-profile]');
  if (!form) return;
  const savedCards = [...form.querySelectorAll('[data-saved-address]')];
  const newCards = [...form.querySelectorAll('[data-new-address]')];
  const hasEntries = (card) =>
    [...card.querySelectorAll('input')].some((input) => input.value.trim() !== '');

  const syncAddresses = () => {
    let kept = 0;
    savedCards.forEach((card) => {
      const removed = card.querySelector('[data-remove-address]').checked;
      card.classList.toggle('is-removing', removed);
      if (!removed) kept++;
      // Removed addresses are ignored by the server. Disable their text fields
      // so native required-field validation does not block an intentional removal.
      card.querySelectorAll('input:not([data-remove-address])').forEach((input) => {
        input.disabled = removed;
      });
    });
    const selected = form.querySelector('[name=primary_address_id]:checked:not(:disabled)');
    if (!selected) {
      const replacement = form.querySelector('[name=primary_address_id]:not(:disabled)');
      if (replacement) replacement.checked = true;
    }
    const capacity = Math.max(0, 3 - kept);
    newCards.forEach((card, index) => {
      const populated = hasEntries(card);
      card.hidden = index >= capacity && !populated;
      if (card.hidden) card.open = false;
      card.querySelectorAll('input').forEach((input) => {
        input.required = populated && !input.name.endsWith('[address_label]');
      });
    });
    const limit = form.querySelector('[data-address-limit]');
    if (limit) limit.hidden = capacity > 0;
  };
  syncAddresses();

  const signature = () => JSON.stringify([...new FormData(form).entries()]);
  const initial = signature();
  const saveState = form.querySelector('[data-profile-save-state]');
  let submitting = false;
  const dirty = () => signature() !== initial;
  const update = () => {
    syncAddresses();
    saveState.textContent = dirty() ? saveState.dataset.unsavedLabel : saveState.dataset.savedLabel;
    saveState.classList.toggle('is-unsaved', dirty());
  };
  form.addEventListener('input', update);
  form.addEventListener('change', update);
  form.addEventListener(
    'invalid',
    (event) => {
      const details = event.target.closest('details');
      if (details) details.open = true;
    },
    true,
  );
  form.addEventListener('submit', () => {
    submitting = true;
  });
  window.addEventListener('beforeunload', (event) => {
    if (submitting || !dirty()) return;
    event.preventDefault();
    event.returnValue = '';
  });
  document.querySelector('[data-profile-errors]')?.focus();
})();
