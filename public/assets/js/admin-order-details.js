'use strict';

// Mode controls select which snapshot/account fields may be submitted.
// Services enforce the same selection; this script only presents the workflow.
document.querySelectorAll('[data-order-customer-modes]').forEach((root) => {
  const form = root.closest('form');
  const radios = [...root.querySelectorAll('[data-customer-mode]')];
  const panels = [...root.querySelectorAll('[data-customer-mode-panel]')];
  const picker = root.querySelector('[data-order-customer-picker]');
  const search = picker.querySelector('[data-customer-search]');
  const id = picker.querySelector('[data-customer-id]');
  const results = picker.querySelector('[data-customer-results]');
  const searchPanel = picker.querySelector('[data-customer-search-panel]');
  const summary = picker.querySelector('[data-customer-summary]');
  const snapshot = root.querySelector('[data-customer-snapshot]');
  const snapshotFields = root.querySelector('[data-customer-snapshot-fields]');
  const method = form.querySelector('[name="delivery_method"]');
  const fee = form.querySelector('[name="delivery_fee"]');
  const copy = form.querySelector('[data-copy-account-address]');
  const addressChoice = form.querySelector('[data-account-address-choice]');
  const addressHeading = form.querySelector('[data-order-address-heading]');
  const addressNames = ['street_address', 'delegation', 'governorate', 'postal_code'];
  let mode = root.dataset.initialCustomerMode;
  let requestNumber = 0;
  const minimumFee = form.dataset.deliveryFeeDefault || '';
  let deliveryFee = fee && Number(fee.value) >= Number(minimumFee) ? fee.value : minimumFee;
  const field = (name) => form.elements.namedItem(name);
  const syncSelection = () => {
    const selected = mode === 'existing' && id.value !== '';
    searchPanel.hidden = selected;
    summary.hidden = !selected;
    snapshot.hidden = !selected;
    snapshotFields.disabled = !selected;
  };
  const syncFulfilment = (changed = false) => {
    if (!method || !fee) return;
    const delivery = method.value === 'DELIVERY';
    const newAccount = mode === 'new';
    const copyAddress = newAccount && copy?.checked === true;
    if (changed) {
      if (!delivery && Number(fee.value) >= Number(minimumFee)) deliveryFee = fee.value;
      fee.value = delivery ? deliveryFee : '0.000';
    }
    fee.readOnly = !delivery;
    if (copy) { copy.disabled = !newAccount; }
    if (addressChoice) addressChoice.hidden = !newAccount || !delivery;
    if (addressHeading) addressHeading.hidden = !delivery || copyAddress;
    addressNames.forEach((name) => {
      const input = field(name);
      if (!input) return;
      input.required = delivery && !copyAddress;
      input.disabled = !delivery || copyAddress;
      const container = input.closest('.admin-field');
      if (container) container.hidden = input.disabled;
    });
  };
  const applyMode = (next) => {
    if (mode !== next) {
      ++requestNumber;
      id.value = '';
      search.value = '';
      results.replaceChildren();
      snapshot.open = false;
    }
    mode = next;
    panels.forEach((panel) => { panel.hidden = panel.dataset.customerModePanel !== mode; panel.disabled = panel.hidden; });
    syncSelection();
    syncFulfilment();
  };
  radios.forEach((radio) => radio.addEventListener('change', () => { if (radio.checked) applyMode(radio.value); }));
  method?.addEventListener('change', () => syncFulfilment(true));
  copy?.addEventListener('change', () => syncFulfilment());
  const read = async (parameters) => {
    const response = await fetch(picker.dataset.endpoint + '?' + new URLSearchParams(parameters), { headers: { Accept: 'application/json' } });
    if (!response.ok) throw new Error('Customer accounts could not be loaded. Please try again.');
    return response.json();
  };
  const showCustomer = (customer) => {
    [['name', 'customer_name'], ['email', 'customer_email'], ['phone', 'customer_phone']].forEach(([label, key]) => {
      picker.querySelector('[data-customer-summary-' + label + ']').textContent = customer[key] || '';
    });
    picker.querySelector('[data-customer-summary-address]').textContent = addressNames.map((name) => customer[name] || '').filter(Boolean).join(', ');
    syncSelection();
  };
  const copyCustomer = (customer, overwrite) => {
    ['customer_name', 'customer_phone', 'customer_email'].forEach((key) => {
      const input = field('existing_' + key);
      if (input && (overwrite || !input.value.trim())) input.value = customer[key] || '';
    });
    addressNames.forEach((key) => {
      const input = field(key);
      if (input && (overwrite || !input.value.trim())) input.value = customer[key] || '';
    });
  };
  const chooseCustomer = async (customer) => {
    const request = ++requestNumber;
    id.value = String(customer.id);
    results.replaceChildren();
    showCustomer(customer);
    try {
      const data = await read({ id: customer.id });
      if (request !== requestNumber || mode !== 'existing' || id.value !== String(customer.id)) return;
      showCustomer(data.customer);
      if (root.dataset.copyOnSelect === '1') copyCustomer(data.customer, true);
    } catch (error) {
      if (request === requestNumber) {
        id.value = '';
        syncSelection();
        results.textContent = error.message;
      }
    }
  };
  const find = async () => {
    if (mode !== 'existing') return;
    const request = ++requestNumber;
    const q = search.value.trim();
    results.textContent = q.length < 2 ? 'Enter at least two characters.' : 'Searching...';
    if (q.length < 2) return;
    try {
      const data = await read({ q });
      if (request !== requestNumber || mode !== 'existing') return;
      results.replaceChildren();
      if (!data.customers.length) results.textContent = 'No matching customer accounts.';
      data.customers.forEach((customer) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'admin-button admin-button--secondary';
        button.textContent = [customer.customer_name, customer.customer_phone, customer.customer_email].filter(Boolean).join(' | ');
        button.addEventListener('click', () => chooseCustomer(customer));
        results.append(button);
      });
    } catch (error) { if (request === requestNumber) results.textContent = error.message; }
  };
  picker.querySelector('[data-customer-find]').addEventListener('click', find);
  search.addEventListener('keydown', (event) => { if (event.key === 'Enter') { event.preventDefault(); find(); } });
  picker.querySelector('[data-customer-change]').addEventListener('click', () => {
    ++requestNumber;
    id.value = '';
    results.replaceChildren();
    syncSelection();
    search.focus();
  });
  picker.querySelector('[data-customer-fill]').addEventListener('click', async () => {
    const selectedId = id.value;
    if (!selectedId || mode !== 'existing') return;
    const request = ++requestNumber;
    try {
      const { customer } = await read({ id: selectedId });
      if (request !== requestNumber || id.value !== selectedId || mode !== 'existing') return;
      copyCustomer(customer, true);
      showCustomer(customer);
    } catch (error) {
      if (request === requestNumber) {
        snapshot.open = true;
        const message = document.createElement('p');
        message.textContent = error.message;
        message.setAttribute('role', 'alert');
        snapshot.append(message);
      }
    }
  });
  // Reveal optional snapshot fields when native validation finds an error.
  form.addEventListener('invalid', (event) => {
    const details = event.target.closest('details');
    if (details) details.open = true;
  }, true);
  applyMode(mode);
});
