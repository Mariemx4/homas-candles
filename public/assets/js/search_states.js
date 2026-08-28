// WHAT: the header search box's own tiny empty/loading/error status —
//       separate from state_controller.js since this is a single inline
//       status message (aria-live span), not a multi-panel block.
// WHY:  listens for a "search:error" custom event that nothing in this
//       codebase dispatches yet — this is scaffolding for a future
//       fetch-based live search; right now it only reacts to typing and
//       submitting the form.
const searchForm = document.querySelector("[data-site-search]");

if (searchForm) {
  const input = searchForm.querySelector('input[type="search"]');
  const submitButton = searchForm.querySelector('button[type="submit"]');
  const status = searchForm.querySelector("[data-search-status]");

  function setState(state, message = "") {
    searchForm.dataset.searchState = state || "";
    status.textContent = message;
  }

  input.addEventListener("input", () => {
    searchForm.removeAttribute("aria-busy");
    submitButton.disabled = false;
    setState(input.value.trim() === "" ? "empty" : null, input.value.trim() === "" ? "Search is empty." : "");
  });

  input.addEventListener("invalid", () => {
    setState("error", "Enter a product name before searching.");
  });

  searchForm.addEventListener("submit", () => {
    searchForm.setAttribute("aria-busy", "true");
    submitButton.disabled = true;
    setState("loading", "Searching the collection.");
  });

  searchForm.addEventListener("search:error", (event) => {
    searchForm.removeAttribute("aria-busy");
    submitButton.disabled = false;
    setState("error", event.detail?.message || "Search is unavailable. Please try again.");
  });
}
