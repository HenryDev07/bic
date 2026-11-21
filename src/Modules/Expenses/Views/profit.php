<div class="page-head"><div><h1>Estado de resultados</h1><p class="muted">Moneda base, sin impuestos. El ingreso cuenta al entregar; los gastos por su fecha.</p></div></div>
<form class="toolbar" method="get" action="<?= $e($url('/expenses/profit')) ?>">
  <input class="input" type="date" name="from" value="<?= $e($from) ?>" aria-label="Desde"><input class="input" type="date" name="to" value="<?= $e($to) ?>" aria-label="Hasta">
  <button class="btn btn-ghost" type="submit">Ver</button></form>
<div class="grid grid-3">
  <div class="card stat"><span class="stat-label">Ingresos netos</span><span class="stat-value stat-small"><?= $e($money($pl['net_revenue'])) ?></span><span class="muted">Ventas <?= $e($money($pl['revenue'])) ?> − devoluciones <?= $e($money($pl['returns'])) ?></span></div>
  <div class="card stat"><span class="stat-label">Ganancia bruta</span><span class="stat-value stat-small"><?= $e($money($pl['gross'])) ?></span><span class="muted"><?= $pl['gross_margin_pct'] === null ? 'Sin ventas' : 'Margen ' . $e($money($pl['gross_margin_pct'])) . ' %' ?></span></div>
  <div class="card stat"><span class="stat-label">Ganancia neta</span><span class="stat-value stat-small"><?= $e($money($pl['net'])) ?></span><span class="muted">Gastos <?= $e($money($pl['expenses'])) ?></span></div>
</div>
<div class="card table-wrap"><h2 class="card-title">Detalle</h2>
  <table class="table"><tbody>
    <tr><td>Ventas (base de pedidos entregados)</td><td class="right"><?= $e($money($pl['revenue'])) ?></td></tr>
    <tr><td>− Devoluciones</td><td class="right"><?= $e($money($pl['returns'])) ?></td></tr>
    <tr><td><strong>= Ingresos netos</strong></td><td class="right"><strong><?= $e($money($pl['net_revenue'])) ?></strong></td></tr>
    <tr><td>− Costo de lo vendido</td><td class="right"><?= $e($money($pl['cogs'])) ?></td></tr>
    <tr><td>+ Costo de lo devuelto y repuesto</td><td class="right"><?= $e($money($pl['returned_cost'])) ?></td></tr>
    <tr><td><strong>= Ganancia bruta</strong></td><td class="right"><strong><?= $e($money($pl['gross'])) ?></strong></td></tr>
    <?php foreach ($pl['by_category'] as $c): ?><tr><td>− Gasto: <?= $e($c['name']) ?></td><td class="right"><?= $e($money($c['total'])) ?></td></tr><?php endforeach; ?>
    <tr><td><strong>= Ganancia neta</strong></td><td class="right"><strong><?= $e($money($pl['net'])) ?></strong></td></tr>
  </tbody></table></div>
