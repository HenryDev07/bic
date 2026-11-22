<?php use Bic\Core\Money\Decimal; ?>
<div class="page-head"><div><h1>Inteligencia comercial</h1><p class="muted">Análisis con reglas explícitas sobre tus datos reales (<?= $e($from) ?> a <?= $e($to) ?>). Importes sin impuesto, moneda base.</p></div>
  <form method="get" action="<?= $e($url('/insights')) ?>" class="toolbar"><select class="input" name="days" aria-label="Período"><?php foreach ($periods as $d => $l): ?><option value="<?= $e($d) ?>"<?= $d === $days ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select><button class="btn btn-ghost" type="submit">Ver</button></form></div>

<?php if (isset($trend)): $pct = $trend['change_pct']; ?>
<div class="grid grid-3">
  <section class="card stat"><span class="stat-label">Ventas del período</span><span class="stat-value stat-small"><?= $e($money($trend['current'])) ?></span><span class="muted">período anterior: <?= $e($money($trend['previous'])) ?></span></section>
  <section class="card stat"><span class="stat-label">Variación</span><span class="stat-value stat-small <?= $pct !== null && Decimal::from($pct, 2)->isNegative() ? 'neg' : '' ?>"><?= $pct === null ? 'Sin base de comparación' : ($e($money($pct)) . ' %') ?></span><span class="muted">frente al período anterior de igual duración</span></section>
  <section class="card stat"><span class="stat-label">Productos con ventas</span><span class="stat-value stat-small"><?= $e(count($abc)) ?></span><span class="muted">A: <?= $e(count(array_filter($abc, fn ($p) => $p['class'] === 'A'))) ?> · B: <?= $e(count(array_filter($abc, fn ($p) => $p['class'] === 'B'))) ?> · C: <?= $e(count(array_filter($abc, fn ($p) => $p['class'] === 'C'))) ?></span></section>
</div>
<?php endif; ?>

<?php if (isset($reorder)): ?>
<section class="card table-wrap"><h2 class="card-title">Reposición sugerida</h2><p class="card-pad muted">Productos en o bajo su stock mínimo. Cobertura = disponible ÷ venta diaria media del período. Sugerido = mínimo + 14 días de venta − disponible.</p>
<?php if ($reorder === []): ?><p class="empty-msg muted">Nada que reponer por ahora.</p><?php else: ?>
  <table class="table"><thead><tr><th>Producto</th><th class="right">Disponible</th><th class="right">Mínimo</th><th class="right">Venta diaria</th><th class="right">Cobertura (días)</th><th class="right">Sugerido</th></tr></thead><tbody>
  <?php foreach ($reorder as $r): ?><tr><td><strong><?= $e($r['name']) ?></strong> <span class="muted"><code><?= $e($r['sku']) ?></code></span></td><td class="right"><?= $e($money($r['available'], 3)) ?></td><td class="right"><?= $e($money($r['min'], 3)) ?></td>
    <td class="right"><?= $e($money($r['daily'], 3)) ?></td><td class="right"><?= $r['cover_days'] === null ? '—' : $e($money($r['cover_days'], 1)) ?></td><td class="right"><strong><?= $e($money($r['suggested'], 0)) ?></strong></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></section>
<?php endif; ?>

<div class="grid grid-2">
<?php if (isset($slow)): ?>
<section class="card table-wrap"><h2 class="card-title">Existencias estancadas</h2><p class="card-pad muted">Con stock y sin ventas en <?= $e($slowDays) ?> días o más (configurable en Configuración).</p>
<?php if ($slow === []): ?><p class="empty-msg muted">No hay existencias estancadas.</p><?php else: ?>
  <table class="table"><thead><tr><th>Producto</th><th class="right">Cantidad</th><?php if ($canCost): ?><th class="right">Capital parado</th><?php endif; ?><th>Última venta</th></tr></thead><tbody>
  <?php foreach ($slow as $r): ?><tr><td><strong><?= $e($r['name']) ?></strong></td><td class="right"><?= $e($money($r['qty'], 3)) ?></td><?php if ($canCost): ?><td class="right"><?= $e($money($r['value'])) ?></td><?php endif; ?><td><?= $r['last_sale'] === null ? 'Nunca' : $e($local($r['last_sale'], 'd/m/Y')) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></section>
<?php endif; ?>
<?php if (isset($customers)): ?>
<section class="card table-wrap"><h2 class="card-title">Mejores clientes</h2>
<?php if ($customers === []): ?><p class="empty-msg muted">Sin ventas a clientes en el período.</p><?php else: ?>
  <table class="table"><thead><tr><th>Cliente</th><th class="right">Pedidos</th><th class="right">Ventas netas</th></tr></thead><tbody>
  <?php foreach ($customers as $c): ?><tr><td><a href="<?= $e($url('/customers/' . (int) $c['id'])) ?>"><?= $e($c['name']) ?></a></td><td class="right"><?= $e($c['orders']) ?></td><td class="right"><?= $e($money($c['net'])) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></section>
<?php endif; ?>
</div>

<?php if (isset($abc)): ?>
<section class="card table-wrap"><h2 class="card-title">Clasificación ABC de productos</h2><p class="card-pad muted">A = los que suman hasta el 80 % de las ventas, B = hasta el 95 %, C = el resto. Concentra compras, vitrina y atención en los A.</p>
<?php if ($abc === []): ?><p class="empty-msg muted">Sin ventas en el período.</p><?php else: ?>
  <table class="table"><thead><tr><th>Clase</th><th>Producto</th><th class="right">Unidades</th><th class="right">Ventas netas</th><th class="right">% de ventas</th><?php if ($canCost): ?><th class="right">Margen %</th><?php endif; ?></tr></thead><tbody>
  <?php foreach (array_slice($abc, 0, 20) as $p): ?><tr><td><span class="badge<?= $p['class'] === 'A' ? ' ok' : ($p['class'] === 'C' ? ' off' : '') ?>"><?= $e($p['class']) ?></span></td><td><strong><?= $e($p['name']) ?></strong></td><td class="right"><?= $e($money($p['qty'], 3)) ?></td>
    <td class="right"><?= $e($money($p['net'])) ?></td><td class="right"><?= $e($money($p['share'])) ?></td><?php if ($canCost): ?><td class="right"><?= $p['margin'] === null ? '—' : $e($money($p['margin'])) ?></td><?php endif; ?></tr><?php endforeach; ?></tbody></table><?php endif; ?></section>
<?php endif; ?>

<?php if (isset($lowMargin) || isset($belowCost)): ?>
<div class="grid grid-2">
<?php if (isset($lowMargin)): ?>
<section class="card table-wrap"><h2 class="card-title">Margen por debajo del <?= $e($minMargin) ?> %</h2>
<?php if ($lowMargin === []): ?><p class="empty-msg muted">Todos los productos vendidos superan el margen mínimo.</p><?php else: ?>
  <table class="table"><thead><tr><th>Producto</th><th class="right">Ventas netas</th><th class="right">Margen %</th></tr></thead><tbody>
  <?php foreach ($lowMargin as $p): ?><tr><td><?= $e($p['name']) ?></td><td class="right"><?= $e($money($p['net'])) ?></td><td class="right neg"><?= $e($money($p['margin'])) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></section>
<?php endif; ?>
<?php if (isset($belowCost)): ?>
<section class="card table-wrap"><h2 class="card-title">Precio de lista por debajo del costo</h2>
<?php if ($belowCost === []): ?><p class="empty-msg muted">Ningún producto con stock se lista bajo su costo promedio.</p><?php else: ?>
  <table class="table"><thead><tr><th>Producto</th><th class="right">Precio sin impuesto</th><th class="right">Costo prom.</th></tr></thead><tbody>
  <?php foreach ($belowCost as $p): ?><tr><td><?= $e($p['name']) ?> <span class="muted"><code><?= $e($p['sku']) ?></code></span></td><td class="right neg"><?= $e($money($p['net_price'])) ?></td><td class="right"><?= $e($money($p['cost'])) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></section>
<?php endif; ?>
</div>
<?php endif; ?>
