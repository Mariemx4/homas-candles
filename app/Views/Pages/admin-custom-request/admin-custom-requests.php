<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Page view for Admin Custom Requests; it turns prepared application data into HTML.
 *
 * DATA FLOW: An HTTP entry point loads a bootstrap, obtains data from a controller, selects this file as $contentView, and loads a layout. Layout variables such as $escape are therefore injected into this view scope.
 */

use App\Services\CustomRequestService;

$filters = $requestList['filters'];
$humanize = static fn (mixed $value): string => ucwords(strtolower(str_replace('_', ' ', (string) $value)));
$formatDate = static function (mixed $value): string {
    if (!is_string($value) || $value === '') {
        return 'Not specified';
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date instanceof DateTimeImmutable ? $date->format('d M Y') : 'Not specified';
};
?>
<header class="admin-page-header admin-page-header--orders">
  <div><h1>Custom Requests</h1><p class="admin-page-header__summary">Prioritize enquiries, follow up, and turn agreed work into traceable manual orders.</p></div>
  <div class="admin-detail-actions"><a class="admin-button" href="<?php echo BASE_URL . 'admin/requests/create.php'; ?>">Add custom request</a><div class="admin-result-summary" aria-live="polite"><strong><?php echo $escape($requestList['total']); ?></strong><span>matching <?php echo (int) $requestList['total'] === 1 ? 'request' : 'requests'; ?></span></div></div>
</header>

<?php if (is_string($requestList['loadError'])): ?>
  <?php $alertTone = 'error'; $alertTitle = 'Unable to load requests'; $alertMessage = $requestList['loadError']; require __DIR__ . '/../../Components/Admin/alert.php'; ?>
<?php elseif ($requestList['filtersAdjusted']): ?>
  <?php $alertTone = 'warning'; $alertTitle = 'Some filters were reset'; $alertMessage = 'Unsupported search, filter, date, or page values were ignored safely.'; require __DIR__ . '/../../Components/Admin/alert.php'; ?>
<?php endif; ?>

<section class="admin-filter-panel" aria-labelledby="request-filters-title">
  <div class="admin-filter-panel__heading"><h2 id="request-filters-title">Search and filters</h2><a href="<?php echo BASE_URL . 'admin/requests/index.php'; ?>">Clear all</a></div>
  <!-- FORM PURPOSE: Submit filter form with GET; the receiving PHP route must validate the values before using them. -->
  <form class="admin-filter-form" action="<?php echo BASE_URL . 'admin/requests/index.php'; ?>" method="get">
    <div class="admin-field admin-field--wide"><label for="request-search">Request number or customer</label><input id="request-search" name="q" type="search" maxlength="100" value="<?php echo $escape($filters['search'] ?? ''); ?>"></div>
    <div class="admin-field"><label for="request-status">Status</label><select id="request-status" name="status"><option value="">All statuses</option><?php foreach (CustomRequestService::STATUSES as $option): ?><option value="<?php echo $escape($option); ?>"<?php echo $filters['status'] === $option ? ' selected' : ''; ?>><?php echo $escape($humanize($option)); ?></option><?php endforeach; ?></select></div>
    <div class="admin-field"><label for="request-category">Category</label><select id="request-category" name="category"><option value="">All categories</option><?php foreach ($requestList['categories'] as $category): ?><option value="<?php echo $escape($category['id']); ?>"<?php echo (string) $filters['category'] === (string) $category['id'] ? ' selected' : ''; ?>><?php echo $escape($category['name']); ?></option><?php endforeach; ?></select></div>
    <div class="admin-field"><label for="request-admin">Assigned administrator</label><select id="request-admin" name="administrator"><option value="">Anyone</option><option value="unassigned"<?php echo $filters['administrator'] === 'unassigned' ? ' selected' : ''; ?>>Unassigned</option><?php foreach ($requestList['administrators'] as $admin): ?><option value="<?php echo $escape($admin['id']); ?>"<?php echo (string) $filters['administrator'] === (string) $admin['id'] ? ' selected' : ''; ?>><?php echo $escape($admin['name']); ?></option><?php endforeach; ?></select></div>
    <div class="admin-field"><label for="request-date-from">Required from</label><input id="request-date-from" name="date_from" type="date" value="<?php echo $escape($filters['date_from'] ?? ''); ?>"></div>
    <div class="admin-field"><label for="request-date-to">Required through</label><input id="request-date-to" name="date_to" type="date" value="<?php echo $escape($filters['date_to'] ?? ''); ?>"></div>
    <div class="admin-filter-form__actions"><button class="admin-button" type="submit">Apply filters</button></div>
  </form>
</section>

<section class="admin-panel admin-panel--table admin-order-results" aria-labelledby="request-results-title">
  <header class="admin-panel__header"><div><h2 id="request-results-title">Request inbox</h2></div><?php if ($requestList['total'] > 0): ?><span class="admin-panel__meta">Page <?php echo $escape($requestList['page']); ?> of <?php echo $escape($requestList['totalPages']); ?></span><?php endif; ?></header>
  <?php if ($requestList['requests'] === []): ?>
    <?php $emptyTitle = is_string($requestList['loadError']) ? 'Requests unavailable' : 'No matching requests'; $emptyMessage = is_string($requestList['loadError']) ? 'No request data could be displayed safely.' : 'Try clearing or changing the current filters.'; require __DIR__ . '/../../Components/Admin/empty-state.php'; ?>
  <?php else: ?>
    <div class="admin-table-scroll" tabindex="0" aria-label="Scrollable custom requests table"><table class="admin-table admin-requests-table"><caption class="admin-visually-hidden">Filtered custom requests</caption><thead><tr><th scope="col">Request</th><th scope="col">Customer</th><th scope="col">Category</th><th scope="col">Required</th><th scope="col">Assigned</th><th scope="col">Status</th><th scope="col">Attention</th></tr></thead><tbody>
      <?php // URL IDIOM: http_build_query safely encodes each query-string value. ?>
      <?php foreach ($requestList['requests'] as $request): $detailUrl = BASE_URL . 'admin/requests/detail.php?' . http_build_query(['id' => $request['id'], 'return' => $requestList['returnQuery']]); ?>
        <tr><td><a class="admin-table__primary-link" href="<?php echo $escape($detailUrl); ?>"><?php echo $escape($request['request_number']); ?></a><small>Received <?php echo $escape(substr((string) $request['created_at'], 0, 10)); ?></small></td><td><?php echo $escape($request['customer_name_snapshot']); ?></td><td><?php echo $escape($request['category_name']); ?></td><td><?php echo $escape($formatDate($request['required_date'])); ?></td><td><?php echo $escape($request['administrator_name'] ?? 'Unassigned'); ?></td><td><?php $status = $request['request_status']; require __DIR__ . '/../../Components/Admin/status-badge.php'; ?></td><td><span class="admin-badge admin-badge--<?php echo $escape($request['attention']['tone']); ?>"><?php echo $escape($request['attention']['label']); ?></span></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <footer class="admin-order-results__footer"><span>Showing <?php echo $escape((($requestList['page'] - 1) * $requestList['perPage']) + 1); ?>–<?php echo $escape(min($requestList['page'] * $requestList['perPage'], $requestList['total'])); ?> of <?php echo $escape($requestList['total']); ?></span><?php $currentPage = $requestList['page']; $totalPages = $requestList['totalPages']; $paginationBaseUrl = BASE_URL . 'admin/requests/index.php'; $paginationQuery = $requestList['queryState']; require __DIR__ . '/../../Components/Admin/pagination.php'; ?></footer>
  <?php endif; ?>
</section>
