<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Reusable view fragment for Report Definitions.
 *
 * DATA FLOW: A parent view prepares the variables this fragment expects and requires it in the same PHP scope. This is a partial, not a page that should be opened directly.
 */
?>
<!-- SECTION PURPOSE: Group the Calculation definitions content, states, and controls as one accessible page region. -->
<section class="admin-panel admin-report-definitions" aria-labelledby="report-definitions-title">
  <header class="admin-panel__header"><div><h2 id="report-definitions-title">Calculation definitions</h2></div></header>
  <dl><?php foreach ($reportResult['definitions'] as $key => $definition): ?><div><dt><?php echo $escape($humanize($key)); ?></dt><dd><?php echo $escape($definition); ?></dd></div><?php endforeach; ?></dl>
</section>
