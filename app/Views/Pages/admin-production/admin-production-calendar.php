<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Page view for Admin Production Calendar; it turns prepared application data into HTML.
 *
 * DATA FLOW: An HTTP entry point loads a bootstrap, obtains data from a controller, selects this file as $contentView, and loads a layout. Layout variables such as $escape are therefore injected into this view scope.
 */

use App\Services\ProductionService;

$filters = $productionCalendar['filters'];
$filterOptions = $productionCalendar['filterOptions'];
$filterQuery = $productionCalendar['filterQuery'];
$humanize = static fn (mixed $value): string => ucwords(strtolower(str_replace('_', ' ', (string) $value)));
$weekdayLabels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
?>
<header class="admin-page-header">
  <div>
    <h1>Production calendar</h1>
    <p class="admin-page-header__summary">Confirmed and preparing orders grouped by required date.</p>
  </div>
</header>

<?php require __DIR__ . '/../../Components/Admin/production-tabs.php'; ?>

<?php if (is_string($productionCalendar['loadError'])): ?>
  <?php
    $alertTone = 'error';
    $alertTitle = 'Unable to load the production calendar';
    $alertMessage = $productionCalendar['loadError'];
    require __DIR__ . '/../../Components/Admin/alert.php';
  ?>
<?php elseif ($productionCalendar['filtersAdjusted']): ?>
  <?php
    $alertTone = 'warning';
    $alertTitle = 'Some filters were reset';
    $alertMessage = 'One or more unsupported filter or month values were ignored safely.';
    require __DIR__ . '/../../Components/Admin/alert.php';
  ?>
<?php endif; ?>

<!-- SECTION PURPOSE: Group the Filters content, states, and controls as one accessible page region. -->
<section class="admin-filter-panel" aria-labelledby="calendar-filters-title">
  <div class="admin-filter-panel__heading">
    <h2 id="calendar-filters-title">Filters</h2>
    <?php // URL IDIOM: http_build_query safely encodes each query-string value. ?>
    <a href="<?php echo BASE_URL . 'admin/production/calendar.php?' . http_build_query(['month' => $productionCalendar['month']]); ?>">Clear filters</a>
  </div>
  <!-- FORM PURPOSE: Submit filter form with GET; the receiving PHP route must validate the values before using them. -->
  <form class="admin-filter-form" action="<?php echo BASE_URL . 'admin/production/calendar.php'; ?>" method="get">
    <input type="hidden" name="month" value="<?php echo $escape($productionCalendar['month']); ?>">
    <div class="admin-field">
      <label for="calendar-status">Status</label>
      <select id="calendar-status" name="status">
        <option value="">All queue statuses</option>
        <?php foreach (ProductionService::STATUSES as $statusOption): ?>
          <option value="<?php echo $escape($statusOption); ?>"<?php echo $filters['status'] === $statusOption ? ' selected' : ''; ?>><?php echo $escape($humanize($statusOption)); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="admin-field">
      <label for="calendar-method">Fulfilment</label>
      <select id="calendar-method" name="method">
        <option value="">All methods</option>
        <?php foreach (ProductionService::METHODS as $methodOption): ?>
          <option value="<?php echo $escape($methodOption); ?>"<?php echo $filters['method'] === $methodOption ? ' selected' : ''; ?>><?php echo $escape($humanize($methodOption)); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="admin-field">
      <label for="calendar-product">Product</label>
      <select id="calendar-product" name="product">
        <option value="">All products</option>
        <?php foreach ($filterOptions['products'] as $product): ?>
          <option value="<?php echo $escape($product['id']); ?>"<?php echo (string) $filters['product_id'] === (string) $product['id'] ? ' selected' : ''; ?>><?php echo $escape($product['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="admin-field">
      <label for="calendar-scent">Scent</label>
      <select id="calendar-scent" name="scent">
        <option value="">All scents</option>
        <?php foreach ($filterOptions['scents'] as $scent): ?>
          <option value="<?php echo $escape($scent['id']); ?>"<?php echo (string) $filters['scent_id'] === (string) $scent['id'] ? ' selected' : ''; ?>><?php echo $escape($scent['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="admin-field">
      <label for="calendar-color">Color</label>
      <select id="calendar-color" name="color">
        <option value="">All colors</option>
        <?php foreach ($filterOptions['colors'] as $color): ?>
          <option value="<?php echo $escape($color['id']); ?>"<?php echo (string) $filters['color_id'] === (string) $color['id'] ? ' selected' : ''; ?>><?php echo $escape($color['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="admin-filter-form__actions">
      <button class="admin-button" type="submit">Apply filters</button>
    </div>
  </form>
</section>

<!-- SECTION PURPOSE: Group the related page content content, states, and controls as one accessible page region. -->
<section class="admin-panel admin-calendar-panel" aria-labelledby="calendar-title">
  <header class="admin-calendar-nav">
    <?php // URL IDIOM: http_build_query safely encodes each query-string value. ?>
    <a class="admin-button admin-button--secondary" href="<?php echo $escape(BASE_URL . 'admin/production/calendar.php?' . http_build_query(['month' => $productionCalendar['previousMonth']] + $filterQuery)); ?>">← Previous month</a>
    <h2 id="calendar-title"><?php echo $escape($productionCalendar['monthLabel']); ?></h2>
    <?php // URL IDIOM: http_build_query safely encodes each query-string value. ?>
    <a class="admin-button admin-button--secondary" href="<?php echo $escape(BASE_URL . 'admin/production/calendar.php?' . http_build_query(['month' => $productionCalendar['nextMonth']] + $filterQuery)); ?>">Next month →</a>
  </header>

  <?php if ($productionCalendar['weeks'] === []): ?>
    <?php
      $emptyTitle = 'Calendar unavailable';
      $emptyMessage = 'No calendar data could be displayed safely.';
      require __DIR__ . '/../../Components/Admin/empty-state.php';
    ?>
  <?php else: ?>
    <div class="admin-calendar-weekdays" aria-hidden="true">
      <?php foreach ($weekdayLabels as $weekdayLabel): ?>
        <span><?php echo $escape($weekdayLabel); ?></span>
      <?php endforeach; ?>
    </div>
    <div class="admin-calendar-grid" role="grid" aria-label="Production calendar for <?php echo $escape($productionCalendar['monthLabel']); ?>">
      <?php foreach ($productionCalendar['weeks'] as $week): ?>
        <?php foreach ($week as $day): ?>
          <?php
            $dayClasses = 'admin-calendar-day';
            $dayClasses .= $day['inMonth'] ? '' : ' admin-calendar-day--outside';
            $dayClasses .= $day['isToday'] ? ' admin-calendar-day--today' : '';
            $dayClasses .= $day['isOverdue'] ? ' admin-calendar-day--overdue' : '';
            // URL IDIOM: Encode an associative array into a valid query string instead of concatenating raw parameter text.
            $dayUrl = BASE_URL . 'admin/production/queue.php?' . http_build_query(['date_from' => $day['date'], 'date_to' => $day['date']] + $filterQuery);
          ?>
          <div class="<?php echo $escape($dayClasses); ?>" role="gridcell">
            <span class="admin-calendar-day__number"><?php echo $escape($day['day']); ?><?php echo $day['isToday'] ? '<span class="admin-visually-hidden"> (today)</span>' : ''; ?></span>
            <?php if ($day['count'] > 0): ?>
              <a class="admin-calendar-day__count" href="<?php echo $escape($dayUrl); ?>">
                <span class="admin-badge admin-badge--<?php echo $day['isOverdue'] ? 'danger' : 'info'; ?>">
                  <?php echo $escape($day['count']); ?> <?php echo $day['count'] === 1 ? 'order' : 'orders'; ?>
                </span>
                <?php if ($day['isOverdue']): ?><small>Past due</small><?php endif; ?>
              </a>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
