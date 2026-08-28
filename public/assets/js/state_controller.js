// WHAT: shared empty/loading/error/success panel logic — the JS half of
//       components/states.css and every [data-ui-state] block in the PHP
//       pages (login, signup, contact, cart, product, category pages).
// WHY:  auth.js, cart.js, catalogue.js, contact.js, and product.js all call
//       this instead of five separate copies of "hide every panel except
//       the one that matches, and use container.hidden to hide/show the
//       whole group." One shared place to fix a bug in that logic.
export function createStateController(container) {
  if (!container) return null;

  const panels = [...container.querySelectorAll("[data-ui-state]")];

  // WHAT: show exactly one state panel; optionally set its message text.
  function show(state, message = "") {
    const activePanel = panels.find((panel) => panel.dataset.uiState === state);

    panels.forEach((panel) => {
      panel.hidden = panel !== activePanel;
    });

    container.hidden = !activePanel;

    if (activePanel && message) {
      const messageElement = activePanel.querySelector("[data-ui-state-message]");
      if (messageElement) messageElement.textContent = message;
    }
  }

  return { show };
}

// WHAT: true only if every given field is blank (whitespace-only counts as blank).
export function fieldsAreEmpty(fields) {
  return fields.every((field) => field.value.trim() === "");
}
