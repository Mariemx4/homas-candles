// WHAT: replaces a plain <select> with a custom-styled dropdown, while
//       keeping the real <select> in the DOM (hidden) as the source of truth.
// WHY:  native <select> dropdowns can't be styled consistently across
//       browsers — this builds a fully custom, keyboard-accessible
//       replacement UI on top, and keeps it in sync with the real select
//       via syncSelection()/selectOption() so form submission still works
//       exactly as if the native control were used directly.
let customSelectId = 0;

export function initCustomSelects(root = document) {
  root.querySelectorAll("select").forEach((select) => {
    if (select.dataset.customSelectReady === "true") return;

    const field = select.parentElement;
    const label = field?.querySelector(`label[for="${select.id}"]`);
    if (!field || !label) return;

    customSelectId += 1;
    const controlId = `custom-select-${customSelectId}`;
    const menuId = `${controlId}-menu`;
    const labelId = label.id || `${controlId}-label`;
    label.id = labelId;

    const customSelect = document.createElement("div");
    customSelect.className = "custom-select";

    const trigger = document.createElement("button");
    trigger.className = "custom-select__trigger";
    trigger.type = "button";
    trigger.id = controlId;
    trigger.setAttribute("aria-haspopup", "listbox");
    trigger.setAttribute("aria-expanded", "false");
    trigger.setAttribute("aria-controls", menuId);
    trigger.setAttribute("aria-labelledby", `${labelId} ${controlId}`);
    // Preserve server-rendered validation relationships when enhancing a
    // required select such as signup's governorate field.
    if (select.hasAttribute("aria-describedby")) {
      trigger.setAttribute("aria-describedby", select.getAttribute("aria-describedby"));
    }
    if (select.hasAttribute("aria-invalid")) {
      trigger.setAttribute("aria-invalid", select.getAttribute("aria-invalid"));
    }

    const value = document.createElement("span");
    value.className = "custom-select__value";
    const chevron = document.createElement("span");
    chevron.className = "custom-select__chevron";
    chevron.setAttribute("aria-hidden", "true");
    trigger.append(value, chevron);

    const menu = document.createElement("div");
    menu.className = "custom-select__menu";
    menu.id = menuId;
    menu.role = "listbox";
    menu.setAttribute("aria-labelledby", labelId);
    menu.hidden = true;

    // WHAT: one custom option button per native <option>, built with
    //       createElement/textContent — never innerHTML — so option text
    //       can't be interpreted as markup.
    const options = [...select.options].map((nativeOption, index) => {
      const option = document.createElement("button");
      option.className = "custom-select__option";
      option.type = "button";
      option.role = "option";
      option.dataset.value = nativeOption.value;
      option.dataset.index = String(index);
      option.textContent = nativeOption.textContent.trim();
      option.disabled = nativeOption.disabled;
      menu.append(option);
      return option;
    });

    function syncSelection() {
      const selectedIndex = Math.max(0, select.selectedIndex);
      value.textContent = select.options[selectedIndex]?.textContent.trim() || "Choose an option";
      options.forEach((option, index) => {
        option.setAttribute("aria-selected", String(index === selectedIndex));
      });
    }

    function closeMenu({ restoreFocus = false } = {}) {
      menu.hidden = true;
      trigger.setAttribute("aria-expanded", "false");
      if (restoreFocus) trigger.focus();
    }

    function placeMenuWhereItFits() {
      const viewport = window.visualViewport;
      let visibleTop = viewport?.offsetTop ?? 0;
      let visibleBottom = visibleTop + (viewport?.height ?? window.innerHeight);
      const safeGap = 12;

      // WHAT: remove the space covered by the fixed mobile header and bottom
      //       navigation before deciding where the dropdown has room to open.
      // WHY:  those bars are drawn over the page, so window.innerHeight alone
      //       would incorrectly count the hidden space behind them as usable.
      const topBar = document.querySelector(".header-top");
      if (topBar && getComputedStyle(topBar).position === "fixed") {
        const topBarBox = topBar.getBoundingClientRect();
        if (topBarBox.top <= visibleTop + 1 && topBarBox.bottom > visibleTop) {
          visibleTop = Math.min(visibleBottom, topBarBox.bottom);
        }
      }

      const navigation = document.querySelector(".site-nav-list");
      if (navigation && getComputedStyle(navigation).position === "fixed") {
        const navigationBox = navigation.getBoundingClientRect();
        const sitsAtBottom = navigationBox.top > (visibleTop + visibleBottom) / 2
          && navigationBox.bottom >= visibleBottom - 24;
        if (sitsAtBottom) visibleBottom = Math.max(visibleTop, navigationBox.top);
      }

      const triggerBox = trigger.getBoundingClientRect();
      const spaceAbove = Math.max(0, triggerBox.top - visibleTop - safeGap);
      const spaceBelow = Math.max(0, visibleBottom - triggerBox.bottom - safeGap);
      const preferredHeight = Math.min(menu.scrollHeight, (viewport?.height ?? window.innerHeight) * 0.55);
      const opensUpward = spaceBelow < preferredHeight && spaceAbove > spaceBelow;
      const availableSpace = opensUpward ? spaceAbove : spaceBelow;

      // WHAT: CSS reads this value to anchor the menu above or below its box.
      // WHY:  a field near the bottom should open upward, while a field near
      //       the top should keep the more natural downward direction.
      menu.dataset.placement = opensUpward ? "top" : "bottom";
      menu.style.maxHeight = `${Math.max(72, Math.min(preferredHeight, availableSpace))}px`;
    }

    function openMenu() {
      // WHAT: close any other open custom-select on the page first.
      document.querySelectorAll(".custom-select__trigger[aria-expanded='true']").forEach((openTrigger) => {
        if (openTrigger !== trigger) openTrigger.click();
      });

      // Show it invisibly for one measurement: hidden elements have no size,
      // so the browser must lay the menu out before we can choose a direction.
      menu.style.visibility = "hidden";
      menu.hidden = false;
      placeMenuWhereItFits();
      menu.style.visibility = "";
      trigger.setAttribute("aria-expanded", "true");
      options[select.selectedIndex]?.focus();
    }

    // WHAT: pushes a chosen option back onto the real <select> and fires a
    //       real "change" event, so any code listening to the native
    //       select (e.g. a form submit) behaves exactly as before.
    function selectOption(option) {
      select.value = option.dataset.value;
      select.dispatchEvent(new Event("change", { bubbles: true }));
      syncSelection();
      closeMenu({ restoreFocus: true });
    }

    trigger.addEventListener("click", () => {
      if (menu.hidden) openMenu();
      else closeMenu();
    });

    label.addEventListener("click", (event) => {
      event.preventDefault();
      trigger.focus();
    });

    trigger.addEventListener("keydown", (event) => {
      if (!["ArrowDown", "ArrowUp", "Home", "End"].includes(event.key)) return;
      event.preventDefault();
      openMenu();

      if (event.key === "ArrowUp" || event.key === "End") options.at(-1)?.focus();
      else options[0]?.focus();
    });

    // WHAT: arrow/home/end/escape/tab keyboard navigation inside the open menu.
    options.forEach((option, index) => {
      option.addEventListener("click", () => selectOption(option));
      option.addEventListener("keydown", (event) => {
        if (event.key === "Escape") {
          event.preventDefault();
          closeMenu({ restoreFocus: true });
          return;
        }

        if (event.key === "Tab") {
          closeMenu();
          return;
        }

        if (!["ArrowDown", "ArrowUp", "Home", "End"].includes(event.key)) return;
        event.preventDefault();
        const nextIndex = event.key === "Home"
          ? 0
          : event.key === "End"
            ? options.length - 1
            : (index + (event.key === "ArrowDown" ? 1 : -1) + options.length) % options.length;
        options[nextIndex]?.focus();
      });
    });

    document.addEventListener("click", (event) => {
      if (!customSelect.contains(event.target)) closeMenu();
    });

    // WHAT: swap the native select out of view but keep it functional —
    //       tabIndex -1 and aria-hidden remove it from the tab order/screen
    //       readers, since the custom trigger/menu now represents it.
    select.addEventListener("change", syncSelection);
    select.dataset.customSelectReady = "true";
    select.tabIndex = -1;
    select.setAttribute("aria-hidden", "true");
    field.classList.add("custom-select-field", "is-enhanced");
    customSelect.append(trigger, menu);
    select.insertAdjacentElement("afterend", customSelect);
    syncSelection();
  });
}
