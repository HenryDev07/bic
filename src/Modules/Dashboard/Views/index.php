<?php use Bic\Core\Charts; use Bic\Core\Money\Decimal;
$openLabels = ['draft' => 'Borradores', 'pending' => 'Pendientes', 'confirmed' => 'Confirmados', 'prepared' => 'Preparados']; ?>
<div class="page-head">
  <div><h1>Hola, <?= $e($user['name']) ?></h1><p class="muted"><?= $e($business['name'] ?? '') ?> · moneda base <?= $e($business['base_currency'] ?? '') ?> · <?= $e($from) ?> a <?= $e($to) ?></p></div>
  <form method="get" action="<?= $e($url('/')) ?>" class="toolbar"><select class="input" name="days" aria-label="Período">
    <?php foreach ($periods as $d => $l): ?><option value="<?= $e($d) ?>"<?= $d === $days ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select>
    <button class="btn btn-ghost" type="submit">Ver</button></form>
</div>

<?php if (!$hasAny): ?>
<section class="card empty"><h2>Bienvenido</h2><p class="muted">Tu rol no incluye indicadores comerciales. Usa el menú para acceder a los módulos que tienes disponibles.</p></section>
<?php endif; ?>

<?php if (isset($pl) || isset($sales)): ?>
<div class="grid grid-4">
  <?php if (isset($pl)): ?>
  <section class="card stat"><span class="stat-label">Ingresos netos</span><span class="stat-value stat-small"><?= $e($money($pl['net_revenue'])) ?></span><span class="muted">sin impuestos, tras devoluciones</span></section>
  <section class="card stat"><span class="stat-label">Ganancia bruta</span><span class="stat-value stat-small"><?= $e($money($pl['gross'])) ?></span><span class="muted"><?= $pl['gross_margin_pct'] === null ? 'sin ventas' : 'margen ' . $e($money($pl['gross_margin_pct'])) . ' %' ?></span></section>
  <section class="card stat"><span class="stat-label">Gastos</span><span class="stat-value stat-small"><?= $e($money($pl['expenses'])) ?></span><span class="muted">vigentes del período</span></section>
  <section class="card stat"><span class="stat-label">Ganancia neta</span><span class="stat-value stat-small <?= $pl['net']->isNegative() ? 'neg' : '' ?>"><?= $e($money($pl['net'])) ?></span><span class="muted">bruta − gastos</span></section>
  <?php elseif (isset($sales)): ?>
  <section class="card stat"><span class="stat-label">Ventas del período</span><span class="stat-value stat-small"><?= $e($money($sales['total'])) ?></span><span class="muted"><?= $e($sales['orders']) ?> pedido(s) entregado(s)</span></section>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if (isset($sales)): ?>
<section class="card"><h2 class="card-title">Ventas por día <span class="muted">(<?= $e($money($sales['total'])) ?> en <?= $e($sales['orders']) ?> pedido(s))</span></h2>
  <div class="card-pad"><?php
    $labels = array_map(static fn (string $d): string => substr($d, 8, 2) . '/' . substr($d, 5, 2), $sales['days']);
    echo Charts::bars($labels, array_map(static fn (Decimal $v): float => (float) $v->toString(), $sales['values']), 'Ventas netas por día', array_map(static fn (Decimal $v): string => $money($v), $sales['values']));
  ?></div></section>
<?php endif; ?>

<div class="grid grid-2">
  <?php if (isset($pl)): ?>
  <section class="card"><h2 class="card-title">Gastos por categoría</h2><div class="card-pad">
    <?php if ($pl['by_category'] === []): ?><p class="muted">Sin gastos en el período.</p><?php else:
      echo Charts::hbars(array_column($pl['by_category'], 'name'), array_map(static fn ($c): float => (float) $c['total'], $pl['by_category']), 'Gastos por categoría', array_map(static fn ($c): string => $money($c['total']), $pl['by_category']));
    endif; ?></div></section>
  <?php endif; ?>
  <?php if (isset($top)): ?>
  <section class="card table-wrap"><h2 class="card-title">Productos más vendidos</h2>
    <?php if ($top === []): ?><p class="empty-msg muted">Sin ventas en el período.</p><?php else: ?>
    <table class="table"><thead><tr><th>Producto</th><th class="right">Unidades</th><th class="right">Ventas netas</th></tr></thead><tbody>
    <?php foreach ($top as $t): ?><tr><td><strong><?= $e($t['name']) ?></strong></td><td class="right"><?= $e($money($t['qty'], 3)) ?></td><td class="right"><?= $e($money($t['net'])) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></section>
  <?php endif; ?>
</div>

<?php if (isset($cash) || isset($receivables) || isset($payables) || isset($inventory)): ?>
<div class="grid grid-4">
  <?php if (isset($cash)): ?><section class="card stat"><span class="stat-label">Dinero en cuentas</span><span class="stat-value stat-small"><?= $e($money($cash)) ?></span><span class="muted"><?= $e($accounts) ?> cuenta(s)</span></section><?php endif; ?>
  <?php if (isset($receivables)): ?><section class="card stat"><span class="stat-label">Por cobrar a clientes</span><span class="stat-value stat-small"><?= $e($money($receivables)) ?></span><span class="muted">pedidos entregados con saldo</span></section>
    <section class="card stat"><span class="stat-label">Anticipos de clientes</span><span class="stat-value stat-small"><?= $e($money($deposits)) ?></span><span class="muted">pasivo: pedidos aún sin entregar</span></section><?php endif; ?>
  <?php if (isset($payables)): ?><section class="card stat"><span class="stat-label">Por pagar a proveedores</span><span class="stat-value stat-small"><?= $e($money($payables)) ?></span><span class="muted">órdenes enviadas</span></section><?php endif; ?>
  <?php if (isset($inventory)): ?><section class="card stat"><span class="stat-label">Valor del inventario</span><span class="stat-value stat-small"><?= $e($money($inventory['value'])) ?></span><span class="muted">a costo promedio</span></section><?php endif; ?>
</div>
<?php endif; ?>

<div class="grid grid-2">
  <?php if (isset($open)): ?>
  <section class="card"><h2 class="card-title">Pedidos abiertos</h2><div class="card-pad">
    <?php foreach ($open as $k => $n): ?><p class="kv"><span><?= $e($openLabels[$k]) ?></span><strong><?= $e($n) ?></strong></p><?php endforeach; ?>
    <?php if ($can('sales.view')): ?><a href="<?= $e($url('/sales')) ?>">Ver pedidos</a><?php endif; ?></div></section>
  <?php endif; ?>
  <?php if (isset($low)): ?>
  <section class="card table-wrap"><h2 class="card-title">Stock bajo</h2>
    <?php if ($low === []): ?><p class="empty-msg muted">Ningún producto bajo su stock mínimo.</p><?php else: ?>
    <table class="table"><thead><tr><th>Producto</th><th class="right">Disponible</th><th class="right">Mínimo</th></tr></thead><tbody>
    <?php foreach ($low as $l): ?><tr><td><strong><?= $e($l['name']) ?></strong> <span class="muted"><code><?= $e($l['sku']) ?></code></span></td><td class="right"><?= $e($money($l['available'], 3)) ?></td><td class="right"><?= $e($money($l['min_stock'], 3)) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></section>
  <?php endif; ?>
</div>
