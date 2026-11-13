'use strict';
/**
 * Adapt simple record tables to labeled phone cards without duplicating forms,
 * record values or actions. CSS chooses the layout; desktop retains its table.
 * Multi-level/matrix tables stay native. Without JS, native scrolling remains.
 */
(() => {
  const main = document.querySelector('.admin-main');
  if (!main) return;

  for (const table of main.querySelectorAll('table.admin-table')) {
    if (table.tHead?.rows.length !== 1 || table.hasAttribute('data-keep-table')) continue;
    const headers = [...table.tHead.rows[0].cells];
    if (
      headers.length < 2 ||
      headers.some((header) => header.colSpan !== 1 || header.rowSpan !== 1)
    )
      continue;

    // Explicit roles preserve table associations in browsers that otherwise
    // discard native semantics when CSS changes a table's display mode.
    table.classList.add('admin-table--cards');
    table.setAttribute('role', 'table');
    table.tHead.setAttribute('role', 'rowgroup');
    table.tHead.rows[0].setAttribute('role', 'row');
    headers.forEach((header) => header.setAttribute('role', 'columnheader'));

    for (const body of table.tBodies) {
      body.setAttribute('role', 'rowgroup');
      for (const row of body.rows) {
        row.setAttribute('role', 'row');
        for (const [index, cell] of [...row.cells].entries()) {
          cell.setAttribute('role', cell.tagName === 'TH' ? 'rowheader' : 'cell');
          if (cell.colSpan > 1 || row.cells.length !== headers.length) {
            cell.dataset.cardWide = '';
            continue;
          }
          const label = headers[index].textContent.trim();
          const fieldLabel = document.createElement('span');
          fieldLabel.className = 'admin-card-label';
          fieldLabel.setAttribute('aria-hidden', 'true');
          fieldLabel.textContent = label;
          cell.prepend(fieldLabel);

          // Form controls and long operational notes need the full card width.
          // Never infer a business action from text or create another control.
          if (
            index === 0 ||
            cell.querySelector(
              'form, details, .admin-record-actions, .admin-order-row-actions, .admin-readiness-card',
            ) ||
            /actions?|notes?|address|readiness/i.test(label) ||
            cell.textContent.length > 90
          ) {
            cell.dataset.cardWide = '';
          }
        }
      }
    }
    // In card mode this container is no longer a sideways-scrolling region.
    const region = table.closest('.admin-table-scroll');
    if (region) region.dataset.cardRegion = '';
  }

  const phone = window.matchMedia('(max-width: 48rem)');
  const regions = [...main.querySelectorAll('[data-card-region]')].map((region) => ({
    region,
    tabindex: region.getAttribute('tabindex'),
    label: region.getAttribute('aria-label'),
  }));
  function updateRegions() {
    for (const { region, tabindex, label } of regions) {
      if (phone.matches) {
        region.removeAttribute('tabindex');
        region.removeAttribute('aria-label');
      } else {
        if (tabindex !== null) region.setAttribute('tabindex', tabindex);
        if (label !== null) region.setAttribute('aria-label', label);
      }
    }
  }
  phone.addEventListener('change', updateRegions);
  updateRegions();
})();
