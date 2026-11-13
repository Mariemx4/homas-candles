<?php

declare(strict_types=1);


?>
<section class="admin-panel admin-report-definitions" aria-labelledby="report-definitions-title">
  <header class="admin-panel__header"><div><h2 id="report-definitions-title">Calculation definitions</h2></div></header>
  <dl><?php foreach ($reportResult['definitions'] as $key => $definition): ?><div><dt><?php echo $escape($humanize($key)); ?></dt><dd><?php echo $escape($definition); ?></dd></div><?php endforeach; ?></dl>
</section>
