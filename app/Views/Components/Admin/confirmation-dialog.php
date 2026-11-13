<?php

declare(strict_types=1); ?>
<dialog class="admin-confirmation-dialog" data-admin-confirmation aria-labelledby="admin-confirmation-title">
  <header class="admin-confirmation-dialog__header">
    <h2 id="admin-confirmation-title">Confirm action</h2>
    <button class="admin-button admin-button--ghost admin-button--icon" type="button" data-confirmation-close aria-label="Close confirmation" title="Close">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
        <path d="m6 6 12 12M18 6 6 18" />
      </svg>
    </button>
  </header>
  <div class="admin-confirmation-dialog__body" data-confirmation-body></div>
</dialog>