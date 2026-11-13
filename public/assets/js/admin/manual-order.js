'use strict';
document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('[data-manual-order-form]');
  const list = form?.querySelector('[data-order-items]');
  const template = document.querySelector('#manual-order-item-template');
  if (!form || !list || !(template instanceof HTMLTemplateElement)) return;
  let catalogue = {};
  try {
    catalogue = JSON.parse(document.querySelector('#manual-order-catalogue')?.textContent || '{}');
  } catch {}
  let requestNumber = 0,
    timer;
  const renumber = () => {
    const items = [...list.querySelectorAll('[data-order-item]')];
    items.forEach((item, index) => {
      item.querySelector('[data-item-number]').textContent = String(index + 1);
      item.querySelectorAll('[data-item-field]').forEach((field) => {
        field.name = `items[${index}][${field.dataset.itemField}]`;
        field.id = `order-item-${index}-${field.dataset.itemField}`;
        const label = field.closest('.admin-field')?.querySelector('label');
        if (label) label.htmlFor = field.id;
      });
      item.querySelectorAll('[data-product-guide]').forEach((field, position) => {
        field.id = `order-guide-${index}-${position}`;
        field.closest('.admin-field').querySelector('label').htmlFor = field.id;
      });
      const error = item.querySelector('[id$="-error"]');
      if (error) error.id = `order-item-${index}-error`;
      item
        .querySelectorAll('[data-searchable]')
        .forEach((field) => field.setAttribute('aria-describedby', `order-item-${index}-error`));
      item.querySelector('[data-remove-item]').hidden = items.length === 1;
    });
  };
  const applyProduct = (item) => {
    const product = catalogue[item.querySelector('[data-product-select]').value];
    item.querySelectorAll('[data-option-select]').forEach((select) => {
      const current =
          item.dataset.optionProduct === item.querySelector('[data-product-select]').value
            ? select.value
            : '',
        options = product?.[select.dataset.optionSelect] || [];
      select.replaceChildren(new Option('Select an option', ''));
      options.forEach(([id, name]) =>
        select.append(new Option(name, String(id), false, String(id) === current)),
      );
      item.querySelector('[data-item-field="quantity"]').min = String(
        product?.minimum_quantity || 1,
      );
      select.disabled = options.length === 0;
      select.required = !select.disabled;
      select.closest('[data-option-group]').hidden = false;
      if (!options.length)
        select.options[0].textContent =
          'No configured ' + select.dataset.optionSelect + ' for this product.';
      select.value = options.some(([id]) => String(id) === current) ? current : '';
      select.dispatchEvent(new Event('select-sync'));
    });
    item.dataset.optionProduct = item.querySelector('[data-product-select]').value;
    const base = item.querySelector('[data-line-base]');
    if (base) {
      base.textContent = product
        ? product.standard_price
          ? product.standard_price + ' TND'
          : 'Pricing is not configured for this product.'
        : 'Select a product';
      item.querySelector('[data-line-total]').textContent = product
        ? 'Checking current prices...'
        : 'Select a product';
      item.querySelector('[data-line-tier-row]').hidden = true;
      item.querySelector('[data-line-bulk-row]').hidden = true;
    }
  };
  const populateGuide = (select, key, categoryId, selected = select.value) => {
    const options = new Map();
    Object.values(catalogue).forEach((p) => {
      if (key === 'type' && categoryId && p.category_id !== categoryId) return;
      if (p[key + '_id']) options.set(p[key + '_id'], p[key + '_name']);
    });
    select.replaceChildren(new Option(key === 'category' ? 'All categories' : 'All types', ''));
    options.forEach((name, id) => select.append(new Option(name, id)));
    select.value = options.has(selected) ? selected : '';
    select.dispatchEvent(new Event('select-sync'));
  };
  const guides = (item) => {
    const category = item.querySelector('[data-product-guide="category"]');
    const type = item.querySelector('[data-product-guide="type"]');
    const productSelect = item.querySelector('[data-product-select]');
    const selected = productSelect.value;
    populateGuide(category, 'category', '');
    populateGuide(type, 'type', category.value);
    productSelect.replaceChildren(new Option('Select a catalogue product', ''));
    Object.entries(catalogue).forEach(([id, p]) => {
      const context = p.name + ' / ' + p.category_name + ' / ' + (p.type_name || '');
      const match =
        (!category.value || p.category_id === category.value) &&
        (!type.value || p.type_id === type.value);
      if (match) {
        const option = new Option(context, id);
        option.dataset.label = p.name;
        option.dataset.context = [p.category_name, p.type_name].filter(Boolean).join(' / ');
        productSelect.append(option);
      }
    });
    productSelect.value = [...productSelect.options].some((option) => option.value === selected)
      ? selected
      : '';
    productSelect.dispatchEvent(new Event('select-sync'));
    item.querySelector('[data-product-results]').textContent =
      String(productSelect.options.length - 1) + ' matching products';
    applyProduct(item);
  };
  const selectContext = (item) => {
    const p = catalogue[item.querySelector('[data-product-select]').value];
    if (p) {
      populateGuide(
        item.querySelector('[data-product-guide="category"]'),
        'category',
        '',
        p.category_id,
      );
      guidesForType(item, p);
    }
    applyProduct(item);
  };
  const guidesForType = (item, p) => {
    const type = item.querySelector('[data-product-guide="type"]');
    populateGuide(type, 'type', p.category_id, p.type_id);
    item
      .querySelectorAll('[data-product-guide]')
      .forEach((select) => select.dispatchEvent(new Event('select-sync')));
  };
  const preview = async () => {
    clearTimeout(timer);
    const request = ++requestNumber;
    const error = form.querySelector('[data-price-error]');
    error.textContent = 'Checking current prices...';
    try {
      const response = await fetch(form.dataset.pricePreview, {
        method: 'POST',
        body: new FormData(form),
        headers: { Accept: 'application/json' },
      });
      if (!response.ok) throw new Error('Pricing is temporarily unavailable. Please try again.');
      const data = await response.json();
      if (request !== requestNumber) return;
      if (!data.success) throw new Error(data.error);
      [...list.querySelectorAll('[data-order-item]')].forEach((item, index) => {
        const line = data.lines[index];
        item.querySelector('[data-line-base]').textContent = line.base + ' TND';
        item.querySelector('[data-line-tier]').textContent = line.quantity_price + ' TND';
        item.querySelector('[data-line-tier-row]').hidden = line.base === line.quantity_price;
        item.querySelector('[data-line-bulk-row]').hidden = !line.bulk_from;
        item.querySelector('[data-line-bulk]').textContent = line.bulk_from
          ? 'From ' + line.bulk_from + ' TND/unit'
          : '';
        item.querySelector('[data-line-total]').textContent = line.line + ' TND';
      });
      Object.entries({ ...data.amounts, savings: data.savings }).forEach(([key, amount]) => {
        const output = form.querySelector('[data-summary-amount="' + key + '"]');
        if (!output) return;
        output.textContent = (['promo', 'manual'].includes(key) ? '-' : '') + amount + ' TND';
        const row = output.closest('[data-summary-row]');
        if (['promo', 'manual', 'savings'].includes(key)) row.hidden = amount === '0.000';
      });
      form.querySelectorAll('[data-admin-estimate]').forEach((output) => {
        output.textContent = 'Select product options to estimate cost';
      });
      Object.entries(data.admin_estimate || {}).forEach(([key, value]) => {
        form.querySelector('[data-admin-estimate="' + key + '"]').textContent =
          value + (key === 'margin' ? '' : ' TND');
      });
      form.querySelector('[data-promo-label]').textContent = data.promo_code || '';
      form.querySelector('[data-promo-description]').textContent = data.promo_description || '';
      error.textContent = data.notice || '';
    } catch (failure) {
      if (request !== requestNumber) return;
      error.textContent = failure.message;
      list.querySelectorAll('[data-order-item]').forEach((item) => {
        const product = catalogue[item.querySelector('[data-product-select]').value];
        item.querySelector('[data-line-base]').textContent = product?.standard_price
          ? product.standard_price + ' TND'
          : product
            ? 'Pricing is not configured for this product.'
            : 'Select a product';
        item.querySelector('[data-line-total]').textContent = failure.message;
      });
      form.querySelectorAll('[data-admin-estimate]').forEach((output) => {
        output.textContent = 'Review prices';
      });
      list.querySelectorAll('[data-line-bulk-row], [data-line-tier-row]').forEach((row) => {
        row.hidden = true;
      });
      form.querySelectorAll('[data-summary-amount]').forEach((output) => {
        output.textContent = 'Review prices';
      });
    }
  };
  const schedule = () => {
    ++requestNumber;
    clearTimeout(timer);
    timer = setTimeout(preview, 250);
  };
  form.addEventListener('change', (event) => {
    const item = event.target.closest('[data-order-item]');
    if (event.target.matches('[data-product-select]')) selectContext(item);
    if (event.target.matches('[data-product-guide]')) guides(item);
    if (
      item ||
      [
        'promo_code',
        'manual_discount_type',
        'manual_discount_value',
        'delivery_method',
        'delivery_fee',
      ].includes(event.target.name)
    )
      schedule();
  });
  form.addEventListener('input', (event) => {
    if (
      event.target.matches('[data-item-field="quantity"]') ||
      ['promo_code', 'manual_discount_value', 'delivery_fee'].includes(event.target.name)
    )
      schedule();
  });
  form.addEventListener('click', (event) => {
    if (event.target.closest('[data-add-item]')) {
      if (list.querySelectorAll('[data-order-item]').length >= 50) return;
      list.append(template.content.cloneNode(true));
      renumber();
      guides(list.lastElementChild);
      list.lastElementChild.querySelector('[data-item-field="quantity"]').focus();
      schedule();
    }
    const remove = event.target.closest('[data-remove-item]');
    if (remove && list.querySelectorAll('[data-order-item]').length > 1) {
      remove.closest('[data-order-item]').remove();
      renumber();
      schedule();
    }
    if (event.target.closest('[data-remove-promo]')) {
      event.preventDefault();
      form.elements.namedItem('promo_code').value = '';
      preview();
    }
    if (event.target.closest('[data-apply-promo]')) {
      event.preventDefault();
      preview();
    }
  });
  form.addEventListener(
    'invalid',
    (event) => {
      const details = event.target.closest('details');
      if (details) details.open = true;
    },
    true,
  );
  renumber();
  list.querySelectorAll('[data-order-item]').forEach((item) => {
    item.dataset.optionProduct = item.querySelector('[data-product-select]').value;
    guides(item);
    selectContext(item);
  });
  if (
    [...list.querySelectorAll('[data-order-item]')].some(
      (item) => item.querySelector('[data-product-select]').value,
    )
  )
    schedule();
});
