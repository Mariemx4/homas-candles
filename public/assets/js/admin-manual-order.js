'use strict';
document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('[data-manual-order-form]');
  const list = form?.querySelector('[data-order-items]');
  const template = document.querySelector('#manual-order-item-template');
  if (!form || !list || !(template instanceof HTMLTemplateElement)) return;
  let catalogue = {};
  try { catalogue = JSON.parse(document.querySelector('#manual-order-catalogue')?.textContent || '{}'); } catch {}
  let requestNumber = 0, timer;
  const renumber = () => {
    const items = [...list.querySelectorAll('[data-order-item]')];
    items.forEach((item, index) => {
      item.querySelector('[data-item-number]').textContent = String(index + 1);
      item.querySelectorAll('[data-item-field]').forEach(field => {
        field.name = `items[${index}][${field.dataset.itemField}]`;
        field.id = `order-item-${index}-${field.dataset.itemField}`;
        const label = field.closest('.admin-field')?.querySelector('label');
        if (label) label.htmlFor = field.id;
      });
      item.querySelector('[data-remove-item]').hidden = items.length === 1;
    });
  };
  const applyProduct = (item) => {
    const product = catalogue[item.querySelector('[data-product-select]').value];
    item.querySelectorAll('[data-option-select]').forEach(select => {
      const current = select.value, options = product?.[select.dataset.optionSelect] || [];
      select.replaceChildren(new Option('Select an option', ''));
      options.forEach(([id, name]) => select.append(new Option(name, String(id), false, String(id) === current)));
      item.querySelector('[data-item-field="quantity"]').min = String(product?.minimum_quantity || 1);
      select.disabled = options.length === 0; select.required = !select.disabled;
      select.closest('[data-option-group]').hidden = select.disabled;
    });
  };
  const applyOverride = item => {
    const active = item.querySelector('[data-price-override]').checked;
    const group = item.querySelector('[data-override-fields]'); group.hidden = !active;
    group.querySelectorAll('input').forEach(input => { input.disabled = !active; input.required = active; });
  };
  const preview = async () => {
    clearTimeout(timer);
    const request = ++requestNumber;
    const error = form.querySelector('[data-price-error]'); error.textContent = 'Checking current prices...';
    try {
      const response = await fetch(form.dataset.pricePreview, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' } });
      if (!response.ok) throw new Error('Pricing is temporarily unavailable. Please try again.');
      const data = await response.json(); if (request !== requestNumber) return;
      if (!data.success) throw new Error(data.error);
      [...list.querySelectorAll('[data-order-item]')].forEach((item, index) => {
        const line = data.lines[index];
        item.querySelector('[data-line-base]').textContent = line.base + ' TND';
        item.querySelector('[data-line-tier]').textContent = line.quantity_price + ' TND';
        item.querySelector('[data-line-tier-row]').hidden = line.base === line.quantity_price;
        item.querySelector('[data-line-total]').textContent = line.line + ' TND';
      });
      Object.entries({ ...data.amounts, savings: data.savings }).forEach(([key, amount]) => {
        const output = form.querySelector('[data-summary-amount="' + key + '"]'); if (!output) return;
        output.textContent = (['promo','manual'].includes(key) ? '-' : '') + amount + ' TND';
        const row = output.closest('[data-summary-row]');
        if (['promo','manual','savings'].includes(key)) row.hidden = amount === '0.000';
      });
      form.querySelector('[data-promo-label]').textContent = data.promo_code || '';
      form.querySelector('[data-promo-description]').textContent = data.promo_description || '';
      error.textContent = '';
    } catch (failure) {
      if (request !== requestNumber) return;
      error.textContent = failure.message;
      list.querySelectorAll('[data-line-base], [data-line-total]').forEach(output => { output.textContent = 'Review prices'; });
      list.querySelectorAll('[data-line-tier-row]').forEach(row => { row.hidden = true; });
      form.querySelectorAll('[data-summary-amount]').forEach(output => { output.textContent = 'Review prices'; });
    }
  };
  const schedule = () => { ++requestNumber; clearTimeout(timer); timer = setTimeout(preview, 250); };
  form.addEventListener('change', event => {
    const item = event.target.closest('[data-order-item]');
    if (event.target.matches('[data-product-select]')) applyProduct(item);
    if (event.target.matches('[data-price-override]')) applyOverride(item);
    if (item || ['promo_code','manual_discount_type','manual_discount_value','delivery_method','delivery_fee'].includes(event.target.name)) schedule();
  });
  form.addEventListener('input', event => {
    if (event.target.matches('[data-item-field="quantity"], [data-item-field="unit_price"]') || ['promo_code','manual_discount_value','delivery_fee'].includes(event.target.name)) schedule();
  });
  form.addEventListener('click', event => {
    if (event.target.closest('[data-add-item]')) {
      if (list.querySelectorAll('[data-order-item]').length >= 50) return;
      list.append(template.content.cloneNode(true)); renumber();
      list.lastElementChild.querySelector('select').focus(); schedule();
    }
    const remove = event.target.closest('[data-remove-item]');
    if (remove && list.querySelectorAll('[data-order-item]').length > 1) { remove.closest('[data-order-item]').remove(); renumber(); schedule(); }
    if (event.target.closest('[data-remove-promo]')) { event.preventDefault(); form.elements.namedItem('promo_code').value = ''; preview(); }
    if (event.target.closest('[data-apply-promo]')) { event.preventDefault(); preview(); }
  });
  form.addEventListener('invalid', event => { const details = event.target.closest('details'); if (details) details.open = true; }, true);
  renumber();
});
