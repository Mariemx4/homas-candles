// WHAT: keeps two CSS variables in sync with the real, current size of the
//       fixed header bar and the categories drawer header.
// WHY:  the header is `position: fixed`, so other elements (main's
//       padding-top, the floating nav pill, the account panel — see
//       components/header.css) need to know its actual height to avoid
//       overlapping it. That height changes with viewport width (the
//       mobile layout is taller), so this recalculates on every resize via
//       ResizeObserver instead of hardcoding a pixel value.
const headerTop = document.querySelector('.header-top');

if (headerTop) {
  /** FUNCTION PURPOSE: Recalculate and render header spacing from the current inputs/state. */
  function updateHeaderSpacing() {
    const headerHeight = headerTop.getBoundingClientRect().height;
    document.documentElement.style.setProperty('--header-top-height', `${headerHeight}px`);
  }

  const headerResizeObserver = new ResizeObserver(updateHeaderSpacing);

  headerResizeObserver.observe(headerTop);
  updateHeaderSpacing();
}

const headerDrawer = document.querySelector('.drawer-header');

if (headerDrawer) {
  /** FUNCTION PURPOSE: Recalculate and render header drawer spacing from the current inputs/state. */
  function updateHeaderDrawerSpacing() {
    const headerDrawerWidth = headerDrawer.getBoundingClientRect().width;
    document.documentElement.style.setProperty('--header-drawer-width', `${headerDrawerWidth}px`);
  }

  const headerDrawerResizeObserver = new ResizeObserver(updateHeaderDrawerSpacing);

  headerDrawerResizeObserver.observe(headerDrawer);
  updateHeaderDrawerSpacing();
}
