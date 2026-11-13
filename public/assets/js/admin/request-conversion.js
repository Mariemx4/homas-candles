'use strict';
document.querySelectorAll('[data-conversion-form]').forEach((form) => {
  const summary = form.querySelector('[data-conversion-quote]');
  const total = form.elements.namedItem('quote_total');
  const create = form.querySelector('[data-conversion-create]');
  let timer,
    revision = 0,
    submitting = false;
  const invalidate = () => {
    total.value = '';
    create.disabled = true;
    ++revision;
  };
  const preview = async () => {
    clearTimeout(timer);
    const request = revision;
    summary.textContent = 'Checking the quotation…';
    try {
      const data = new FormData(form);
      data.set('conversion_action', 'preview');
      const response = await fetch(form.action, {
        method: 'POST',
        body: data,
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      if (!response.ok)
        throw Error('The quotation could not be checked. Use Review totals to try again.');
      const result = await response.json();
      if (request !== revision || submitting) return;
      if (!result.success)
        throw Error(Object.values(result.errors || {}).join(' ') || 'Complete the quotation.');
      const list = document.createElement('dl');
      list.className = 'admin-definition-list';
      [
        ['Products', 'subtotal'],
        ['Delivery', 'delivery_fee'],
        ['Customer total', 'total'],
        ['Required deposit', 'deposit'],
      ].forEach(([label, key]) => {
        const row = document.createElement('div'),
          dt = document.createElement('dt'),
          dd = document.createElement('dd');
        dt.textContent = label;
        dd.textContent = result.totals[key] + ' TND';
        row.append(dt, dd);
        list.append(row);
      });
      summary.replaceChildren(list);
      total.value = result.totals.total;
      create.disabled = false;
    } catch (error) {
      if (request === revision && !submitting) {
        summary.textContent = error.message;
        create.disabled = true;
      }
    }
  };
  const schedule = () => {
    invalidate();
    clearTimeout(timer);
    timer = setTimeout(preview, 300);
  };
  form.addEventListener('input', schedule);
  form.addEventListener('change', schedule);
  form.addEventListener('submit', (event) => {
    if (submitting) {
      event.preventDefault();
      return;
    }
    submitting = true;
    clearTimeout(timer);
    ++revision;
    // Keep the named Review button enabled so its action remains in the POST.
    create.disabled = true;
  });
  window.addEventListener('pageshow', () => {
    submitting = false;
    invalidate();
    preview();
  });
  invalidate();
  preview();
});
