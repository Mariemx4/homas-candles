'use strict';

/**
 * FILE PURPOSE: Progressive enhancement for the manual-order form.
 *
 * DATA FLOW: Reads the item <template> and the catalogue JSON rendered by
 * admin-order-create.php. It adds/removes items and recipe lines, keeps the
 * PHP array field names consecutive, and narrows each item's color/scent
 * choices to the selected product. ManualOrderService re-validates everything
 * on the server, because browser-side behavior can be bypassed.
 */
document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('[data-manual-order-form]');
  const list = form?.querySelector('[data-order-items]');
  const template = document.querySelector('#manual-order-item-template');
  if (!form || !list || !(template instanceof HTMLTemplateElement)) return;

  // Must match OrderProductionService::MAX_RECIPE_LINES.
  const maxRecipeLines = 20;
  let catalogue = {};
  try {
    catalogue = JSON.parse(document.querySelector('#manual-order-catalogue')?.textContent || '{}') || {};
  } catch {
    catalogue = {};
  }

  // Array-style PHP field names must remain consecutive after an item or a
  // recipe line is removed, so rebuild the visible numbers and every name.
  const renumber = () => {
    const items = [...list.querySelectorAll('[data-order-item]')];
    items.forEach((item, index) => {
      const number = item.querySelector('[data-item-number]');
      if (number) number.textContent = String(index + 1);
      item.querySelectorAll('[data-item-field]').forEach((field) => {
        field.name = `items[${index}][${field.dataset.itemField}]`;
      });
      const lines = [...item.querySelectorAll('[data-recipe-line]')];
      lines.forEach((line, lineIndex) => {
        line.querySelectorAll('[data-recipe-field]').forEach((field) => {
          field.name = `items[${index}][recipe][${lineIndex}][${field.dataset.recipeField}]`;
        });
        const removeLine = line.querySelector('[data-remove-recipe-line]');
        if (removeLine) removeLine.hidden = lines.length === 1;
      });
      const remove = item.querySelector('[data-remove-item]');
      if (remove) remove.hidden = items.length === 1;
    });
  };

  // Replace one select's options, keeping the current choice when it is still offered.
  const fillOptions = (select, options) => {
    const current = select.value;
    select.replaceChildren(new Option('—', ''));
    options.forEach(([id, name]) => select.append(new Option(name, String(id), false, String(id) === current)));
  };

  // A catalogue item shows color/scent choices; a custom item shows the name field.
  const applyProduct = (item) => {
    const productId = item.querySelector('[data-product-select]')?.value || '';
    const product = productId === '' ? null : catalogue[productId];
    item.querySelectorAll('[data-catalogue-only]').forEach((element) => { element.hidden = product === null; });
    item.querySelectorAll('[data-custom-only]').forEach((element) => { element.hidden = product !== null; });
    item.querySelectorAll('[data-option-select]').forEach((select) => {
      fillOptions(select, product ? (product[select.dataset.optionSelect] || []) : []);
    });
  };

  form.addEventListener('change', (event) => {
    const select = event.target.closest('[data-product-select]');
    if (select) applyProduct(select.closest('[data-order-item]'));
  });

  form.addEventListener('click', (event) => {
    const addButton = event.target.closest('[data-add-item]');
    if (addButton) {
      // This client-side ceiling improves usability; the PHP service enforces
      // the same limit because browser-side validation can be bypassed.
      if (list.querySelectorAll('[data-order-item]').length >= 50) return;
      list.append(template.content.cloneNode(true));
      renumber();
      list.lastElementChild?.querySelector('select, input')?.focus();
      return;
    }
    const removeButton = event.target.closest('[data-remove-item]');
    if (removeButton && list.querySelectorAll('[data-order-item]').length > 1) {
      removeButton.closest('[data-order-item]')?.remove();
      renumber();
      return;
    }
    const addLine = event.target.closest('[data-add-recipe-line]');
    if (addLine) {
      const lines = addLine.closest('[data-recipe]')?.querySelector('[data-recipe-lines]');
      const first = lines?.querySelector('[data-recipe-line]');
      if (!lines || !first || lines.children.length >= maxRecipeLines) return;
      // Clone an existing row so the material list stays identical, then clear it.
      const copy = first.cloneNode(true);
      copy.querySelectorAll('select, input').forEach((field) => { field.value = ''; });
      lines.append(copy);
      renumber();
      copy.querySelector('select')?.focus();
      return;
    }
    const removeLine = event.target.closest('[data-remove-recipe-line]');
    if (removeLine && removeLine.closest('[data-recipe-lines]')?.children.length > 1) {
      removeLine.closest('[data-recipe-line]')?.remove();
      renumber();
    }
  });

  renumber();
});
