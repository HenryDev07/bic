<div class="page-head">
  <div><h1>Existencias</h1><p class="muted">Físico = lo que hay; reservado = comprometido en pedidos; disponible = físico − reservado.</p></div>
  <?php if ($can('inventory.adjust')): ?><a class="btn btn-primary" href="<?= $e($url('/inventory/adjust')) ?>"><?= $icon('plus', 16) ?> Ajuste / transferencia</a><?php endif; ?>
</div>
<?php if ($issues === []): ?><div class="alert alert-success">Conciliación correcta: cada saldo coincide con la suma de sus movimientos.</div>
<?php else: ?><div class="alert alert-danger"><strong>Conciliación con diferencias:</strong><?php foreach ($issues as $i): ?><br><?= $e($i) ?><?php endforeach; ?></div><?php endif; ?>
<?php if ($valuation !== null): ?><div class="grid grid-3"><div class="card stat"><span class="stat-label">Valor del inventario (costo promedio)</span><span class="stat-value"><?= $e($money($valuation['value'])) ?></span></div></div><?php endif; ?>
<form class="toolbar" method="get" action="<?= $e($url('/inventory')) ?>">
  <input class="input" type="search" name="q" value="<?= $e($q) ?>" placeholder="Producto, SKU o código" maxlength="100">
  <select class="input" name="warehouse_id" aria-label="Almacén"><option value="">Todos los almacenes</option>
    <?php foreach ($warehouses as $w): ?><option value="<?= $e($w['id']) ?>"<?= (int) $w['id'] === $warehouseId ? ' selected' : '' ?>><?= $e($w['name']) ?></option><?php endforeach; ?></select>
  <label class="check"><input type="checkbox" name="low" value="1"<?= $low ? ' checked' : '' ?>> Solo stock bajo</label>
  <button class="btn btn-ghost" type="submit">Filtrar</button>
</form>
<div class="card table-wrap">
<?php if ($rows === []): ?><p class="empty-msg muted">No hay productos que coincidan.</p><?php else: ?>
  <table class="table"><thead><tr><th>Producto</th><th>Almacén</th><th class="right">Físico</th><th class="right">Reservado</th><th class="right">Disponible</th><?php if ($showCost): ?><th class="right">Costo prom.</th><th class="right">Valor</th><?php endif; ?><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td><a href="<?= $e($url('/products/' . (int) $r['product_id'])) ?>"><strong><?= $e($r['name']) ?></strong></a><br><span class="muted"><code><?= $e($r['sku']) ?></code><?= $r['variant_name'] ? ' · ' . $e($r['variant_name']) : '' ?></span></td>
      <td><?= $e($r['warehouse_name'] ?? '—') ?></td>
      <td class="right"><?= $e($money($r['quantity'], 3)) ?> <span class="muted"><?= $e($r['unit']) ?></span></td>
      <td class="right"><?= $e($money($r['reserved'], 3)) ?></td><td class="right"><strong><?= $e($money($r['available'], 3)) ?></strong></td>
      <?php if ($showCost): ?><td class="right"><?= $e($money($r['avg_cost'], 4)) ?></td><td class="right"><?= $e($money($r['value'])) ?></td><?php endif; ?>
      <td><?= $r['low'] ? '<span class="badge off">Stock bajo (mín. ' . $e($money($r['min_stock'], 3)) . ')</span>' : '' ?></td></tr>
  <?php endforeach; ?></tbody></table>
<?php endif; ?></div>
<?= $partial('core::pagination', ['pager' => $pager, 'pagePath' => $pagePath, 'pageQuery' => $pageQuery]) ?>
