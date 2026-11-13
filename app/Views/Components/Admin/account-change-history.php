<?php declare(strict_types=1); ?>
<section class="admin-panel" id="account-history" aria-labelledby="account-history-title">
  <header class="admin-panel__header"><div><h2 id="account-history-title">Account change history</h2><p class="admin-panel__intro">Who changed the profile or access, and what changed. Times use Tunisia time.</p></div></header>
  <?php if ($accountHistory === []): ?><p class="admin-muted">No changes recorded since change history was enabled.</p><?php else: ?>
  <ol class="admin-task-list">
  <?php foreach ($accountHistory as $entry): $before = json_decode($entry['before_state'] ?? 'null', true) ?? [];
      $after = json_decode($entry['after_state'] ?? 'null', true) ?? [];
      $subject = $after ?: $before; ?>
    <li class="admin-history-entry"><p><strong><?php echo $escape(ucfirst(strtolower($entry['action']))); ?></strong> · <?php echo $escape(trim(($subject['first_name'] ?? '') . ' ' . ($subject['last_name'] ?? '')) ?: 'Account #' . $entry['account_id']); ?></p><p class="admin-muted"><?php echo $escape($entry['actor_name']); ?> · <?php echo $escape(\App\Services\BusinessClock::displayTimestamp($entry['changed_at'])); ?></p>
      <details><summary>View changes</summary><dl class="admin-detail-list">
      <?php foreach (array_unique([...array_keys($before),...array_keys($after)]) as $key): if ($key === 'id' || ($before[$key] ?? null) === ($after[$key] ?? null)) {
          continue;
      }$display = static fn ($value) => is_array($value) ? (implode('; ', array_map(static fn (array $address): string => implode(', ', array_filter([(bool)($address['is_primary'] ?? false) ? 'Primary' : 'Additional',$address['address_label'] ?? '',$address['street_address'] ?? '',$address['delegation'] ?? '',$address['governorate'] ?? '',$address['postal_code'] ?? ''])), $value)) ?: 'None') : ($value ?? 'Not set'); ?>
        <div><dt><?php echo $escape(ucwords(str_replace('_', ' ', $key))); ?></dt><dd><span class="admin-muted">Before:</span> <?php echo $escape($display($before[$key] ?? null)); ?><br><span class="admin-muted">After:</span> <?php echo $escape($display($after[$key] ?? null)); ?></dd></div>
      <?php endforeach; ?></dl></details>
    </li>
  <?php endforeach; ?></ol>
  <?php if ($historyPages > 1): ?><nav class="admin-pagination" aria-label="Account history pages"><?php foreach (['Previous' => $historyPage - 1,'Next' => $historyPage + 1] as $label => $page): if ($page < 1 || $page > $historyPages) {
      continue;
  } ?><a class="admin-button admin-button--secondary" href="<?php echo $escape(BASE_URL . 'admin/accounts.php?' . http_build_query(['kind' => $kind,...($raw === null ? [] : ['id' => $id ?? 'new']),'return' => $accountReturn,'history_page' => $page]) . '#account-history'); ?>"><?php echo $label; ?></a><?php endforeach; ?><span>Page <?php echo $historyPage; ?> of <?php echo $historyPages; ?></span></nav><?php endif; ?>
  <?php endif; ?>
</section>
