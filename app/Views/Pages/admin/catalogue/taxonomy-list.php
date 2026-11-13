<?php

declare(strict_types=1);

use App\View\AdminIcons;
use App\Services\TaxonomyService;

$activeCatalogueTab = $taxonomyKind;
$singular = TaxonomyService::label($taxonomyKind);
$plural = TaxonomyService::label($taxonomyKind, true);
$filters = $taxonomyList['filters'];
?>
<?php require VIEW_PATH . '/Components/Admin/catalogue-tabs.php'; ?>

<header class="admin-page-header">
  <div>
    <p class="admin-eyebrow">Catalogue taxonomy</p>
    <h1><?php echo $escape($plural); ?></h1>
    <p class="admin-page-header__summary">Manage <?php echo $escape(strtolower($plural)); ?> and their English, French, and Arabic names.</p>
  </div>
  <a class="admin-button" href="<?php echo $escape($selfUrl . '?id=new'); ?>">New <?php echo $escape(strtolower($singular)); ?></a>
</header>

<?php if (is_string($taxonomyNotice['success'])): ?>
  <?php $alertTone = 'success';
    $alertTitle = 'Saved';
    $alertMessage = $taxonomyNotice['success'];
    require VIEW_PATH . '/Components/Admin/alert.php'; ?>
<?php endif; ?>
<?php if (isset($taxonomyNotice['errors']['form'])): ?>
  <?php $alertTone = 'error';
        $alertTitle = 'Not saved';
        $alertMessage = $taxonomyNotice['errors']['form'];
        require VIEW_PATH . '/Components/Admin/alert.php'; ?>
<?php endif; ?>
<?php if (is_string($taxonomyList['loadError'])): ?>
  <?php $alertTone = 'error';
            $alertTitle = 'Unable to load ' . strtolower($plural);
            $alertMessage = $taxonomyList['loadError'];
            require VIEW_PATH . '/Components/Admin/alert.php'; ?>
<?php elseif ($taxonomyList['filtersAdjusted']): ?>
  <?php $alertTone = 'warning';
                $alertTitle = 'Some filters were reset';
                $alertMessage = 'One or more unsupported filter or page values were ignored safely.';
                require VIEW_PATH . '/Components/Admin/alert.php'; ?>
<?php endif; ?>

<section class="admin-filter-panel" aria-labelledby="taxonomy-filters-title">
  <div class="admin-filter-panel__heading">
    <h2 id="taxonomy-filters-title">Search and filters</h2>
    <a href="<?php echo $escape($selfUrl); ?>">Clear all</a>
  </div>
  <form class="admin-filter-form" action="<?php echo $escape($selfUrl); ?>" method="get">
    <div class="admin-field admin-field--wide">
      <label for="taxonomy-search">Slug or name</label>
      <input id="taxonomy-search" name="q" type="search" maxlength="100" value="<?php echo $escape($filters['search'] ?? ''); ?>" placeholder="Search <?php echo $escape(strtolower($plural)); ?>">
    </div>
    <div class="admin-field">
      <label for="taxonomy-active">Status</label>
      <select id="taxonomy-active" name="active">
        <option value="">All</option>
        <option value="1" <?php echo $filters['active'] === '1' ? ' selected' : ''; ?>>Active</option>
        <option value="0" <?php echo $filters['active'] === '0' ? ' selected' : ''; ?>>Inactive</option>
      </select>
    </div>
    <div class="admin-filter-form__actions">
      <button class="admin-button" type="submit">Apply filters</button>
    </div>
  </form>
</section>

<section class="admin-panel admin-panel--table" aria-labelledby="taxonomy-results-title">
  <header class="admin-panel__header">
    <div>
      <h2 id="taxonomy-results-title"><?php echo $escape($plural); ?></h2>
    </div>
    <?php if ((int) $taxonomyList['total'] > 0): ?>
      <span class="admin-panel__meta">Page <?php echo $escape($taxonomyList['page']); ?> of <?php echo $escape($taxonomyList['totalPages']); ?></span>
    <?php endif; ?>
  </header>

  <?php if ($taxonomyList['items'] === []): ?>
    <?php
                  $emptyTitle = is_string($taxonomyList['loadError']) ? strtolower($plural) . ' unavailable' : 'No matching ' . strtolower($plural);
      $emptyMessage = is_string($taxonomyList['loadError']) ? 'No data could be displayed safely.' : 'Try clearing filters, or create a new record.';
      require VIEW_PATH . '/Components/Admin/empty-state.php';
      ?>
  <?php else: ?>
    <div class="admin-table-scroll" tabindex="0" aria-label="Scrollable <?php echo $escape(strtolower($plural)); ?> table">
      <table class="admin-table">
        <caption class="admin-visually-hidden">Filtered <?php echo $escape(strtolower($plural)); ?></caption>
        <thead>
          <tr>
            <th scope="col">Name</th>
            <th scope="col">Slug</th><?php if ($taxonomyKind === 'colors'): ?><th scope="col">Swatch</th><?php endif; ?><th scope="col">Status</th>
            <th scope="col">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($taxonomyList['items'] as $item): ?>
            <?php // URL IDIOM: http_build_query safely encodes each query-string value.
              ?>
            <?php $editUrl = $selfUrl . '?' . http_build_query(['id' => $item['id']]); ?>
            <tr>
              <td><a class="admin-table__primary-link" href="<?php echo $escape($editUrl); ?>"><?php echo $escape($item['name']); ?></a></td>
              <td><?php echo $escape($item['slug']); ?></td>
              <?php if ($taxonomyKind === 'colors'): ?>
                <td><?php echo $escape($item['hex_code'] ?: '—'); ?></td>
              <?php endif; ?>
              <td><span class="admin-badge admin-badge--<?php echo ((int) $item['is_active'] === 1) ? 'success' : 'neutral'; ?>"><?php echo ((int) $item['is_active'] === 1) ? 'Active' : 'Inactive'; ?></span></td>
              <td><?php echo AdminIcons::recordActions($editUrl, $taxonomyKind, (int) $item['id'], (string) $item['name']); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <footer class="admin-list-footer" aria-live="polite">
      Showing <?php echo $escape((($taxonomyList['page'] - 1) * $taxonomyList['perPage']) + 1); ?>–<?php echo $escape(min($taxonomyList['page'] * $taxonomyList['perPage'], $taxonomyList['total'])); ?> of <?php echo $escape($taxonomyList['total']); ?>
    </footer>
    <?php
      $currentPage = $taxonomyList['page'];
$totalPages = $taxonomyList['totalPages'];
$paginationBaseUrl = $selfUrl;
$paginationQuery = $taxonomyList['queryState'];
require VIEW_PATH . '/Components/Admin/pagination.php';
?>
  <?php endif; ?>
</section>