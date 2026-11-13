<?php

declare(strict_types=1);

/**
 * Safe fallback used when an admin route points at a missing page view.
 *
 * The missing filesystem path is deliberately not printed: server paths are
 * useful to developers but should not be disclosed in the browser.
 */
?>
<section class="admin-panel" role="alert" aria-labelledby="admin-view-error-title">
  <header class="admin-panel__header">
    <div>
      <p class="admin-eyebrow">Configuration error</p>
      <h1 id="admin-view-error-title">This admin page could not be loaded</h1>
    </div>
  </header>
  <p>The page is temporarily unavailable. Check the server log for the missing view configuration.</p>
</section>