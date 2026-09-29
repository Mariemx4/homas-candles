<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Page view for Admin Settings; it turns prepared application data into HTML.
 *
 * DATA FLOW: An HTTP entry point loads a bootstrap, obtains data from a controller, selects this file as $contentView, and loads a layout. Layout variables such as $escape are therefore injected into this view scope.
 */
?>
<header class="admin-page-header admin-page-header--detail"><div><h1>Settings</h1><p class="admin-page-header__summary">Only configuration backed by explicit persistence, validation, permissions, and audit rules can be changed here.</p></div></header>

<div class="admin-settings-grid">
  <!-- SECTION PURPOSE: Group the panel content, states, and controls as one accessible page region. -->
  <section class="admin-panel"><header class="admin-panel__header"><div><p class="admin-eyebrow">Store configuration</p><h2>Schema-authoritative values</h2></div><span class="admin-badge admin-badge--neutral">Read only</span></header><div class="admin-settings-copy"><p>The current schema authoritatively fixes currency to TND and validates order, payment, delivery, catalogue, and inventory states. It has no general store-settings table or administrator audit trail for configuration changes.</p><p>No tracked configuration file or environment value is displayed or editable from this page.</p></div></section>

  <!-- SECTION PURPOSE: Group the panel content, states, and controls as one accessible page region. -->
  <section class="admin-panel"><header class="admin-panel__header"><div><p class="admin-eyebrow">Administrators</p><h2>Account management</h2></div><span class="admin-badge admin-badge--neutral">Unavailable</span></header><div class="admin-settings-copy"><p>The administrator schema defines active states but not roles, permissions, or an administrator-management audit history. Creation, password reset, and deactivation controls are therefore withheld.</p><p>There is no public administrator registration route.</p></div></section>

  <!-- SECTION PURPOSE: Group the panel content, states, and controls as one accessible page region. -->
  <section class="admin-panel admin-settings-backup"><header class="admin-panel__header"><div><p class="admin-eyebrow">Database protection</p><h2>Backup mechanism</h2></div><?php if ($settingsCapabilities['backupMechanismAvailable'] && $settingsCapabilities['backupStorageProtected']): ?><span class="admin-badge admin-badge--success">Available</span><?php else: ?><span class="admin-badge admin-badge--danger">Check required</span><?php endif; ?></header><div class="admin-settings-copy"><p>Backups remain an operator-only command-line task. The existing mechanism uses a transactional database dump and checksum and stores output outside the public web root.</p><code><?php echo $escape($settingsCapabilities['backupCommand']); ?></code><p>No browser control is provided because the project has no audited backup-job model. Backup filenames, server paths, credentials, and command output are not exposed here.</p></div></section>
</div>
