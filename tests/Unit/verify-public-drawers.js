const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const documentListeners = new Map();
const document = {
  activeElement: null,
  addEventListener(type, listener) { documentListeners.set(type, listener); },
};

function element(parent = null) {
  const listeners = new Map();
  const node = {
    parent,
    hidden: false,
    isConnected: true,
    attributes: {},
    addEventListener(type, listener) { listeners.set(type, listener); },
    setAttribute(name, value) { this.attributes[name] = value; },
    contains(target) {
      for (let current = target; current; current = current.parent) {
        if (current === this) return true;
      }
      return false;
    },
    focus() {
      document.activeElement = this;
      documentListeners.get("focusin")?.({ target: this });
    },
    click() {
      listeners.get("click")?.({ target: this });
      documentListeners.get("click")?.({ target: this });
    },
  };
  return node;
}

const categoryTrigger = element();
const categoryPanel = element();
const categoryClose = element(categoryPanel);
const accountTrigger = element();
const accountPanel = element();
const accountClose = element(accountPanel);
const outside = element();
categoryPanel.hidden = true;
accountPanel.hidden = true;

const nodes = {
  ".category-menu-trigger": categoryTrigger,
  "#categories-drawer": categoryPanel,
  ".close-category-menu": categoryClose,
  ".account-menu-trigger": accountTrigger,
  "#account-drawer": accountPanel,
  ".account-drawer-button": accountClose,
};
document.querySelector = (selector) => nodes[selector] ?? null;

const script = fs.readFileSync(path.join(__dirname, "../../public/assets/js/floating_menus.js"), "utf8");
vm.runInNewContext(script, { document });

categoryTrigger.click();
assert.equal(categoryPanel.hidden, false);
assert.equal(categoryTrigger.attributes["aria-expanded"], "true");
assert.equal(document.activeElement, categoryClose);

let prevented = false;
documentListeners.get("keydown")({ key: "Escape", preventDefault() { prevented = true; } });
assert.equal(prevented, true);
assert.equal(categoryPanel.hidden, true);
assert.equal(categoryTrigger.attributes["aria-expanded"], "false");
assert.equal(document.activeElement, categoryTrigger);

accountTrigger.click();
assert.equal(document.activeElement, accountClose);
categoryTrigger.click();
assert.equal(accountPanel.hidden, true);
assert.equal(categoryPanel.hidden, false);
assert.equal(document.activeElement, categoryClose);

outside.focus();
assert.equal(categoryPanel.hidden, true);
assert.equal(document.activeElement, outside);

accountTrigger.click();
accountClose.click();
assert.equal(accountPanel.hidden, true);
assert.equal(document.activeElement, accountTrigger);

categoryTrigger.click();
outside.click();
assert.equal(categoryPanel.hidden, true);

console.log("Public drawer keyboard and focus checks passed.");
