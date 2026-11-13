<?php

declare(strict_types=1);

use App\View\AdminIcons;

?>
<header class="admin-page-header admin-page-header--detail">
  <div>
    <h1>Settings</h1>
    <p class="admin-page-header__summary">Your shop’s operating rules, team access and data protection.</p>
  </div>
</header>

<div class="admin-settings-grid">
  <section class="admin-panel">
    <header class="admin-panel__header">
      <div>
        <p class="admin-eyebrow">Store configuration</p>
        <h2>How your shop works</h2>
      </div><span class="admin-badge admin-badge--neutral">Read only</span>
    </header>
    <div class="admin-settings-copy">
      <p>Prices and payments use Tunisian dinars (TND). Each order shows its required deposit and remaining balance.</p>
      <p>D17 transfers need a review before they count as paid. Completing pickup or delivery does not record a payment automatically.</p>
      <p>Product pricing follows your configured costing rules. Stock changes are recorded with their history.</p>
    </div>
  </section>

  <section class="admin-panel">
    <header class="admin-panel__header">
      <div>
        <p class="admin-eyebrow">Administrators</p>
        <h2>Account management</h2>
      </div><span class="admin-badge admin-badge--success">Available</span>
    </header>
    <p>Active administrators can manage current profiles and account status. Current and last-active accounts are protected; audit references prevent permanent deletion.</p><a class="admin-button" href="<?php echo $escape(BASE_URL . 'admin/accounts.php'); ?>"><?php echo AdminIcons::render('settings'); ?>Manage administrators</a>
  </section>

  <section class="admin-panel admin-settings-backup">
    <header class="admin-panel__header">
      <div>
        <p class="admin-eyebrow">Database protection</p>
        <h2>Backup & recovery</h2>
      </div><?php if ($settingsCapabilities['backupMechanismAvailable'] && $settingsCapabilities['backupStorageProtected']): ?><span class="admin-badge admin-badge--success">Available</span><?php else: ?><span class="admin-badge admin-badge--danger">Check required</span><?php endif; ?>
    </header>
    <div class="admin-settings-copy">
      <?php if ($settingsCapabilities['lastRestoreCheck'] !== null): ?><p>A PostgreSQL archive was restored into a disposable database and its table contents matched the exported snapshot.</p><p>Last restore check: <strong><?php echo $escape(\App\Services\BusinessClock::displayTimestamp($settingsCapabilities['lastRestoreCheck']['verified_at'])); ?></strong> (Tunisia time).</p><?php else: ?><p>No successful restore check is recorded yet. Create and verify a backup before relying on it for recovery.</p><?php endif; ?>
      <details><summary>Backup instructions</summary><p>Run <code>php scripts/backup-database.php --restore-check</code> in the application container. Archives stay in private storage. The check creates and removes a separate database; it does not replace shop data. Schedule regular backups and keep an additional copy outside this computer.</p></details>
    </div>
  </section>
</div>
