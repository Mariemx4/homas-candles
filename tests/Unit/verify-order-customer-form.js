'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(
  require('node:path').join(__dirname, '../../public/assets/js/admin/order-details.js'),
  'utf8',
);
const control = (value = '') => {
  const events = {};
  const container = { hidden: false };
  return {
    value,
    hidden: false,
    disabled: false,
    checked: false,
    required: false,
    dataset: {},
    children: [],
    addEventListener(type, handler) {
      (events[type] ||= []).push(handler);
    },
    fire(type, extra = {}) {
      return Promise.all((events[type] || []).map((handler) => handler({ type, ...extra })));
    },
    closest() {
      return container;
    },
    container,
    replaceChildren() {
      this.children = [];
      this.textContent = '';
    },
    append(child) {
      this.children.push(child);
    },
    focus() {
      this.focused = true;
    },
    setAttribute() {},
  };
};
const profile = {
  id: 9,
  customer_name: 'Existing Customer',
  customer_email: 'account@example.test',
  customer_phone: '22123456',
  street_address: 'Account address',
  delegation: 'Tunis',
  governorate: 'Tunis',
  postal_code: '1000',
};
function fixture(create = true, fetchOverride = null) {
  const mode = create ? 'guest' : 'existing';
  const fields = Object.fromEntries(
    [
      'street_address',
      'delegation',
      'governorate',
      'postal_code',
      ...['existing', 'guest', 'new'].flatMap((m) =>
        ['customer_name', 'customer_email', 'customer_phone'].map((f) => m + '_' + f),
      ),
    ].map((n) => [n, control()]),
  );
  if (!create) {
    fields.existing_customer_name.value = 'Order snapshot';
    fields.street_address.value = 'Order address';
  }
  const method = control('PICKUP'),
    fee = control('0.000'),
    copy = create ? control() : null;
  if (copy) copy.checked = true;
  const form = control();
  form.dataset.deliveryFeeDefault = '9.000';
  const addressChoice = control(),
    addressHeading = control();
  const formSelectors = {
    '[name="delivery_method"]': method,
    '[name="delivery_fee"]': fee,
    '[data-copy-account-address]': copy,
    '[data-account-address-choice]': addressChoice,
    '[data-order-address-heading]': addressHeading,
  };
  form.elements = { namedItem: (n) => fields[n] };
  form.querySelector = (s) => formSelectors[s];
  const id = control(create ? '' : '42'),
    search = control('Customer'),
    results = control(),
    summary = control(),
    searchPanel = control();
  const snapshot = control(),
    snapshotFields = control(),
    find = control(),
    change = control(),
    fill = control();
  const pickerSelectors = {
    '[data-customer-search]': search,
    '[data-customer-id]': id,
    '[data-customer-results]': results,
    '[data-customer-summary]': summary,
    '[data-customer-search-panel]': searchPanel,
    '[data-customer-find]': find,
    '[data-customer-change]': change,
    '[data-customer-fill]': fill,
  };
  ['name', 'email', 'phone', 'address'].forEach(
    (n) => (pickerSelectors['[data-customer-summary-' + n + ']'] = control()),
  );
  const picker = {
    dataset: { endpoint: '/customers.php' },
    querySelector: (s) => pickerSelectors[s],
  };
  const radios = (create ? ['existing', 'guest', 'new'] : ['existing', 'guest']).map((m) => {
    const c = control(m);
    c.checked = m === mode;
    return c;
  });
  const panels = radios.map((r) => {
    const c = control();
    c.dataset.customerModePanel = r.value;
    return c;
  });
  const root = {
    dataset: { initialCustomerMode: mode, copyOnSelect: create ? '1' : '0' },
    closest: () => form,
    querySelectorAll: (s) => (s === '[data-customer-mode]' ? radios : panels),
    querySelector: (s) =>
      ({
        '[data-order-customer-picker]': picker,
        '[data-customer-snapshot]': snapshot,
        '[data-customer-snapshot-fields]': snapshotFields,
      })[s],
  };
  vm.runInNewContext(source, {
    URLSearchParams,
    document: {
      querySelectorAll: (s) => (s === '[data-order-customer-modes]' ? [root] : []),
      createElement: () => control(),
    },
    fetch:
      fetchOverride ||
      (async () => ({ ok: true, json: async () => ({ customer: profile, customers: [profile] }) })),
  });
  const select = async (mode) => {
    const r = radios.find((r) => r.value === mode);
    radios.forEach((r) => (r.checked = false));
    r.checked = true;
    await r.fire('change');
  };
  return {
    fields,
    method,
    fee,
    copy,
    radios,
    panels,
    id,
    search,
    results,
    summary,
    searchPanel,
    snapshotFields,
    find,
    change,
    fill,
    select,
    addressChoice,
    addressHeading,
  };
}
const tick = () => new Promise(setImmediate);
async function main() {
  const f = fixture();
  assert.equal(f.panels.filter((p) => !p.hidden).length, 1);
  assert.equal(f.panels.find((p) => p.dataset.customerModePanel === 'new').disabled, true);
  assert.equal(f.fields.street_address.disabled, true);
  await f.select('existing');
  f.search.value = 'Customer';
  await f.find.fire('click');
  assert.equal(f.results.children.length, 1);
  await f.results.children[0].fire('click');
  assert.equal(f.id.value, '9');
  assert.equal(f.searchPanel.hidden, true);
  assert.equal(f.summary.hidden, false);
  assert.equal(f.fields.existing_customer_name.value, profile.customer_name);
  assert.equal(f.fields.street_address.value, profile.street_address);
  await f.change.fire('click');
  assert.equal(f.id.value, '');
  assert.equal(f.snapshotFields.disabled, true);
  assert.equal(f.searchPanel.hidden, false);
  assert.equal(f.search.focused, true);
  await f.select('new');
  assert.equal(f.panels.filter((p) => !p.hidden).length, 1);
  assert.equal(f.panels.find((p) => p.dataset.customerModePanel === 'existing').disabled, true);
  f.method.value = 'DELIVERY';
  await f.method.fire('change');
  assert.equal(f.fee.value, '9.000');
  assert.equal(
    f.fields.street_address.disabled,
    true,
    'Copying the account address avoids duplicate address entry.',
  );
  assert.equal(f.addressChoice.hidden, false);
  f.copy.checked = false;
  await f.copy.fire('change');
  assert.equal(f.fields.street_address.required, true);
  assert.equal(f.fields.street_address.disabled, false);
  f.fee.value = '12.000';
  f.method.value = 'PICKUP';
  await f.method.fire('change');
  assert.equal(f.fee.value, '0.000');
  assert.equal(f.fields.street_address.disabled, true);
  f.method.value = 'DELIVERY';
  await f.method.fire('change');
  assert.equal(f.fee.value, '12.000');
  await f.select('guest');
  assert.equal(f.id.value, '');
  assert.equal(f.copy.disabled, true);
  assert.equal(f.addressChoice.hidden, true);
  assert.equal(f.fields.street_address.disabled, false);

  const edit = fixture(false);
  await edit.change.fire('click');
  await edit.find.fire('click');
  await edit.results.children[0].fire('click');
  assert.equal(
    edit.fields.existing_customer_name.value,
    'Order snapshot',
    'Changing the account preserves historical snapshots.',
  );
  assert.equal(
    edit.fields.existing_customer_email.value,
    '',
    'Optional blank order email stays blank.',
  );
  assert.equal(edit.fields.street_address.value, 'Order address');
  await edit.fill.fire('click');
  assert.equal(
    edit.fields.street_address.value,
    'Account address',
    'Only an explicit copy replaces snapshot details.',
  );
  await edit.select('guest');
  assert.equal(edit.id.value, '');

  let resolve;
  const stale = fixture(
    true,
    () =>
      new Promise((r) => {
        resolve = r;
      }),
  );
  await stale.select('existing');
  stale.search.value = 'Customer';
  const pending = stale.find.fire('click');
  await stale.select('guest');
  resolve({ ok: true, json: async () => ({ customers: [profile] }) });
  await pending;
  await tick();
  assert.equal(
    stale.results.children.length,
    0,
    'Search results arriving after a mode switch are discarded.',
  );
  assert.equal(stale.id.value, '');
  let finishDetail;
  const lateDetail = fixture(true, async (url) =>
    String(url).includes('?q=')
      ? { ok: true, json: async () => ({ customers: [profile] }) }
      : new Promise((resolve) => {
          finishDetail = resolve;
        }),
  );
  await lateDetail.select('existing');
  lateDetail.search.value = 'Customer';
  await lateDetail.find.fire('click');
  const choosing = lateDetail.results.children[0].fire('click');
  await lateDetail.select('guest');
  finishDetail({ ok: true, json: async () => ({ customer: profile }) });
  await choosing;
  assert.equal(
    lateDetail.id.value,
    '',
    'A late selected profile cannot restore an account link after switching modes.',
  );
  assert.equal(
    lateDetail.fields.existing_customer_name.value,
    '',
    'A late selected profile cannot overwrite inactive contact fields.',
  );

  const method = control('PICKUP'),
    fee = control('0.000');
  const address = Object.fromEntries(
    ['street_address', 'delegation', 'governorate', 'postal_code'].map((name) => [
      name,
      control('Retained address'),
    ]),
  );
  const quotation = {
    dataset: { deliveryFeeDefault: '9.000' },
    elements: { namedItem: (name) => address[name] },
    querySelector: (s) => ({ '[name="delivery_method"]': method, '[name="delivery_fee"]': fee })[s],
  };
  vm.runInNewContext(source, {
    document: { querySelectorAll: (s) => (s === '[data-order-fulfilment]' ? [quotation] : []) },
  });
  assert.equal(
    address.street_address.disabled,
    true,
    'A quotation hides and excludes pickup address fields without any account picker.',
  );
  method.value = 'DELIVERY';
  await method.fire('change');
  assert.equal(fee.value, '9.000');
  assert.equal(address.street_address.required, true);
  assert.equal(address.street_address.value, 'Retained address');
  fee.value = '12.000';
  method.value = 'PICKUP';
  await method.fire('change');
  assert.equal(fee.value, '0.000');
  method.value = 'DELIVERY';
  await method.fire('change');
  assert.equal(fee.value, '12.000', 'A quotation restores its own edited delivery fee.');
  console.log(
    '[PASS] Exclusive modes, explicit account selection, snapshot preservation, address copying, stale responses and delivery fees.',
  );
}
main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
