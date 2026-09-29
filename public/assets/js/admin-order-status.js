document.querySelectorAll('[data-order-status-form]').forEach((form) => {
  const select = form.querySelector('select[name="new_status"]');
  const save = form.querySelector('[data-status-save]');
  if (!select || !save) return;

  const current = select.value;
  let submitting = false;
  // Let the administrator review the choice, then explicitly save it.
  const syncSave = () => { save.disabled = submitting || select.disabled || select.value === current; };
  save.hidden = false;
  syncSave();
  select.addEventListener('change', syncSave);
  form.addEventListener('submit', (event) => {
    if (submitting || select.value === current) {
      event.preventDefault();
      return;
    }
    submitting = true;
    syncSave();
    form.setAttribute('aria-busy', 'true');
    // Disable only the custom trigger; the select must remain in POST data.
    const trigger = form.querySelector('.custom-select__trigger');
    if (trigger) trigger.disabled = true;
  });
  window.addEventListener('pageshow', () => {
    submitting = false;
    form.removeAttribute('aria-busy');
    syncSave();
  });
});
