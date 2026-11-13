/* Shared native-select enhancement. Search is opt-in; submitted IDs remain native values. */
let customSelectId = 0;
export function initCustomSelects(root = document) {
  root.querySelectorAll('select').forEach((select) => {
    if (select.dataset.customSelectReady === 'true') return;
    const field = select.parentElement,
      label = field?.querySelector('label[for="' + select.id + '"]');
    if (!field || !label) return;
    const searchable = select.hasAttribute('data-searchable');
    const id = 'custom-select-' + ++customSelectId,
      menuId = id + '-menu';
    label.id ||= id + '-label';
    const customSelect = document.createElement('div');
    customSelect.className = 'custom-select';
    const trigger = document.createElement(searchable ? 'input' : 'button');
    trigger.className = 'custom-select__trigger';
    trigger.id = id;
    trigger.type = searchable ? 'text' : 'button';
    trigger.setAttribute('aria-haspopup', 'listbox');
    trigger.setAttribute('aria-expanded', 'false');
    trigger.setAttribute('aria-controls', menuId);
    trigger.setAttribute('aria-labelledby', label.id);
    if (searchable) {
      trigger.role = 'combobox';
      trigger.autocomplete = 'off';
      trigger.setAttribute('aria-autocomplete', 'list');
      trigger.placeholder = select.dataset.placeholder || 'Search or select...';
    }
    for (const attribute of ['aria-describedby', 'aria-invalid'])
      if (select.hasAttribute(attribute))
        trigger.setAttribute(attribute, select.getAttribute(attribute));
    const value = document.createElement('span');
    value.className = 'custom-select__value';
    if (!searchable) {
      const chevron = document.createElement('span');
      chevron.className = 'custom-select__chevron';
      chevron.setAttribute('aria-hidden', 'true');
      trigger.append(value, chevron);
    }
    const menu = document.createElement('div');
    menu.className = 'custom-select__menu';
    menu.id = menuId;
    menu.role = 'listbox';
    menu.hidden = true;
    menu.setAttribute('aria-labelledby', label.id);
    const status = document.createElement('div');
    status.className = 'custom-select__status';
    status.setAttribute('role', 'status');
    let options = [],
      active = -1,
      editing = false,
      clearingForSearch = false;
    const selectedText = () =>
      select.value
        ? (
            select.selectedOptions[0]?.dataset?.label || select.selectedOptions[0]?.textContent
          )?.trim() || ''
        : '';
    function syncSelection() {
      for (const attribute of ['aria-describedby', 'aria-invalid'])
        if (select.hasAttribute(attribute))
          trigger.setAttribute(attribute, select.getAttribute(attribute));
      trigger.disabled = select.disabled;
      trigger.setAttribute('aria-required', String(!!select.required));
      if (searchable)
        trigger.placeholder = select.disabled
          ? select.options[0]?.textContent || 'No configured choices.'
          : select.dataset.placeholder || 'Search or select...';
      if (searchable) {
        if (!editing) trigger.value = selectedText();
      } else
        value.textContent = select.selectedOptions[0]?.textContent.trim() || 'Choose an option';
      options.forEach((option) =>
        option.setAttribute('aria-selected', String(option.dataset.value === select.value)),
      );
    }
    function render() {
      const query = searchable && editing ? trigger.value.trim().toLocaleLowerCase() : '';
      const matches = [...select.options].filter(
        (option) =>
          !option.disabled &&
          (!searchable || option.value) &&
          option.textContent.toLocaleLowerCase().includes(query),
      );
      menu.replaceChildren();
      active = -1;
      trigger.removeAttribute('aria-activedescendant');
      // Bound rendered suggestions while retaining all native values and searchable records.
      options = matches.slice(0, searchable ? 60 : matches.length).map((native, index) => {
        const option = document.createElement('button');
        option.type = 'button';
        option.role = 'option';
        option.tabIndex = -1;
        option.className = 'custom-select__option';
        option.dataset.value = native.value;
        option.id = menuId + '-' + index;
        if (native.dataset?.context) {
          const primary = document.createElement('span');
          primary.className = 'custom-select__primary';
          primary.textContent = native.dataset.label || native.textContent.trim();
          const secondary = document.createElement('span');
          secondary.className = 'custom-select__secondary';
          secondary.textContent = native.dataset.context;
          option.append(primary, secondary);
        } else option.textContent = native.textContent.trim();
        menu.append(option);
        return option;
      });
      status.textContent =
        matches.length === 0
          ? select.dataset.emptyMessage || 'No choices match your search.'
          : matches.length > 60 && searchable
            ? 'Showing 60 matches. Type more to narrow results.'
            : '';
      if (status.textContent) menu.append(status);
      syncSelection();
      const selected = options.findIndex((option) => option.dataset.value === select.value);
      if (selected >= 0) highlight(selected);
    }
    function closeMenu({ restoreFocus = false } = {}) {
      menu.hidden = true;
      trigger.setAttribute('aria-expanded', 'false');
      trigger.removeAttribute('aria-activedescendant');
      editing = false;
      syncSelection();
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
      const topBar = document.querySelector('.header-top, .admin-topbar');
      if (topBar && ['fixed', 'sticky'].includes(getComputedStyle(topBar).position)) {
        const topBarBox = topBar.getBoundingClientRect();
        if (topBarBox.top <= visibleTop + 1 && topBarBox.bottom > visibleTop) {
          visibleTop = Math.min(visibleBottom, topBarBox.bottom);
        }
      }

      const navigation = document.querySelector('.site-nav-list');
      if (navigation && getComputedStyle(navigation).position === 'fixed') {
        const navigationBox = navigation.getBoundingClientRect();
        const sitsAtBottom =
          navigationBox.top > (visibleTop + visibleBottom) / 2 &&
          navigationBox.bottom >= visibleBottom - 24;
        if (sitsAtBottom) visibleBottom = Math.max(visibleTop, navigationBox.top);
      }

      const triggerBox = trigger.getBoundingClientRect();
      const spaceAbove = Math.max(0, triggerBox.top - visibleTop - safeGap);
      const spaceBelow = Math.max(0, visibleBottom - triggerBox.bottom - safeGap);
      const preferredHeight = Math.min(
        menu.scrollHeight,
        searchable ? 360 : 272,
        (viewport?.height ?? window.innerHeight) * 0.55,
      );
      const opensUpward = spaceBelow < preferredHeight && spaceAbove > spaceBelow;
      const availableSpace = opensUpward ? spaceAbove : spaceBelow;

      // WHAT: CSS reads this value to anchor the menu above or below its box.
      // WHY:  a field near the bottom should open upward, while a field near
      //       the top should keep the more natural downward direction.
      menu.dataset.placement = opensUpward ? 'top' : 'bottom';
      menu.style.maxHeight = `${Math.max(72, Math.min(preferredHeight, availableSpace))}px`;
    }

    function openMenu() {
      if (select.disabled) return;
      document
        .querySelectorAll('.custom-select__trigger[aria-expanded="true"]')
        .forEach((other) => {
          if (other !== trigger) other.dispatchEvent(new Event('select-close'));
        });
      render();
      menu.hidden = false;
      placeMenuWhereItFits();
      trigger.setAttribute('aria-expanded', 'true');
    }
    function highlight(index) {
      if (!options.length) return;
      active = (index + options.length) % options.length;
      options.forEach((option, i) => option.classList.toggle('is-active', i === active));
      trigger.setAttribute('aria-activedescendant', options[active].id);
      options[active].scrollIntoView({ block: 'nearest' });
      if (!searchable) options[active].focus();
    }
    function selectOption(option) {
      select.value = option.dataset.value;
      editing = false;
      select.dispatchEvent(new Event('change', { bubbles: true }));
      trigger.removeAttribute('aria-invalid');
      closeMenu();
      trigger.focus();
    }
    trigger.addEventListener('select-close', () => closeMenu());
    trigger.addEventListener('click', () =>
      searchable ? openMenu() : menu.hidden ? openMenu() : closeMenu(),
    );
    if (searchable) {
      trigger.addEventListener('focus', () => openMenu());
      trigger.addEventListener('input', () => {
        editing = true;
        if (select.value) {
          clearingForSearch = true;
          select.value = '';
          select.dispatchEvent(new Event('change', { bubbles: true }));
          clearingForSearch = false;
        }
        openMenu();
      });
    }
    const keys = (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        closeMenu();
        return;
      }
      if (event.key === 'Tab') {
        closeMenu();
        return;
      }
      if (event.key === 'Enter') {
        event.preventDefault();
        if (!menu.hidden && options.length) selectOption(options[Math.max(0, active)]);
        else if (menu.hidden) openMenu();
        return;
      }
      if (
        !['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key) ||
        (searchable && ['Home', 'End'].includes(event.key))
      )
        return;
      event.preventDefault();
      if (menu.hidden) openMenu();
      highlight(
        event.key === 'Home'
          ? 0
          : event.key === 'End'
            ? options.length - 1
            : active < 0
              ? event.key === 'ArrowDown'
                ? 0
                : options.length - 1
              : active + (event.key === 'ArrowDown' ? 1 : -1),
      );
    };
    trigger.addEventListener('keydown', keys);
    menu.addEventListener('keydown', keys);
    menu.addEventListener('mousedown', (event) => {
      if (searchable) event.preventDefault();
    });
    menu.addEventListener('click', (event) => {
      const option = event.target.closest('.custom-select__option');
      if (option) selectOption(option);
    });
    customSelect.addEventListener('focusout', (event) => {
      if (!customSelect.contains(event.relatedTarget)) closeMenu();
    });
    document.addEventListener('click', (event) => {
      if (!customSelect.contains(event.target)) closeMenu();
    });
    label.addEventListener('click', (event) => {
      event.preventDefault();
      trigger.focus();
    });
    const refreshSelection = () => {
      if (!clearingForSearch) editing = false;
      if (!menu.hidden) render();
      else syncSelection();
    };
    select.addEventListener('change', refreshSelection);
    select.addEventListener('select-sync', refreshSelection);
    select.addEventListener('invalid', (event) => {
      event.preventDefault();
      trigger.setAttribute('aria-invalid', 'true');
      trigger.focus();
    });
    new MutationObserver(() => {
      if (!menu.hidden) render();
      else syncSelection();
    }).observe(select, {
      childList: true,
      subtree: true,
      attributes: true,
      attributeFilter: ['disabled', 'selected', 'required', 'aria-describedby', 'aria-invalid'],
    });
    select.dataset.customSelectReady = 'true';
    select.tabIndex = -1;
    select.setAttribute('aria-hidden', 'true');
    field.classList.add('custom-select-field', 'is-enhanced');
    customSelect.append(trigger, menu);
    select.insertAdjacentElement('afterend', customSelect);
    syncSelection();
  });
}
