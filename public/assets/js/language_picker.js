// A locale selection reloads this page so PHP renders translated content and
// stores the preference. Query parameters for the current page are preserved.
const languagePicker = document.querySelector('[data-language-picker]');

if (languagePicker) {
  const trigger = languagePicker.querySelector('.language-picker__trigger');
  const menu = languagePicker.querySelector('[data-language-menu]');
  const options = [...languagePicker.querySelectorAll('[data-language-option]')];

  /** FUNCTION PURPOSE: Open menu and update its accessibility/focus state. */
  function openMenu() {
    menu.hidden = false;
    trigger.setAttribute('aria-expanded', 'true');
    const selected = options.find((option) => option.getAttribute('aria-selected') === 'true');
    selected?.focus();
  }

  /** FUNCTION PURPOSE: Close menu and restore its neutral accessibility/focus state. */
  function closeMenu({ restoreFocus = false } = {}) {
    menu.hidden = true;
    trigger.setAttribute('aria-expanded', 'false');
    if (restoreFocus) trigger.focus();
  }

  trigger.addEventListener('click', () => {
    if (menu.hidden) openMenu();
    else closeMenu();
  });

  options.forEach((option) => {
    option.addEventListener('click', () => {
      const selected = option.dataset.value;
      if (!['en', 'fr', 'ar'].includes(selected)) return;
      if (document.documentElement.lang === selected) {
        closeMenu({ restoreFocus: true });
        return;
      }
      const destination = new URL(window.location.href);
      destination.searchParams.set('lang', selected);
      window.location.assign(destination.href);
    });
  });

  // WHAT: clicking anywhere outside the picker closes the menu.
  document.addEventListener('click', (event) => {
    if (!languagePicker.contains(event.target)) closeMenu();
  });

  languagePicker.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      event.preventDefault();
      closeMenu({ restoreFocus: true });
    }
  });
}
