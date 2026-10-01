<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Reusable view fragment for Report Summary.
 *
 * DATA FLOW: A parent view prepares the variables this fragment expects and requires it in the same PHP scope. This is a partial, not a page that should be opened directly.
 */

$summary = $reportResult['summary'];
$orders = $summary['orders'];
$payments = $summary['payments'];
$requests = $summary['requests'];
$materials = $summary['materials'];
?>
<!-- SECTION PURPOSE: Group retained financial and activity totals as one accessible page region. -->
<section class="admin-report-metrics" aria-labelledby="report-summary-title">
  <header class="admin-section-heading"><div><h2 id="report-summary-title">Financial and activity totals</h2></div><span><?php echo $escape($filters['start']); ?> through <?php echo $escape($filters['end']); ?></span></header>
  <div class="admin-report-metric-grid">
    <article><span>Retained orders created</span><strong><?php echo $escape($orders['order_count'] ?? 0); ?></strong><small><?php echo $escape($orders['completed_count'] ?? 0); ?> completed, including Trash</small></article>
    <article><span>Completed sales value</span><strong><?php echo $escape($money($orders['completed_value'] ?? 0)); ?></strong><small>Order value, not cash receipt</small></article>
    <article><span>Verified payments</span><strong><?php echo $escape($money($payments['verified_amount'] ?? 0)); ?></strong><small><?php echo $escape($payments['verified_count'] ?? 0); ?> verified records</small></article>
    <article><span>Known item costs</span><strong><?php echo $escape($money($orders['known_cost'] ?? 0)); ?></strong><small><?php echo $escape($orders['missing_cost_items'] ?? 0); ?> items missing cost</small></article>
    <article><span>Estimated gross margin</span><strong><?php echo $escape($money($summary['estimated_profit'])); ?></strong><small>Before overhead and delivery expense</small></article>
    <article><span>Request conversion</span><strong><?php echo $escape($requests['conversion_rate'] ?? '0.0'); ?>%</strong><small><?php echo $escape($requests['converted_count'] ?? 0); ?> of <?php echo $escape($requests['request_count'] ?? 0); ?> requests</small></article>
    <article><span>Material used</span><strong><?php echo $escape($number($materials['used_quantity'] ?? 0)); ?></strong><small><?php echo $escape($money($materials['used_value'] ?? 0)); ?> snapshot value</small></article>
    <article><span>Recorded waste</span><strong><?php echo $escape($number($materials['waste_quantity'] ?? 0)); ?></strong><small><?php echo $escape($money($materials['waste_value'] ?? 0)); ?> snapshot value</small></article>
  </div>
</section>
