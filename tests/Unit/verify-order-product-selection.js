'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
class Option {
  constructor(text, value, unused = false, selected = false) {
    this.dataset = {};
    this.textContent = text;
    this.value = value;
    this.selected = selected;
  }
}
function row() {
  const controls = {};
  const make = (selector, dataset = {}, select = false) => {
    const label = {},
      group = { hidden: false, querySelector: () => label };
    const control = {
      dataset,
      options: [],
      textContent: '',
      hidden: false,
      min: '1',
      closest: (s) => (s === '[data-order-item]' ? result : group),
      matches: (s) =>
        s
          .split(', ')
          .some(
            (part) =>
              part === selector || (part === '[data-product-guide]' && dataset.productGuide),
          ),
      replaceChildren(...options) {
        this.options = options;
      },
      append(option) {
        this.options.push(option);
      },
      dispatchEvent() {},
      focus() {},
    };
    let text = '';
    Object.defineProperty(control, 'value', {
      get: () =>
        select
          ? (control.options.find((o) => o.selected) || control.options[0])?.value || ''
          : text,
      set: (v) => {
        if (select) control.options.forEach((o) => (o.selected = o.value === v));
        else text = v;
      },
    });
    controls[selector] = control;
    return control;
  };
  const result = {
    dataset: {},
    querySelector: (s) => controls[s],
    querySelectorAll: (s) => {
      if (s === '[data-option-select]')
        return [
          controls['[data-option-select="colors"]'],
          controls['[data-option-select="scents"]'],
        ];
      if (s === '[data-item-field]')
        return Object.values(controls).filter((c) => c.dataset.itemField);
      if (s === '[data-product-guide], [data-product-search]')
        return Object.values(controls).filter(
          (c) => c.dataset.productGuide || c === controls['[data-product-search]'],
        );
      if (s === '[data-product-guide]')
        return Object.values(controls).filter((c) => c.dataset.productGuide);
      return [];
    },
  };
  make('[data-item-number]');
  make('[data-remove-item]');
  make('[data-product-results]');
  make('[data-product-guide="category"]', { productGuide: 'category' }, true);
  make('[data-product-guide="type"]', { productGuide: 'type' }, true);
  make('[data-product-search]');
  make('[data-product-select]', { itemField: 'product_id' }, true);
  make('[data-item-field="quantity"]', { itemField: 'quantity' });
  make('[data-option-select="colors"]', { itemField: 'color_id', optionSelect: 'colors' }, true);
  make('[data-option-select="scents"]', { itemField: 'scent_id', optionSelect: 'scents' }, true);
  return { result, controls };
}
const catalogue = {
  1: {
    name: 'White Flower Candle',
    standard_price: '12.000',
    category_id: '10',
    category_name: 'Candles',
    type_id: '20',
    type_name: 'Flowers',
    minimum_quantity: 1,
    colors: [[31, 'Ivory']],
    scents: [[41, 'Rose']],
  },
  2: {
    name: 'Rose',
    category_id: '11',
    category_name: 'Bouquets',
    type_id: '21',
    type_name: 'Floral',
    minimum_quantity: 3,
    colors: [[32, 'Pink']],
    scents: [],
  },
  3: {
    name: 'Moon',
    category_id: '10',
    category_name: 'Candles',
    type_id: '22',
    type_name: 'Jar',
    minimum_quantity: 1,
    colors: [],
    scents: [],
  },
};
const rows = [row()],
  events = {};
const list = {
  querySelectorAll: () => rows.map((r) => r.result),
  append() {
    rows.push(row());
  },
  get lastElementChild() {
    return rows.at(-1).result;
  },
};
const form = {
  querySelector: (s) => (s === '[data-order-items]' ? list : null),
  addEventListener: (name, callback) => (events[name] = callback),
};
class Template {
  content = { cloneNode: () => ({}) };
}
let ready;
const document = {
  addEventListener: (type, callback) => (ready = callback),
  querySelector: (s) =>
    s === '[data-manual-order-form]'
      ? form
      : s === '#manual-order-item-template'
        ? new Template()
        : s === '#manual-order-catalogue'
          ? { textContent: JSON.stringify(catalogue) }
          : null,
};
vm.runInNewContext(
  fs.readFileSync(path.join(__dirname, '../../public/assets/js/admin/manual-order.js'), 'utf8'),
  {
    document,
    HTMLTemplateElement: Template,
    Option,
    Event: class {},
    setTimeout: () => 1,
    clearTimeout() {},
  },
);
ready();
const c = rows[0].controls;
c['[data-product-select]'].value = '1';
events.change({ target: c['[data-product-select]'] });
assert.equal(c['[data-product-guide="category"]'].value, '10');
assert.equal(c['[data-product-guide="type"]'].value, '20');
c['[data-option-select="colors"]'].value = '31';
c['[data-option-select="scents"]'].value = '41';
c['[data-product-select]'].value = '2';
events.change({ target: c['[data-product-select]'] });
assert.equal(c['[data-option-select="colors"]'].value, '');
assert.equal(c['[data-option-select="scents"]'].value, '');
c['[data-product-guide="category"]'].value = '';
c['[data-product-guide="type"]'].value = '';
events.change({ target: c['[data-product-guide="category"]'] });
assert.equal(c['[data-product-select]'].options.length, 4);
c['[data-product-guide="category"]'].value = '10';
events.change({ target: c['[data-product-guide="category"]'] });
assert.deepEqual(
  c['[data-product-select]'].options.map((o) => o.value),
  ['', '1', '3'],
);
assert.deepEqual(
  c['[data-product-guide="type"]'].options.map((o) => o.value),
  ['', '20', '22'],
);
c['[data-product-guide="type"]'].value = '22';
events.change({ target: c['[data-product-guide="type"]'] });
assert.deepEqual(
  c['[data-product-select]'].options.map((o) => o.value),
  ['', '3'],
);
c['[data-product-guide="category"]'].value = '';
events.change({ target: c['[data-product-guide="category"]'] });
c['[data-product-guide="type"]'].value = '';
events.change({ target: c['[data-product-guide="type"]'] });
assert.match(c['[data-product-select]'].options[2].textContent, /Rose.*Bouquets.*Floral/);
c['[data-product-select]'].value = '2';
events.change({ target: c['[data-product-select]'] });
assert.equal(c['[data-product-guide="category"]'].value, '11');
assert.equal(c['[data-product-guide="type"]'].value, '21');
assert.equal(c['[data-item-field="quantity"]'].min, '3');
assert.deepEqual(
  c['[data-option-select="colors"]'].options.map((o) => o.value),
  ['', '32'],
);
assert.equal(c['[data-option-select="scents"]'].disabled, true);
c['[data-product-guide="category"]'].value = '10';
events.change({ target: c['[data-product-guide="category"]'] });
assert.equal(c['[data-product-select]'].value, '');
assert.equal(c['[data-option-select="colors"]'].disabled, true);
events.click({ target: { closest: (s) => (s === '[data-add-item]' ? {} : null) } });
assert.equal(rows.length, 2);
assert.equal(rows[1].controls['[data-product-select]'].name, 'items[1][product_id]');
assert.equal(rows[0].controls['[data-product-select]'].name, 'items[0][product_id]');
assert.equal(rows[1].controls['[data-product-select]'].options.length, 4);
console.log(
  'Product selector: guided filtering, direct search, context, options, minimums, and multiple product rows passed.',
);
