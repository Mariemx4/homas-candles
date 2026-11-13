'use strict';
/**
 * Progressive admin workspace enhancements. PHP forms and links remain the
 * source of truth; this file groups existing controls without replacing their
 * validation, endpoints, CSRF fields or authoritative pricing.
 *
 * Initialization order: request sections -> record tabs -> declared form steps
 * -> filters -> recovery -> product/address/stock helpers. Session storage holds
 * panel identifiers only. Never add account details, form drafts or prices to it.
 * See docs/admin-dashboard/ui-maintenance.md for the markup contracts.
 */
(() => {
  const main = document.querySelector('.admin-main');
  if (!main) return;

  // Clone the server-owned icon registry instead of duplicating SVG paths or
  // fetching a CDN. Text remains the accessible name of enhanced controls.
  const iconLibrary = document.getElementById('admin-icon-library')?.content;
  const sectionIcons = {
    Overview: 'eye',
    Products: 'products',
    Basics: 'edit',
    Photos: 'photo',
    Options: 'settings',
    Pricing: 'payments',
    Languages: 'language',
    Publish: 'check',
    Production: 'production',
    Payments: 'payments',
    History: 'history',
    Brief: 'products',
    Conversation: 'requests',
  };
  function setControlLabel(control, label, iconName) {
    const icon = iconLibrary?.querySelector('[data-admin-icon="' + iconName + '"] svg');
    control.replaceChildren();
    if (icon) control.append(icon.cloneNode(true));
    control.append(document.createTextNode(label));
  }

  function currentHash() {
    try {
      return decodeURIComponent(location.hash.slice(1));
    } catch {
      return '';
    } // A malformed copied URL must not disable the workspace.
  }
  const pageKey =
    location.pathname +
    ':' +
    (/^(?:\d+|new)$/.test(new URLSearchParams(location.search).get('id') || '')
      ? new URLSearchParams(location.search).get('id')
      : '');
  const storage = {
    get: (key) => {
      try {
        return sessionStorage.getItem('homas:workspace:' + pageKey + ':' + key);
      } catch {
        return null;
      }
    },
    set: (key, value) => {
      try {
        sessionStorage.setItem('homas:workspace:' + pageKey + ':' + key, value);
      } catch {}
    },
  };
  // Only panel identifiers are remembered. Customer details, prices, notes and
  // evidence are never written into browser draft storage.
  // Views declare section ownership. Labels and translations never determine
  // where a form appears; all sections stay readable when JS is unavailable.
  /** The view declares ownership; changing headings must never move controls. */
  function groupRequestSections() {
    main.querySelectorAll('[data-request-record]').forEach((record) => {
      const workspace = document.createElement('div');
      workspace.dataset.workspace = 'Request sections';
      const panels = new Map();
      for (const [label, id] of [
        ['Overview', 'request-overview'],
        ['Brief', 'request-brief'],
        ['Conversation', 'request-conversation'],
        ['History', 'request-history'],
      ]) {
        const panel = document.createElement('div');
        panel.dataset.workspacePanel = label;
        panel.id = id;
        panels.set(label, panel);
      }
      record.querySelectorAll('[data-request-section]').forEach((section) => {
        const panel = panels.get(section.dataset.requestSection);
        if (!panel) return;
        if (section.querySelector('[name="action"][value="transition"]')) panel.prepend(section);
        else panel.append(section);
      });
      record.replaceChildren(workspace);
      panels.forEach((panel) => {
        if (panel.children.length) workspace.append(panel);
      });
    });
  }
  groupRequestSections();
  // Record tabs: shared keyboard behavior, linked selection and error recovery.
  /** One tab implementation serves products, orders and requests. */
  function initializeRecordTabs() {
    main.querySelectorAll('[data-workspace]').forEach((workspace) => {
      if (workspace.dataset.workspaceOrder) {
        const groups = new Map();
        const order = workspace.dataset.workspaceOrder.split(',');
        [...workspace.querySelectorAll(':scope > [data-workspace-panel]')].forEach((section) => {
          const label = section.dataset.workspacePanel;
          let group = groups.get(label);
          if (!group) {
            group = document.createElement('div');
            group.dataset.workspacePanel = label;
            group.id = section.id || 'product-' + label.toLowerCase();
            section.removeAttribute('id');
            section.before(group);
            groups.set(label, group);
          }
          group.append(section);
        });
        order.forEach((label) => {
          if (groups.has(label)) workspace.append(groups.get(label));
        });
      }
      const panels = [...workspace.querySelectorAll(':scope > [data-workspace-panel]')];
      if (panels.length < 2) return;
      const tabs = document.createElement('div');
      tabs.className = 'admin-workspace-tabs';
      tabs.setAttribute('role', 'tablist');
      tabs.setAttribute('aria-label', workspace.dataset.workspace || 'Record sections');
      const buttons = panels.map((panel, index) => {
        panel.id ||= 'workspace-panel-' + index;
        const button = document.createElement('button');
        button.type = 'button';
        button.id = panel.id + '-tab';
        setControlLabel(
          button,
          panel.dataset.workspacePanel,
          sectionIcons[panel.dataset.workspacePanel] || 'eye',
        );
        button.setAttribute('role', 'tab');
        button.setAttribute('aria-controls', panel.id);
        panel.setAttribute('role', 'tabpanel');
        panel.setAttribute('aria-labelledby', button.id);
        panel.tabIndex = 0;
        tabs.append(button);
        return button;
      });
      const activate = (index, focus = false, updateUrl = false) => {
        panels.forEach((panel, i) => {
          panel.hidden = i !== index;
          buttons[i].setAttribute('aria-selected', String(i === index));
          buttons[i].tabIndex = i === index ? 0 : -1;
        });
        storage.set(workspace.dataset.workspace || 'panels', panels[index].id);
        if (focus) buttons[index].focus();
        if (updateUrl) history.replaceState(null, '', '#' + panels[index].id);
      };
      const hash = currentHash();
      let selected = panels.findIndex(
        (panel) =>
          panel.id === hash ||
          (hash && [...panel.querySelectorAll('[id]')].some((node) => node.id === hash)),
      );
      if (selected < 0)
        selected = panels.findIndex((panel) =>
          panel.querySelector('.admin-field-error:not(:empty), .admin-alert--error'),
        );
      if (selected < 0)
        selected = panels.findIndex(
          (panel) => panel.id === storage.get(workspace.dataset.workspace || 'panels'),
        );
      activate(Math.max(0, selected));
      workspace.prepend(tabs);
      window.addEventListener('hashchange', () => {
        const target = document.getElementById(currentHash()),
          index = panels.findIndex((panel) => panel === target || panel.contains(target));
        if (index >= 0) activate(index);
      });
      buttons.forEach((button, index) => {
        button.addEventListener('click', () => activate(index, false, true));
        button.addEventListener('keydown', (event) => {
          const next =
            event.key === 'ArrowRight'
              ? (index + 1) % buttons.length
              : event.key === 'ArrowLeft'
                ? (index - 1 + buttons.length) % buttons.length
                : event.key === 'Home'
                  ? 0
                  : event.key === 'End'
                    ? buttons.length - 1
                    : null;
          if (next !== null) {
            event.preventDefault();
            activate(next, true, true);
          }
        });
      });
      workspace.addEventListener(
        'invalid',
        (event) => {
          const index = panels.findIndex((panel) => panel.contains(event.target));
          if (index >= 0) activate(index);
        },
        true,
      );
      workspace.addEventListener('click', (event) => {
        const link = event.target.closest('a[href^="#"]');
        if (!link) return;
        const target = document.getElementById(link.hash.slice(1)),
          index = panels.findIndex((panel) => panel === target || panel.contains(target));
        if (index >= 0) {
          activate(index);
          target?.scrollIntoView({ block: 'start' });
        }
      });
    });
  }
  initializeRecordTabs();
  // Journey ownership comes from data-journey-step, never fieldset order/copy.
  /** Arrange existing controls without changing their original form submission. */
  function groupFormJourneys() {
    main.querySelectorAll('[data-form-journey]').forEach((form) => {
      const content = [...form.children].filter(
        (node) =>
          !node.matches(
            'input[type=hidden],script,template,button[name="action"][value="save_draft"]',
          ),
      );
      const groups =
        form.dataset.formJourney === 'order'
          ? ['Customer', 'Products', 'Pickup & delivery', 'Review & save']
          : form.dataset.formJourney === 'request'
            ? ['Customer', 'Requested products', 'Preferences & save']
            : ['Agreed products', 'Pickup & delivery', 'Review & create'];
      const steps = groups.map((label) => {
        const step = document.createElement('div');
        step.dataset.formStep = label;
        return step;
      });
      let index = 0;
      content.forEach((node) => {
        // Unmarked supporting content follows its preceding declared step.
        const declared = Number(node.dataset.journeyStep);
        if (Number.isInteger(declared) && declared >= 0 && declared < steps.length)
          index = declared;
        steps[index].append(node);
      });
      if (form.dataset.formJourney === 'order') {
        const review = steps[3],
          pricing = review.querySelector('.admin-order-price-summary');
        const discount = review.querySelector('[data-order-discounts]');
        if (discount) {
          const details = document.createElement('details'),
            summary = document.createElement('summary');
          details.className = 'admin-optional-discounts';
          summary.textContent = 'Add a discount or promotion';
          discount.before(details);
          details.append(summary, discount);
          if (discount.querySelector('.admin-field-error:not(:empty)')) details.open = true;
        }
        if (pricing) review.prepend(pricing);
        const recap = document.createElement('section');
        recap.className = 'admin-review-summary';
        recap.setAttribute('aria-label', 'Order review');
        review.prepend(recap);
        const render = () => {
          const value = (name) => form.elements.namedItem(name)?.value || '',
            mode = form.querySelector('[name=customer_mode]:checked')?.value || 'guest';
          const heading = document.createElement('h2');
          heading.textContent = 'Check your order';
          const customer = document.createElement('p');
          customer.textContent =
            'Customer: ' +
            (value(mode + '_customer_name') || value('customer_name') || 'Choose a customer') +
            (value(mode + '_customer_phone') ? ' · ' + value(mode + '_customer_phone') : '');
          const fulfilment = document.createElement('p');
          fulfilment.textContent =
            (value('delivery_method') === 'DELIVERY' ? 'Delivery' : 'Pickup') +
            ' · Required ' +
            (value('required_date') || 'Choose a date');
          const items = document.createElement('ul');
          form.querySelectorAll('[data-order-item]').forEach((row) => {
            const product = row.querySelector('[data-product-select]'),
              quantity = row.querySelector('[data-item-field=quantity]');
            const item = document.createElement('li');
            item.textContent =
              (quantity?.value || '1') +
              ' × ' +
              (product?.selectedOptions[0]?.textContent.trim() || 'Choose a product');
            const options = [...row.querySelectorAll('[data-option-select]')]
              .filter((select) => !select.disabled && select.value)
              .map((select) => select.selectedOptions[0].textContent.trim());
            if (options.length) item.append(document.createTextNode(' · ' + options.join(' / ')));
            items.append(item);
          });
          recap.replaceChildren(heading, customer, fulfilment, items);
        };
        form.addEventListener('input', render);
        form.addEventListener('change', render);
        form.addEventListener('click', () => queueMicrotask(render));
        render();
      }
      form.addEventListener(
        'invalid',
        (event) => {
          for (
            let node = event.target.parentElement;
            node && node !== form;
            node = node.parentElement
          )
            if (node.tagName === 'DETAILS') node.open = true;
        },
        true,
      );
      steps.forEach((step) => form.append(step));
      form.dataset.guidedForm = '';
    });
  }
  groupFormJourneys();
  // A progressive form guide: all steps remain visible without JavaScript.
  /** Share step validation, focus and draft-action placement across forms. */
  function initializeGuidedForms() {
    main.querySelectorAll('[data-guided-form]').forEach((form) => {
      const steps = [...form.querySelectorAll(':scope > [data-form-step]')];
      if (steps.length < 2) return;
      const nav = document.createElement('nav');
      nav.className = 'admin-stepper';
      nav.setAttribute('aria-label', 'Form steps');
      const buttons = steps.map((step, index) => {
        const b = document.createElement('button');
        b.type = 'button';
        const number = document.createElement('span');
        number.textContent = index + 1;
        b.append(number, document.createTextNode(step.dataset.formStep));
        nav.append(b);
        return b;
      });
      const actions = document.createElement('div');
      actions.className = 'admin-step-actions';
      const back = document.createElement('button'),
        next = document.createElement('button'),
        count = document.createElement('span');
      back.type = next.type = 'button';
      back.className = 'admin-button admin-button--secondary admin-step-back';
      next.className = 'admin-button admin-step-next';
      setControlLabel(back, 'Back', 'back');
      setControlLabel(next, 'Continue', 'arrow');
      count.className = 'admin-step-count';
      count.setAttribute('aria-live', 'polite');
      actions.append(back, count);
      const saveDraft = form.querySelector('button[name="action"][value="save_draft"]');
      if (saveDraft) actions.append(saveDraft);
      actions.append(next);
      let current = 0;
      const show = (index) => {
        current = index;
        steps.forEach((step, i) => {
          step.hidden = i !== index;
          buttons[i].setAttribute('aria-current', i === index ? 'step' : 'false');
        });
        back.hidden = index === 0;
        next.hidden = index === steps.length - 1;
        count.textContent = 'Step ' + (index + 1) + ' of ' + steps.length;
        storage.set('form-step', String(index));
      };
      buttons.forEach((button, index) => button.addEventListener('click', () => show(index)));
      const focusStep = () => {
        steps[current].tabIndex = -1;
        steps[current].focus({ preventScroll: true });
        nav.scrollIntoView({ block: 'nearest' });
      };
      back.addEventListener('click', () => {
        show(current - 1);
        focusStep();
      });
      next.addEventListener('click', () => {
        const invalid = [...steps[current].querySelectorAll('input,select,textarea')].find(
          (field) => !field.disabled && !field.checkValidity(),
        );
        if (invalid) {
          invalid.reportValidity();
          return;
        }
        show(current + 1);
        focusStep();
      });
      form.prepend(nav);
      form.append(actions);
      form.addEventListener(
        'invalid',
        (event) => {
          const index = steps.findIndex((step) => step.contains(event.target));
          if (index >= 0) show(index);
        },
        true,
      );
      let initial = steps.findIndex((step) =>
        [...step.querySelectorAll('.admin-field-error')].some((error) => error.textContent.trim()),
      );
      if (initial < 0) initial = 0;
      show(Math.max(0, Math.min(steps.length - 1, initial)));
    });
  }
  initializeGuidedForms();
  // Quick search retains its original GET form even outside collapsed filters.
  /** Keep quick search associated with the original GET form. */
  function initializeFilters() {
    main.querySelectorAll('.admin-filter-panel').forEach((panel, index) => {
      const summary = document.createElement('div'),
        button = document.createElement('button'),
        hint = document.createElement('span');
      summary.className = 'admin-filter-summary';
      button.type = 'button';
      const fields = [...panel.querySelectorAll('input[name],select[name]')];
      const changed = fields.filter(
        (field) =>
          field.name !== 'page' &&
          field.value &&
          field.value !== field.defaultValue &&
          (field.tagName !== 'SELECT' ||
            !['priority', 'asc', 'required_date'].includes(field.value)),
      ).length;
      const active = new URLSearchParams(location.search);
      const hasFilters = fields.some(
        (field) => active.has(field.name) && active.get(field.name) !== '',
      );
      const title = panel.querySelector('h2')?.textContent || 'Filters';
      setControlLabel(button, 'More filters', 'filter');
      hint.textContent = hasFilters ? 'Filters applied' : 'Dates, status & more';
      const search = panel.querySelector('input[type="search"]'),
        form = search?.form;
      if (search && form) {
        form.id ||= 'workspace-filters-' + index;
        const quick = document.createElement('div');
        quick.className = 'admin-quick-search';
        const group = search.closest('.admin-field') || search;
        search.setAttribute('form', form.id);
        const submit = document.createElement('button');
        submit.type = 'submit';
        submit.className = 'admin-button admin-button--secondary';
        setControlLabel(submit, 'Search', 'search');
        submit.setAttribute('form', form.id);
        quick.append(group, submit);
        summary.append(quick);
      }
      if (hasFilters) {
        const clear =
          panel.querySelector('.admin-filter-panel__heading a') || document.createElement('a');
        clear.className = 'admin-button admin-button--ghost';
        clear.href = location.pathname;
        clear.textContent = 'Clear filters';
        summary.append(clear);
      }
      panel.id ||= 'workspace-filter-panel-' + index;
      button.setAttribute('aria-controls', panel.id);
      const set = (open) => {
        panel.dataset.collapsed = String(!open);
        button.setAttribute('aria-expanded', String(open));
        setControlLabel(
          button,
          search ? (open ? 'Hide filters' : 'More filters') : title,
          'filter',
        );
      };
      button.addEventListener('click', () => set(panel.dataset.collapsed === 'true'));
      summary.append(button, hint);
      panel.prepend(summary);
      set(hasFilters || changed > 0);
    });
  }
  initializeFilters();
  // Unsubmitted edits warn before navigation; an accepted form submission does
  // not. Prevented/failed client submissions keep the protection enabled.
  /** A prevented submission must not disable the warning about unsaved work. */
  function protectUnsavedWork() {
    const tracked = new Map();
    let submitting = null;
    main.querySelectorAll('form[method="post" i]').forEach((form) => {
      if (form.matches('[data-order-status-form]')) return;
      let dirty = false;
      tracked.set(form, () => dirty);
      form.addEventListener('input', (event) => {
        if (event.target.type === 'hidden') return;
        dirty = true;
        form.dataset.unsaved = 'true';
      });
      form.addEventListener('change', (event) => {
        if (event.target.type === 'hidden') return;
        dirty = true;
        form.dataset.unsaved = 'true';
      });
      form.addEventListener('submit', (event) => {
        if (!event.defaultPrevented) submitting = form;
        queueMicrotask(() => {
          if (event.defaultPrevented) submitting = null;
        });
      });
    });
    window.addEventListener('beforeunload', (event) => {
      if ([...tracked].some(([form, isDirty]) => form !== submitting && isDirty())) {
        event.preventDefault();
        event.returnValue = '';
      }
    });
    window.addEventListener('pageshow', () => {
      submitting = null;
    });
  }
  protectUnsavedWork();
  /** Connect server errors to native fields and their enhanced select proxy. */
  function showFieldErrors() {
    const fieldErrors = [...main.querySelectorAll('.admin-field-error')].filter((error) =>
      error.textContent.trim(),
    );
    if (fieldErrors.length) {
      const summary = document.createElement('div');
      summary.className = 'admin-error-summary';
      summary.tabIndex = -1;
      summary.setAttribute('role', 'alert');
      const title = document.createElement('p');
      title.textContent = 'A few details need your attention';
      const list = document.createElement('ul');
      fieldErrors.forEach((error, index) => {
        let field = error
          .closest('.admin-field')
          ?.querySelector('input:not([type=hidden]),select,textarea');
        if (!field) {
          for (
            let sibling = error.previousElementSibling;
            sibling;
            sibling = sibling.previousElementSibling
          ) {
            if (sibling.matches('input:not([type=hidden]),select,textarea')) {
              field = sibling;
              break;
            }
            if (sibling.tagName === 'LABEL') break;
          }
        }
        if (!field) return;
        error.id ||= 'workspace-error-' + index;
        field.id ||= 'workspace-field-' + index;
        field.setAttribute('aria-invalid', 'true');
        field.setAttribute(
          'aria-describedby',
          [field.getAttribute('aria-describedby'), error.id].filter(Boolean).join(' '),
        );
        const item = document.createElement('li'),
          link = document.createElement('a');
        link.href = '#' + field.id;
        link.textContent = error.textContent;
        link.addEventListener('click', (event) => {
          event.preventDefault();
          field.dispatchEvent(new Event('invalid', { bubbles: false }));
          (field.parentElement.querySelector('.custom-select__trigger') || field).focus();
        });
        item.append(link);
        list.append(item);
      });
      if (list.children.length) {
        summary.append(title, list);
        main.querySelector('header')?.after(summary);
        summary.focus();
      }
    }
  }
  showFieldErrors();
  /** Close the shared New menu on outside clicks and Escape. */
  function initializeCreateMenus() {
    document.querySelectorAll('.admin-create-menu').forEach((menu) => {
      document.addEventListener('click', (event) => {
        if (!menu.contains(event.target)) menu.open = false;
      });
      menu.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
          menu.open = false;
          menu.querySelector('summary').focus();
        }
      });
    });
  }
  initializeCreateMenus();
  /** Category-specific visibility is presentation; server validation stays authoritative. */
  function initializeProductDetails() {
    main.querySelectorAll('[data-product-core]').forEach((form) => {
      const category = form.querySelector('[data-product-category]');
      if (!category) return;
      if (!form.querySelector('[name=action][value=create_draft]')) {
        for (const [label, selectors] of [
          [
            'Selling rules',
            [
              '#product-minimum',
              '#product-purchase-mode',
              '[name=is_made_to_order]',
              '[name=can_purchase_alone]',
            ],
          ],
          ['Website address', ['#product-slug']],
        ]) {
          const fields = [
            ...new Set(
              selectors
                .map((selector) => form.querySelector(selector)?.closest('.admin-field'))
                .filter(Boolean),
            ),
          ];
          if (!fields.length) continue;
          const details = document.createElement('details'),
            summary = document.createElement('summary'),
            grid = document.createElement('div');
          details.className = 'admin-optional-settings';
          summary.textContent = label;
          grid.className = 'admin-form-grid';
          details.append(summary, grid);
          fields.forEach((field) => grid.append(field));
          if (details.querySelector('.admin-field-error:not(:empty)')) details.open = true;
          form.querySelector(':scope > button[type=submit]')?.before(details);
        }
        const price = form.querySelector('.admin-price-field'),
          pricing = document.getElementById('product-pricing');
        if (price && pricing) {
          const panel = document.createElement('section'),
            heading = document.createElement('h2');
          panel.className = 'admin-panel';
          heading.textContent = 'Current selling price';
          panel.append(heading, price);
          pricing.prepend(panel);
        }
      }
      const sync = () => {
        const slug = category.selectedOptions[0]?.dataset.slug || '';
        form.querySelectorAll('[data-product-field]').forEach((group) => {
          const active =
            {
              type: slug !== 'candle-sets',
              stock: slug === 'accessories',
              candle_count: ['bouquets', 'event-favors'].includes(slug),
              reference: slug === 'home-diffusers',
            }[group.dataset.productField] ?? true;
          group.hidden = !active;
          group.querySelectorAll('input,select').forEach((field) => {
            field.disabled = !active;
            if (group.dataset.productField === 'type') field.required = active;
          });
        });
      };
      category.addEventListener('change', sync);
      sync();
    });
  }
  initializeProductDetails();
  /** Opening/removing optional address fields preserves the existing form contract. */
  function initializeOptionalFields() {
    main.addEventListener(
      'invalid',
      (event) => {
        for (
          let node = event.target.parentElement;
          node && node !== main;
          node = node.parentElement
        )
          if (node.tagName === 'DETAILS') node.open = true;
      },
      true,
    );
    main.querySelectorAll('[data-optional-address]').forEach((group) => {
      const sync = () => {
        const started = [...group.querySelectorAll('input')].some((field) => field.value.trim());
        group.querySelectorAll('[data-address-required]').forEach((field) => {
          field.required = started;
        });
      };
      group.addEventListener('input', sync);
      sync();
    });
    main.querySelectorAll('[data-account-address]').forEach((group) => {
      const remove = group.querySelector('input[type=checkbox]');
      if (!remove) return;
      const sync = () =>
        group.querySelectorAll('input:not([type=checkbox])').forEach((field) => {
          field.disabled = remove.checked;
        });
      remove.addEventListener('change', sync);
      sync();
    });
  }
  initializeOptionalFields();
  /** Preview quantities using integer thousandths; never write stock from this preview. */
  function initializeStockPreview() {
    main.querySelectorAll('[data-stock-movement]').forEach((form) => {
      const type = form.elements.movement_type,
        quantity = form.elements.quantity_change,
        note = form.elements.movement_note,
        preview = form.querySelector('[data-stock-preview]'),
        button = form.querySelector('[data-stock-submit]');
      if (!type || !quantity || !note || !preview || !button) return;
      const millis = (value) => {
        if (!/^\d+(?:\.\d{1,3})?$/.test(value)) return null;
        const [whole, fraction = ''] = value.split('.');
        return Number(whole) * 1000 + Number(fraction.padEnd(3, '0'));
      };
      const money = (value) =>
        Math.trunc(value / 1000) + '.' + String(value % 1000).padStart(3, '0');
      const sync = () => {
        const amount = millis(quantity.value.trim()),
          current = millis(form.dataset.stockCurrent);
        note.required = type.value !== 'STOCK_IN';
        setControlLabel(
          button,
          {
            STOCK_IN: 'Receive stock',
            ADJUSTMENT_IN: 'Save stock correction',
            ADJUSTMENT_OUT: 'Save stock correction',
            DAMAGED_OR_WASTED: 'Record waste',
          }[type.value] || 'Record movement',
          'stock',
        );
        if (amount === null || current === null) {
          preview.textContent = 'Enter a quantity with up to three decimal places.';
          return;
        }
        const next =
          current +
          (['ADJUSTMENT_OUT', 'DAMAGED_OR_WASTED'].includes(type.value) ? -amount : amount);
        preview.textContent =
          next < 0
            ? 'This would make stock negative. Review the quantity.'
            : 'Current ' +
              money(current) +
              ' ' +
              form.dataset.stockUnit +
              ' → After ' +
              money(next) +
              ' ' +
              form.dataset.stockUnit;
      };
      type.addEventListener('change', sync);
      quantity.addEventListener('input', sync);
      sync();
    });
  }
  initializeStockPreview();
  /** Carry the allowlisted order identifier through related editing forms. */
  function preserveProductionContext() {
    const returnOrder = main.querySelector('[data-return-order]')?.dataset.returnOrder;
    if (returnOrder && /^\d+$/.test(returnOrder))
      main.querySelectorAll('form[method="post" i]').forEach((form) => {
        const field = document.createElement('input');
        field.type = 'hidden';
        field.name = 'return_order';
        field.value = returnOrder;
        form.append(field);
      });
  }
  preserveProductionContext();
})();
