const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const source = fs.readFileSync(
  path.join(__dirname, '../../public/assets/js/admin/order-status.js'),
  'utf8',
);
const selectEvents = {};
const formEvents = {};
const pageEvents = {};
const select = {
  value: 'PREPARED',
  disabled: false,
  addEventListener: (name, handler) => {
    selectEvents[name] = handler;
  },
};
const save = { hidden: false, disabled: false };
let automaticSubmissions = 0;
const form = {
  querySelector: (selector) =>
    selector.startsWith('select') ? select : selector === '[data-status-save]' ? save : null,
  addEventListener: (name, handler) => {
    formEvents[name] = handler;
  },
  requestSubmit: () => {
    automaticSubmissions++;
  },
  setAttribute: () => {},
  removeAttribute: () => {},
};
vm.runInNewContext(source, {
  document: { querySelectorAll: () => [form] },
  window: {
    addEventListener: (name, handler) => {
      pageEvents[name] = handler;
    },
  },
});
assert.equal(save.hidden, false, 'Save remains visible.');
assert.equal(save.disabled, true, 'An unchanged status cannot be saved again.');
select.value = 'CONFIRMED';
selectEvents.change();
assert.equal(save.disabled, false, 'Selecting an earlier status enables Save.');
assert.equal(automaticSubmissions, 0, 'Changing the selection waits for an explicit save.');
select.value = 'PREPARED';
selectEvents.change();
assert.equal(save.disabled, true, 'Returning to the original status clears the pending change.');
select.value = 'CONFIRMED';
selectEvents.change();
let prevented = false;
formEvents.submit({
  preventDefault: () => {
    prevented = true;
  },
});
assert.equal(prevented, false, 'The first explicit save is submitted.');
assert.equal(save.disabled, true, 'Save is disabled while submitting.');
assert.equal(select.disabled, false, 'The selected value remains part of the submitted form.');
formEvents.submit({
  preventDefault: () => {
    prevented = true;
  },
});
assert.equal(prevented, true, 'Double submissions are blocked.');
pageEvents.pageshow();
assert.equal(save.disabled, false, 'Browser Back restores a usable Save button.');
console.log('Order status control checks passed.');
