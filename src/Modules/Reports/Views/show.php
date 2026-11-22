<div class="page-head"><div><h1><?= $e($table->title) ?></h1><p class="muted"><?= $e($table->subtitle) ?></p></div>
  <div class="actions"><a class="btn btn-ghost" href="<?= $e($url('/reports')) ?>">Volver</a>
  <?php if ($can('reports.export')): foreach ($formats as $f => $label): ?>
    <a class="btn btn-ghost" href="<?= $e($url('/reports/' . $key . '?format=' . $f . ($def['ranged'] ? '&from=' . $from . '&to=' . $to : ''))) ?>"><?= $e($label) ?></a>
  <?php endforeach; endif; ?></div></div>
<?php if ($def['ranged']): ?>
<form class="toolbar" method="get" action="<?= $e($url('/reports/' . $key)) ?>"><input class="input" type="date" name="from" value="<?= $e($from) ?>" aria-label="Desde"><input class="input" type="date" name="to" value="<?= $e($to) ?>" aria-label="Hasta"><button class="btn btn-ghost" type="submit">Ver</button></form>
<?php endif; ?>
<div class="card table-wrap">
<?php if ($table->rows === []): ?><p class="empty-msg muted">Sin datos para este período.</p><?php else: ?>
  <table class="table"><thead><tr><?php foreach ($table->columns as $c): ?><th<?= \Bic\Core\Export\Table::isNumeric($table->type($c)) ? ' class="right"' : '' ?>><?= $e($c['label']) ?></th><?php endforeach; ?></tr></thead><tbody>
  <?php foreach ($table->rows as $r): ?><tr><?php foreach ($table->columns as $c): ?><td<?= \Bic\Core\Export\Table::isNumeric($table->type($c)) ? ' class="right"' : '' ?>><?= $e($table->display($c, $r[$c['key']] ?? null)) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
  <?php if ($table->totals !== null): ?><tfoot><tr><?php foreach ($table->columns as $c): ?><th<?= \Bic\Core\Export\Table::isNumeric($table->type($c)) ? ' class="right"' : '' ?>><?= $e($table->display($c, $table->totals[$c['key']] ?? null)) ?></th><?php endforeach; ?></tr></tfoot><?php endif; ?></table>
<?php endif; ?></div>
