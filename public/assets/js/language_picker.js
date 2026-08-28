// WHAT: the EN/FR/AR dropdown in the header — opens/closes the menu, tracks
//       which language is selected, and fires a "languagechange" event.
// WHY:  the "languagechange" CustomEvent (dispatched below) is there for
//       something else to listen for later (e.g. actually reloading the
//       page in the chosen language via app/translations/*.php) — right
//       now nothing listens for it yet, this just updates the visible
//       code/hidden input.
const languagePicker = document.querySelector("[data-language-picker]");

if (languagePicker) {
  const trigger = languagePicker.querySelector(".language-picker__trigger");
  const menu = languagePicker.querySelector("[data-language-menu]");
  const options = [...languagePicker.querySelectorAll("[data-language-option]")];
  const code = languagePicker.querySelector("[data-language-code]");
  const input = languagePicker.querySelector("[data-language-input]");

  function openMenu() {
    menu.hidden = false;
    trigger.setAttribute("aria-expanded", "true");
    const selected = options.find((option) => option.getAttribute("aria-selected") === "true");
    selected?.focus();
  }

  function closeMenu({ restoreFocus = false } = {}) {
    menu.hidden = true;
    trigger.setAttribute("aria-expanded", "false");
    if (restoreFocus) trigger.focus();
  }

  trigger.addEventListener("click", () => {
    if (menu.hidden) openMenu();
    else closeMenu();
  });

  options.forEach((option) => {
    option.addEventListener("click", () => {
      options.forEach((item) => item.setAttribute("aria-selected", String(item === option)));
      code.textContent = option.dataset.code;
      input.value = option.dataset.value;
      closeMenu({ restoreFocus: true });

      languagePicker.dispatchEvent(new CustomEvent("languagechange", {
        bubbles: true,
        detail: { language: option.dataset.value },
      }));
    });
  });

  // WHAT: clicking anywhere outside the picker closes the menu.
  document.addEventListener("click", (event) => {
    if (!languagePicker.contains(event.target)) closeMenu();
  });

  languagePicker.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
      event.preventDefault();
      closeMenu({ restoreFocus: true });
    }
  });
}
