// These header panels are nonmodal: focus may leave them, which closes them.
// Desktop widths keep the categories panel open beside the page (see header.css).
const desktopQuery = window.matchMedia('(min-width: 1024px)');

const drawerDefinitions = [
  ['.category-menu-trigger', '#categories-drawer', '.close-category-menu'],
  ['.account-menu-trigger', '#account-drawer', '.account-drawer-button'],
];

let activeDrawer = null;

function closeDrawer(drawer, restoreFocus = false) {
  if (!drawer || drawer.panel.hidden) return;

  drawer.panel.hidden = true;
  drawer.trigger.setAttribute('aria-expanded', 'false');
  if (activeDrawer === drawer) activeDrawer = null;

  if (restoreFocus && drawer.trigger.isConnected) drawer.trigger.focus();
}

function openDrawer(drawer) {
  if (activeDrawer && activeDrawer !== drawer) closeDrawer(activeDrawer);

  drawer.panel.hidden = false;
  drawer.trigger.setAttribute('aria-expanded', 'true');
  activeDrawer = drawer;
  drawer.closeButton.focus();
}

for (const [triggerSelector, panelSelector, closeSelector] of drawerDefinitions) {
  const trigger = document.querySelector(triggerSelector);
  const panel = document.querySelector(panelSelector);
  const closeButton = document.querySelector(closeSelector);
  if (!trigger || !panel || !closeButton) continue;

  const drawer = { trigger, panel, closeButton };
  trigger.addEventListener('click', () => {
    if (panel === document.querySelector('#categories-drawer') && desktopQuery.matches) return;
    if (panel.hidden) openDrawer(drawer);
    else closeDrawer(drawer, true);
  });
  closeButton.addEventListener('click', () => closeDrawer(drawer, true));
}

document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape' && activeDrawer) {
    event.preventDefault();
    closeDrawer(activeDrawer, true);
  }
});

document.addEventListener('focusin', (event) => {
  if (
    activeDrawer &&
    !activeDrawer.panel.contains(event.target) &&
    event.target !== activeDrawer.trigger
  ) {
    closeDrawer(activeDrawer);
  }
});

document.addEventListener('click', (event) => {
  if (
    activeDrawer &&
    !activeDrawer.panel.contains(event.target) &&
    event.target !== activeDrawer.trigger
  ) {
    closeDrawer(activeDrawer);
  }
});

// The categories panel is open on desktop and closed on smaller screens, as the width changes.
function syncCategoriesPanel() {
  const panel = document.querySelector('#categories-drawer');
  const trigger = document.querySelector('.category-menu-trigger');
  if (!panel || !trigger) return;
  if (desktopQuery.matches) {
    if (activeDrawer && activeDrawer.panel === panel) activeDrawer = null;
    panel.hidden = false;
    trigger.setAttribute('aria-expanded', 'true');
  } else {
    panel.hidden = true;
    trigger.setAttribute('aria-expanded', 'false');
  }
}

syncCategoriesPanel();
desktopQuery.addEventListener('change', syncCategoriesPanel);
