'use strict';
const assert = require('node:assert/strict'),
  fs = require('node:fs'),
  vm = require('node:vm'),
  path = require('node:path');
const observers = [];
let document;
class Element {
  constructor(tag) {
    this.tagName = tag;
    this.children = [];
    this.dataset = {};
    this.attrs = {};
    this.events = {};
    this.style = {};
    this.hidden = false;
    this.disabled = false;
    this.textContent = '';
    this.value = '';
    this.scrollHeight = 280;
    const classes = new Set();
    this.classList = {
      add: (...names) => names.forEach((n) => classes.add(n)),
      toggle: (name, on) => (on ? classes.add(name) : classes.delete(name)),
    };
  }
  append(...children) {
    children.forEach((child) => {
      child.parentElement = this;
      this.children.push(child);
    });
  }
  replaceChildren(...children) {
    this.children = [];
    this.append(...children);
  }
  setAttribute(key, value) {
    this.attrs[key] = String(value);
  }
  getAttribute(key) {
    return this.attrs[key];
  }
  hasAttribute(key) {
    return Object.hasOwn(this.attrs, key);
  }
  removeAttribute(key) {
    delete this.attrs[key];
  }
  addEventListener(type, callback) {
    (this.events[type] ||= []).push(callback);
  }
  dispatchEvent(event) {
    event.target ||= this;
    event.preventDefault ||= () => {};
    (this.events[event.type] || []).forEach((callback) => callback(event));
  }
  fire(type, values = {}) {
    this.dispatchEvent({
      type,
      ...values,
      preventDefault() {
        this.prevented = true;
      },
    });
  }
  focus() {
    if (document.activeElement !== this) {
      document.activeElement = this;
      this.fire('focus');
    }
  }
  click() {
    this.fire('click');
  }
  getBoundingClientRect() {
    return { top: 100, bottom: 140 };
  }
  scrollIntoView() {}
  contains(element) {
    return element === this || this.children.some((child) => child.contains(element));
  }
  closest(selector) {
    if (selector === '.custom-select__option' && this.className === 'custom-select__option')
      return this;
    return this.parentElement?.closest(selector) || null;
  }
  insertAdjacentElement(unused, element) {
    this.parentElement.append(element);
  }
}
function fixture(searchable = true, count = 3) {
  const field = new Element('div'),
    label = new Element('label'),
    select = new Element('select');
  select.id = 'record';
  field.querySelector = () => label;
  select.options = [
    { value: '', textContent: 'Choose', selected: true },
    ...Array.from({ length: count }, (_, i) => ({
      value: String(i + 1),
      textContent: i === 0 ? 'Flower Candle' : i === 1 ? 'Floral Duo' : 'Product ' + (i + 1),
      selected: false,
    })),
  ];
  Object.defineProperty(select, 'value', {
    get() {
      return this.options.find((o) => o.selected)?.value || '';
    },
    set(value) {
      this.options.forEach((o) => (o.selected = o.value === value));
    },
  });
  Object.defineProperty(select, 'selectedOptions', {
    get() {
      return this.options.filter((o) => o.selected);
    },
  });
  if (searchable) select.setAttribute('data-searchable', '');
  select.dataset.placeholder = 'Search or select product...';
  select.setAttribute('aria-describedby', 'product-error');
  field.append(label, select);
  return { field, label, select };
}
const first = fixture(true, 120),
  plain = fixture(false, 3),
  fields = [first, plain];
document = {
  activeElement: null,
  createElement: (tag) => new Element(tag),
  querySelector: () => null,
  querySelectorAll: (selector) =>
    selector === 'select'
      ? fields.map((f) => f.select)
      : fields
          .flatMap((f) =>
            f.field.children
              .filter((c) => c.className === 'custom-select')
              .map((c) => c.children[0]),
          )
          .filter((c) => c.attrs['aria-expanded'] === 'true'),
  addEventListener() {},
};
const context = {
  document,
  window: { innerHeight: 800 },
  getComputedStyle: () => ({ position: 'static' }),
  MutationObserver: class {
    constructor(callback) {
      this.callback = callback;
      observers.push(this);
    }
    observe() {}
  },
  Event: class {
    constructor(type, options = {}) {
      this.type = type;
      Object.assign(this, options);
    }
  },
};
vm.createContext(context);
vm.runInContext(
  fs
    .readFileSync(path.join(__dirname, '../../public/assets/js/custom_select.js'), 'utf8')
    .replace('export function', 'function'),
  context,
);
context.initCustomSelects(document);
const custom = first.field.children.at(-1),
  input = custom.children[0],
  menu = custom.children[1];
assert.equal(input.tagName, 'input');
assert.equal(input.role, 'combobox');
assert.equal(input.attrs['aria-describedby'], 'product-error');
input.focus();
assert.equal(menu.hidden, false);
assert.equal(menu.children.filter((c) => c.role === 'option').length, 60);
assert.match(menu.children.at(-1).textContent, /60/);
input.value = 'flo';
input.fire('input');
assert.deepEqual(
  menu.children.filter((c) => c.role === 'option').map((c) => c.textContent),
  ['Flower Candle', 'Floral Duo'],
);
input.fire('keydown', { key: 'ArrowDown' });
assert.equal(input.attrs['aria-activedescendant'], menu.children[0].id);
input.fire('keydown', { key: 'ArrowDown' });
input.fire('keydown', { key: 'Enter' });
assert.equal(first.select.value, '2');
assert.equal(input.value, 'Floral Duo');
assert.equal(menu.hidden, true);
input.fire('click');
input.value = 'no match';
input.fire('input');
assert.equal(first.select.value, '');
assert.match(menu.children[0].textContent, /No choices/);
input.fire('keydown', { key: 'Escape' });
assert.equal(menu.hidden, true);
assert.equal(input.value, '');
input.fire('click');
input.value = 'Product 120';
input.fire('input');
input.fire('keydown', { key: 'ArrowUp' });
input.fire('keydown', { key: 'Enter' });
assert.equal(first.select.value, '120');
input.fire('click');
input.value = 'Flower';
input.fire('input');
const option = menu.children[0];
menu.fire('click', { target: option });
assert.equal(first.select.value, '1');
assert.equal(input.value, 'Flower Candle');
first.select.options = [{ value: '', textContent: 'No configured colors.', selected: true }];
first.select.disabled = true;
observers[0].callback();
assert.equal(input.disabled, true);
assert.equal(input.placeholder, 'No configured colors.');
first.select.disabled = false;
first.select.options = [
  { value: '', textContent: 'Choose', selected: true },
  { value: '9', textContent: 'New Color', selected: false },
];
observers[0].callback();
input.fire('click');
input.value = 'new';
input.fire('input');
input.fire('keydown', { key: 'Enter' });
assert.equal(first.select.value, '9');
first.select.fire('invalid');
assert.equal(input.attrs['aria-invalid'], 'true');
const button = plain.field.children.at(-1).children[0];
assert.equal(button.tagName, 'button');
button.fire('click');
assert.equal(plain.field.children.at(-1).children[1].hidden, false);
button.fire('keydown', { key: 'ArrowDown' });
button.fire('keydown', { key: 'Enter' });
context.initCustomSelects(document);
assert.equal(first.field.children.length, 3);
console.log(
  'Shared searchable select: focus, filtering, bounded results, mouse, arrows, Enter, Escape, empty states, dynamic options, labels, validation, and native dropdown reuse passed.',
);
