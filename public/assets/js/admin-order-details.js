document.querySelectorAll('[data-order-customer-picker]').forEach((picker) => {
  const form = picker.closest('form');
  const search = picker.querySelector('[data-customer-search]');
  const id = picker.querySelector('[data-customer-id]');
  const results = picker.querySelector('[data-customer-results]');
  const selection = picker.querySelector('[data-customer-selection]');
  const fill = picker.querySelector('[data-customer-fill]');
  let requestNumber = 0;
  const read = async (parameters) => {
    const response = await fetch(`${picker.dataset.endpoint}?${new URLSearchParams(parameters)}`, { headers: { Accept: 'application/json' } });
    if (!response.ok) throw new Error('Customer accounts could not be loaded. Please try again.');
    return response.json();
  };
  const find = async () => {
    const request = ++requestNumber;
    const q = search.value.trim();
    results.textContent = q.length < 2 ? 'Enter at least two characters.' : 'Searching…';
    if (q.length < 2) return;
    try {
      const data = await read({ q });
      if (request !== requestNumber) return;
      results.replaceChildren();
      if (!data.customers.length) results.textContent = 'No matching accounts. You can keep this as a guest order.';
      data.customers.forEach((customer) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'admin-button admin-button--secondary';
        button.textContent = `${customer.customer_name} · ${customer.customer_phone} · ${customer.customer_email}`;
        button.addEventListener('click', () => {
          id.value = String(customer.id);
          selection.textContent = `Linked account: ${customer.customer_name} · ${customer.customer_phone}`;
          fill.disabled = false;
          results.replaceChildren();
          ['customer_name', 'customer_phone', 'customer_email'].forEach((key) => {
            const input = form.elements.namedItem(key);
            if (input && !input.value.trim()) input.value = customer[key] || '';
          });
        });
        results.append(button);
      });
    } catch (error) { if (request === requestNumber) results.textContent = error.message; }
  };
  picker.querySelector('[data-customer-find]').addEventListener('click', find);
  search.addEventListener('keydown', (event) => { if (event.key === 'Enter') { event.preventDefault(); find(); } });
  picker.querySelector('[data-customer-clear]').addEventListener('click', () => {
    ++requestNumber;
    id.value = '';
    fill.disabled = true;
    selection.textContent = 'Guest order — no account linked.';
    results.replaceChildren();
  });
  fill.addEventListener('click', async () => {
    const selectedId = id.value;
    if (!selectedId) return;
    try {
      const { customer } = await read({ id: selectedId });
      if (id.value !== selectedId) return;
      ['customer_name', 'customer_phone', 'customer_email', 'street_address', 'delegation', 'governorate', 'postal_code'].forEach((key) => {
        const input = form.elements.namedItem(key);
        if (input) input.value = customer[key] || '';
      });
      results.textContent = 'Account contact and address copied. Review the details before saving.';
    } catch (error) { results.textContent = error.message; }
  });
});

document.querySelectorAll('form').forEach((form) => {
  const method = form.querySelector('select[name="delivery_method"]');
  const fee = form.querySelector('input[name="delivery_fee"]');
  if (!method || !fee) return;
  let deliveryFee = Number(fee.value) >= 9 ? fee.value : '9.000';
  const sync = (changed) => {
    const delivery = method.value === 'DELIVERY';
    if (changed) {
      if (!delivery && Number(fee.value) >= 9) deliveryFee = fee.value;
      fee.value = delivery ? deliveryFee : '0.000';
    }
    fee.readOnly = !delivery;
    ['street_address', 'delegation', 'governorate', 'postal_code'].forEach((name) => {
      const input = form.elements.namedItem(name);
      if (!input) return;
      input.required = delivery;
      input.disabled = !delivery;
      const field = input.closest('.admin-field');
      if (field) field.hidden = !delivery;
    });
  };
  method.addEventListener('change', () => sync(true));
  sync(false);
});
