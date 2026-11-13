'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const node = (extra = {}) => ({
  hidden: false,
  textContent: '',
  children: [],
  dataset: {},
  append(...children) {
    this.children.push(...children);
  },
  replaceChildren(...children) {
    this.children = children;
  },
  ...extra,
});
const status = node(),
  history = node(),
  error = node({ hidden: true }),
  changed = node({ hidden: true }),
  actions = node();
const figures = ['total', 'deposit', 'paid', 'remaining'].map((key) =>
  node({ dataset: { orderFigure: key } }),
);
const tracking = node({
  dataset: { url: '/account-order.php?id=1&format=json', version: 'original' },
  querySelector(selector) {
    return {
      '[data-order-history]': history,
      '[data-tracking-error]': error,
      '[data-order-changed]': changed,
    }[selector];
  },
});
const document = {
  hidden: false,
  querySelector(selector) {
    return {
      '[data-order-tracking]': tracking,
      '[data-order-status]': status,
      '[data-order-actions]': actions,
    }[selector];
  },
  querySelectorAll: () => figures,
  createElement: () => node(),
  addEventListener(event, callback) {
    this[event] = callback;
  },
};
let refresh,
  fail = false,
  requests = 0;
const state = {
  version: 'original',
  status: 'Confirmed',
  editable: true,
  history: [{ status: '<script>unsafe</script>', date: '2026-10-04' }],
  figures: { total: '100.000', deposit: '40.000', paid: '40.000', remaining: '60.000' },
};
const context = {
  document,
  window: {
    setInterval(callback, delay) {
      assert.equal(delay, 15000);
      refresh = callback;
    },
  },
  fetch: async (url, options) => {
    requests++;
    assert.equal(url, tracking.dataset.url);
    assert.equal(options.cache, 'no-store');
    if (fail) throw new Error('Offline');
    return { ok: true, json: async () => state };
  },
};
vm.runInNewContext(
  fs.readFileSync(path.join(__dirname, '../../public/assets/js/customer-order.js'), 'utf8'),
  context,
);
(async () => {
  await refresh();
  assert.equal(status.textContent, 'Confirmed');
  assert.equal(figures[3].textContent, '60.000');
  assert.equal(actions.hidden, false);
  assert.equal(history.children[0].children[0].textContent, '<script>unsafe</script>');
  state.version = 'updated';
  state.status = 'Preparing';
  state.editable = false;
  await refresh();
  assert.equal(status.textContent, 'Preparing');
  assert.equal(actions.hidden, true);
  assert.equal(changed.hidden, false);
  fail = true;
  await refresh();
  assert.equal(error.hidden, false);
  assert.equal(figures[3].textContent, '60.000');
  const before = requests;
  document.hidden = true;
  await refresh();
  assert.equal(requests, before);
  document.hidden = false;
  fail = false;
  await document.visibilitychange();
  assert.equal(error.hidden, true);
  console.log(
    'Customer tracking checks passed: refreshed status/balances, stale actions, safe text, offline recovery and hidden tabs.',
  );
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
