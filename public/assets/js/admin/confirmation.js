'use strict';
(() => {
  const dialog = document.querySelector('[data-admin-confirmation]');
  if (!dialog || typeof dialog.showModal !== 'function') return;
  const body = dialog.querySelector('[data-confirmation-body]');
  const title = dialog.querySelector('#admin-confirmation-title');
  const closeButton = dialog.querySelector('[data-confirmation-close]');
  const destructiveActions = new Set([
    'delete',
    'archive',
    'deactivate',
    'remove_line',
    'remove_color',
    'remove_scent',
    'remove_component',
    'remove_quantity_price',
  ]);
  const unsavedRemovals = '[data-remove-item], [data-remove-component], [data-remove-custom]';
  let opener,
    loading,
    busy = false,
    refreshOnClose = false,
    destination = null;
  let pendingConfirmation = null;
  const approved = new WeakSet();
  const route = (href) => {
    const url = new URL(href, location.href);
    return url.origin === location.origin &&
      /\/admin\/(?:lifecycle|orders\/delete)\.php$/.test(url.pathname)
      ? url
      : null;
  };
  const message = (text, tone = 'error') => {
    body.querySelector('[data-confirmation-message]')?.remove();
    const alert = document.createElement('div');
    alert.className = 'admin-alert admin-alert--' + tone;
    alert.dataset.confirmationMessage = '';
    alert.setAttribute('role', tone === 'error' ? 'alert' : 'status');
    alert.textContent = text;
    body.prepend(alert);
  };
  const open = (trigger, label) => {
    loading?.abort();
    opener = trigger;
    busy = false;
    refreshOnClose = false;
    destination = null;
    pendingConfirmation = null;
    title.textContent = label;
    body.replaceChildren();
    if (!dialog.open) dialog.showModal();
    closeButton.focus();
  };
  const close = () => {
    if (busy) return;
    dialog.close();
    if (!refreshOnClose && opener?.isConnected) opener.focus();
  };
  const button = (label, classes, callback) => {
    const el = document.createElement('button');
    el.type = 'button';
    el.className = 'admin-button ' + classes;
    el.textContent = label;
    el.addEventListener('click', callback);
    return el;
  };
  const confirm = (trigger, label, explanation, action) => {
    open(trigger, label);
    pendingConfirmation = action;
    const p = document.createElement('p');
    p.textContent = explanation;
    const actions = document.createElement('div');
    actions.className = 'admin-confirmation-dialog__actions';
    actions.append(
      button('Cancel', 'admin-button--secondary', close),
      button(label, 'admin-button--warning', () => {
        const run = pendingConfirmation;
        pendingConfirmation = null;
        close();
        run?.();
      }),
    );
    body.append(p, actions);
  };
  const load = async (trigger, url) => {
    open(trigger, /\/orders\/delete\.php$/.test(url.pathname) ? 'Delete order' : 'Manage record');
    message('Loading record details…', 'info');
    const request = new AbortController();
    loading = request;
    try {
      const response = await fetch(url.href, {
        credentials: 'same-origin',
        signal: request.signal,
      });
      const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
      if (request.signal.aborted || !dialog.open) return;
      const content = doc.querySelector('[data-admin-confirmation-content]');
      if (!response.ok || !content)
        throw Error('Record details could not be loaded. Close this window and try again.');
      title.textContent = content.querySelector('h1')?.textContent || 'Confirm action';
      content.querySelector('.admin-page-header')?.remove();
      content.querySelectorAll('script').forEach((el) => el.remove());
      content.querySelectorAll('form').forEach((form) => {
        form.setAttribute(
          'action',
          new URL(form.getAttribute('action') || url.href, url.href).href,
        );
      });
      body.replaceChildren(...content.childNodes);
      closeButton.focus();
    } catch (error) {
      if (!request.signal.aborted) message(error.message);
    }
  };
  document.addEventListener(
    'click',
    (event) => {
      if (
        event.defaultPrevented ||
        event.button !== 0 ||
        event.ctrlKey ||
        event.metaKey ||
        event.shiftKey ||
        event.altKey
      )
        return;
      const link = event.target.closest('a[href]');
      if (link && dialog.contains(link)) {
        event.preventDefault();
        close();
        return;
      }
      if (link && !link.hasAttribute('download') && (!link.target || link.target === '_self')) {
        const url = route(link.href);
        if (url) {
          event.preventDefault();
          load(link, url);
          return;
        }
      }
      const remove = event.target.closest(unsavedRemovals);
      if (!remove || remove.disabled) return;
      if (approved.delete(remove)) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      const label = remove.textContent.trim() || 'Remove item';
      confirm(remove, label, label + '? This removes it from the current form.', () => {
        approved.add(remove);
        remove.click();
      });
    },
    true,
  );
  document.addEventListener(
    'submit',
    async (event) => {
      const form = event.target;
      if (!(form instanceof HTMLFormElement)) return;
      if (!dialog.contains(form)) {
        if (approved.delete(form)) return;
        const submitter = event.submitter;
        const action =
          submitter?.name === 'action' ? submitter.value : new FormData(form).get('action');
        const cancellation =
          form.matches('[data-order-status-form]') &&
          new FormData(form).get('new_status') === 'CANCELLED';
        const endpoint = route(form.getAttribute('action') || location.href);
        if (!destructiveActions.has(action) && !endpoint && !cancellation) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        const label = cancellation
          ? 'Cancel order'
          : submitter?.textContent.trim() || 'Confirm removal';
        const explanation = cancellation
          ? 'Cancel this order before production? The order will stay closed. Recorded payments are retained and need separate refund review. Stock is unchanged.'
          : label + '? Review this change before continuing.';
        confirm(submitter || form, label, explanation, () => {
          approved.add(form);
          submitter ? form.requestSubmit(submitter) : form.requestSubmit();
        });
        return;
      }
      event.preventDefault();
      if (busy || refreshOnClose) return;
      const endpoint = route(form.getAttribute('action') || location.href);
      if (!endpoint) {
        message('This action is unavailable. Close this window and reload the page.');
        return;
      }
      const submitter = event.submitter;
      const data = new FormData(form);
      if (submitter?.name) data.append(submitter.name, submitter.value);
      busy = true;
      dialog.setAttribute('aria-busy', 'true');
      closeButton.disabled = true;
      const controls = [...body.querySelectorAll('button, input, select, textarea')];
      const enabled = controls.filter((el) => !el.disabled);
      enabled.forEach((el) => {
        el.disabled = true;
      });
      try {
        const response = await fetch(endpoint.href, {
          method: 'POST',
          body: data,
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
        });
        if (!response.ok)
          throw Error(
            'The action could not be confirmed. Close this window and review the record again.',
          );
        const result = await response.json();
        body.querySelectorAll('[name="csrf_token"]').forEach((el) => {
          el.value = result.csrf_token;
        });
        if (!result.success) {
          enabled.forEach((el) => {
            el.disabled = false;
          });
          message(result.message);
        } else {
          refreshOnClose = true;
          const current = new URL(location.href).searchParams;
          if (
            result.removed &&
            (current.get('id') || current.get('edit')) === String(result.record_id)
          )
            destination = result.redirect_url;
          body.replaceChildren();
          message(result.message, 'success');
          const actions = document.createElement('div');
          actions.className = 'admin-confirmation-dialog__actions';
          const done = button('Done', '', close);
          actions.append(done);
          body.append(actions);
          done.focus();
        }
      } catch (error) {
        // A lost response may have followed a successful write; do not offer a blind retry.
        refreshOnClose = true;
        message(error.message);
      } finally {
        busy = false;
        dialog.removeAttribute('aria-busy');
        closeButton.disabled = false;
      }
    },
    true,
  );
  closeButton.addEventListener('click', close);
  dialog.addEventListener('cancel', (event) => {
    if (busy) event.preventDefault();
  });
  dialog.addEventListener('click', (event) => {
    if (event.target !== dialog) return;
    const rect = dialog.getBoundingClientRect();
    if (
      event.clientX < rect.left ||
      event.clientX > rect.right ||
      event.clientY < rect.top ||
      event.clientY > rect.bottom
    )
      close();
  });
  dialog.addEventListener('close', () => {
    loading?.abort();
    pendingConfirmation = null;
    if (refreshOnClose) {
      destination ? location.assign(destination) : location.reload();
      return;
    }
    if (opener?.isConnected) opener.focus();
  });
})();
