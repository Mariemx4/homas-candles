(() => {
  const tracking = document.querySelector('[data-order-tracking]');
  if (!tracking) return;
  let running = false;
  const refresh = async () => {
    if (document.hidden || running) return;
    running = true;
    try {
      const response = await fetch(tracking.dataset.url, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' },
      });
      if (!response.ok) throw new Error('Tracking unavailable');
      const state = await response.json();
      document.querySelector('[data-order-status]').textContent = state.status;
      const history = tracking.querySelector('[data-order-history]');
      history.replaceChildren(
        ...state.history.map((event) => {
          const entry = document.createElement('li');
          const status = document.createElement('strong');
          const date = document.createElement('time');
          status.textContent = event.status;
          date.textContent = event.date;
          entry.append(status, ' ', date);
          return entry;
        }),
      );
      document.querySelectorAll('[data-order-figure]').forEach((node) => {
        node.textContent = state.figures[node.dataset.orderFigure];
      });
      if (state.version !== tracking.dataset.version) {
        tracking.querySelector('[data-order-changed]').hidden = false;
        const actions = document.querySelector('[data-order-actions]');
        if (actions) actions.hidden = true;
      }
      tracking.querySelector('[data-tracking-error]').hidden = true;
    } catch (_) {
      tracking.querySelector('[data-tracking-error]').hidden = false;
    } finally {
      running = false;
    }
  };
  window.setInterval(refresh, 15000);
  document.addEventListener('visibilitychange', refresh);
})();
