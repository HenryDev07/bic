<div class="page-head"><div><h1>Órdenes de compra</h1><p class="muted">Compras = inventario. Los fletes y aranceles se suman al costo de lo recibido.</p></div>
  <?php if ($can('purchases.create')): ?><a class="btn btn-primary" href="<?= $e($url('/purchases/create')) ?>"><?= $icon('plus', 16) ?> Nueva orden</a><?php endif; ?></div>
<form class="toolbar" method="get" action="<?= $e($url('/purchases')) ?>">
  <input class="input" type="search" name="q" value="<?= $e($q) ?>" placeholder="Número o proveedor" maxlength="100">
  <select class="input" name="status" aria-label="Estado"><option value="">Todos los estados</option>
    <?php foreach ($statuses as $k => $l): ?><option value="<?= $e($k) ?>"<?= $status === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select>
  <button class="btn btn-ghost" type="submit">Filtrar</button></form>
<div class="card table-wrap">
<?php if ($rows === []): ?><p class="empty-msg muted">No hay órdenes de compra.</p><?php else: ?>
  <table class="table"><thead><tr><th>Orden</th><th>Proveedor</th><th>Estado</th><th>Fecha</th><th class="right">Total a pagar (base)</th><th class="right">Pendiente</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td><a href="<?= $e($url('/purchases/' . (int) $r['id'])) ?>"><strong><?= $e($r['po_number']) ?></strong></a></td><td><?= $e($r['supplier_name']) ?></td>
      <td><span class="badge<?= $r['status'] === 'received' ? ' ok' : ($r['status'] === 'cancelled' ? ' off' : '') ?>"><?= $e($statuses[$r['status']] ?? $r['status']) ?></span></td>
      <td class="muted"><?= $e($r['ordered_on'] ?? '—') ?></td><td class="right"><?= $e($money($r['payable'])) ?></td>
      <td class="right"><?= $r['status'] === 'cancelled' ? '—' : $e($money($r['due'])) ?></td></tr>
  <?php endforeach; ?></tbody></table>
<?php endif; ?></div>
<?= $partial('core::pagination', ['pager' => $pager, 'pagePath' => $pagePath, 'pageQuery' => $pageQuery]) ?>
