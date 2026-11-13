'use strict';
document.addEventListener('DOMContentLoaded', () => {
  const list = document.querySelector('[data-custom-products]'),
    template = document.querySelector('#custom-product-template'),
    componentTemplate = document.querySelector('#request-component-template');
  if (!list || !template || !componentTemplate) return;
  const catalogue = JSON.parse(document.querySelector('#request-component-catalogue').textContent),
    standard = JSON.parse(document.querySelector('#request-standard-options').textContent);
  const customVisibility = (root) =>
    root.querySelectorAll('[data-request-choice]').forEach((select) => {
      const wrapper =
        select.closest('[data-request-component]') || select.closest('[data-custom-product]');
      const group = wrapper.querySelector(
        '[data-request-custom="' + select.dataset.requestChoice + '"]',
      );
      const active = select.value === '__custom__';
      group.hidden = !active;
      group.querySelector('input').disabled = !active;
      group.querySelector('input').required = active;
    });
  const applyComponent = (row, sourceChanged = false) => {
    const source = row.querySelector('[data-component-source]').value,
      select = row.querySelector('[data-component-record]');
    const existing = source !== 'CUSTOM',
      selected = sourceChanged ? '' : select.value;
    row.querySelector('[data-existing-component]').hidden = !existing;
    select.disabled = !existing;
    select.required = existing;
    row.querySelector('[data-component-label]').textContent =
      source === 'ITEM' ? 'Item' : 'Product';
    const custom = row.querySelector('[data-new-component]');
    custom.hidden = existing;
    custom.querySelector('input').disabled = existing;
    custom.querySelector('input').required = !existing;
    select.replaceChildren(new Option('Search or select...', ''));
    if (existing)
      Object.values(catalogue)
        .filter((p) =>
          source === 'PRODUCT' ? p.can_purchase_alone : p.category_slug !== 'candle-sets',
        )
        .forEach((p) =>
          select.append(
            new Option(
              p.name + ' / ' + p.category_name + ' / ' + p.type_name,
              String(p.id),
              false,
              String(p.id) === selected,
            ),
          ),
        );
    const product = catalogue[select.value],
      changed = sourceChanged || row.dataset.optionProduct !== select.value;
    row.querySelectorAll('[data-request-choice]').forEach((option) => {
      const kind = option.dataset.requestChoice,
        current = changed ? '' : option.value,
        choices = existing
          ? product?.[kind === 'color' ? 'colors' : 'scents'] || {}
          : standard[kind];
      option.replaceChildren(
        new Option(
          existing ? 'No configured ' + kind + ' choices' : 'Select ' + kind + ' (optional)',
          '',
        ),
      );
      Object.entries(choices).forEach(([id, name]) =>
        option.append(new Option(name, id, false, id === current)),
      );
      if (!existing)
        option.append(
          new Option(
            'Other / Custom requested value',
            '__custom__',
            false,
            current === '__custom__',
          ),
        );
      option.disabled = existing && Object.keys(choices).length === 0;
      option.required = existing && !option.disabled;
      if (!option.disabled && existing) option.options[0].textContent = 'Select configured ' + kind;
    });
    row.dataset.optionProduct = select.value;
    customVisibility(row);
  };
  const renumber = () => {
    const rows = [...list.querySelectorAll('[data-custom-product]')];
    form.querySelector('[data-add-custom]').disabled = rows.length >= 50;
    rows.forEach((row, index) => {
      row.querySelector('[data-add-component]').disabled =
        row.querySelectorAll('[data-request-component]').length >= 50;
      row.querySelector('[data-custom-number]').textContent = String(index + 1);
      row.querySelector('[data-remove-custom]').hidden = rows.length === 1;
      const assign = (field, prefix, id) => {
        field.name =
          prefix + '[' + (field.dataset.customField || field.dataset.componentField) + ']';
        field.id = id;
        field.setAttribute('aria-describedby', 'request-products-error');
        field.closest('.admin-field').querySelector('label').htmlFor = id;
      };
      row
        .querySelectorAll('[data-custom-field]')
        .forEach((field) =>
          assign(
            field,
            'custom_products[' + index + ']',
            'custom-' + index + '-' + field.dataset.customField,
          ),
        );
      row.querySelectorAll('[data-request-component]').forEach((child, position) => {
        child.querySelector('[data-component-number]').textContent = String(position + 1);
        child
          .querySelectorAll('[data-component-field]')
          .forEach((field) =>
            assign(
              field,
              'custom_products[' + index + '][components][' + position + ']',
              'component-' + index + '-' + position + '-' + field.dataset.componentField,
            ),
          );
      });
    });
  };
  const form = list.closest('form');
  const productsPayload = () =>
    [...list.querySelectorAll('[data-custom-product]')].map((row) => {
      const product = {};
      row.querySelectorAll('[data-custom-field]').forEach((field) => {
        if (!field.disabled) product[field.dataset.customField] = field.value;
      });
      product.components = [...row.querySelectorAll('[data-request-component]')].map((child) => {
        const component = {};
        child.querySelectorAll('[data-component-field]').forEach((field) => {
          if (!field.disabled) component[field.dataset.componentField] = field.value;
        });
        return component;
      });
      return product;
    });
  form.addEventListener('submit', (event) => {
    const message = document.querySelector('#request-payload-message');
    if (new Blob([JSON.stringify(productsPayload())]).size > 2097152) {
      event.preventDefault();
      message.textContent =
        'This brief exceeds 2 MiB. Shorten the specifications or split it into separate requests. Your entries are still here.';
      message.focus();
    }
  });
  // The browser constructs the actual POST using this event. Nested field names
  // are removed so PHP's max_input_vars cannot silently truncate components.
  form.addEventListener('formdata', (event) => {
    for (const key of [...event.formData.keys()])
      if (key.startsWith('custom_products[')) event.formData.delete(key);
    event.formData.set('custom_products_json', JSON.stringify(productsPayload()));
  });
  form.addEventListener('change', (event) => {
    const row = event.target.closest('[data-request-component]');
    if (
      row &&
      (event.target.matches('[data-component-source]') ||
        event.target.matches('[data-component-record]'))
    )
      applyComponent(row, event.target.matches('[data-component-source]'));
    if (event.target.matches('[data-request-choice]'))
      customVisibility(row || event.target.closest('[data-custom-product]'));
  });
  form.addEventListener('click', (event) => {
    if (event.target.closest('[data-add-custom]') && list.children.length < 50) {
      list.append(template.content.cloneNode(true));
      renumber();
      customVisibility(list.lastElementChild);
      list.lastElementChild.querySelector('input').focus();
    }
    const add = event.target.closest('[data-add-component]');
    if (add) {
      const children = add
        .closest('[data-custom-product]')
        .querySelector('[data-request-components]');
      if (children.children.length < 50) {
        children.append(componentTemplate.content.cloneNode(true));
        renumber();
        applyComponent(children.lastElementChild, true);
      }
    }
    const remove = event.target.closest('[data-remove-component]');
    if (remove) {
      remove.closest('[data-request-component]').remove();
      renumber();
    }
    const removeProduct = event.target.closest('[data-remove-custom]');
    if (removeProduct && list.children.length > 1) {
      removeProduct.closest('[data-custom-product]').remove();
      renumber();
    }
  });
  renumber();
  list.querySelectorAll('[data-custom-product]').forEach((row) => {
    row.querySelectorAll('[data-request-component]').forEach((child) => {
      child.dataset.optionProduct = child.querySelector('[data-component-record]').value;
      applyComponent(child);
    });
    customVisibility(row);
  });
});
