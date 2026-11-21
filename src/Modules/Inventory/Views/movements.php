<div class="page-head"><div><h1>Movimientos de stock</h1><p class="muted">Kardex inmutable: las correcciones son movimientos inversos, nunca ediciones.</p></div></div>
<form class="toolbar" method="get" action="<?= $e($url('/inventory/movements')) ?>">
  <select class="input" name="variant_id" aria-label="Variante"><option value="">Todas las variantes</option>
    <?php foreach ($variants as $v): ?><option value="<?= $e($v['id']) ?>"<?= (int) $v['id'] === (int) $f['variant_id'] ? ' selected' : '' ?>><?= $e($v['name']) ?> — <?= $e($v['sku']) ?></option><?php endforeach; ?></select>
  <select class="input" name="warehouse_id" aria-label="Almacén"><option value="">Todos los almacenes</option>
    <?php foreach ($warehouses as $w): ?><option value="<?= $e($w['id']) ?>"<?= (int) $w['id'] === (int) $f['warehouse_id'] ? ' selected' : '' ?>><?= $e($w['name']) ?></option><?php endforeach; ?></select>
  <select class="input" name="type" aria-label="Tipo"><option value="">Todos los tipos</option>
    <?php foreach ($types as $k => $l): ?><option value="<?= $e($k) ?>"<?= $f['type'] === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select>
  <input class="input" type="date" name="from" value="<?= $e($f['from_date']) ?>" aria-label="Desde"><input class="input" type="date" name="to" value="<?= $e($f['to_date']) ?>" aria-label="Hasta">
  <button class="btn btn-ghost" type="submit">Filtrar</button>
</form>
<div class="card table-wrap">
<?php if ($rows === []): ?><p class="empty-msg muted">Sin movimientos.</p><?php else: ?>
  <table class="table"><thead><tr><th>Fecha</th><th>Producto</th><th>Almacén</th><th>Tipo</th><th class="right">Cantidad</th><th class="right">Saldo</th><?php if ($showCost): ?><th class="right">Costo unit.</th><?php endif; ?><th>Motivo / origen</th></tr></thead><tbody>
  <?php foreach ($rows as $m): $in = (float) $m['quantity'] > 0; ?>
    <tr><td class="muted"><?= $e($local($m['created_at'])) ?></td>
      <td><?= $e($m['product_name']) ?><br><span class="muted"><code><?= $e($m['sku']) ?></code></span></td><td><?= $e($m['warehouse_name']) ?></td>
      <td><span class="badge"><?= $e($types[$m['movement_type']] ?? $m['movement_type']) ?></span></td>
      <td class="right"><?= $in ? '+' : '−' ?><?= $e($money(ltrim((string) $m['quantity'], '-'), 3)) ?></td><td class="right"><?= $e($money($m['balance_after'], 3)) ?></td>
      <?php if ($showCost): ?><td class="right"><?= $e($money($m['unit_cost'], 4)) ?></td><?php endif; ?>
      <td><?= $e($m['reason'] ?? '') ?><?= $m['reference_type'] === 'purchase_receipt' ? ' <a href="' . $e($url('/purchases')) . '">Recepción #' . (int) $m['reference_id'] . '</a>' : '' ?></td></tr>
  <?php endforeach; ?></tbody></table>
<?php endif; ?></div>
<?= $partial('core::pagination', ['pager' => $pager, 'pagePath' => $pagePath, 'pageQuery' => $pageQuery]) ?>
