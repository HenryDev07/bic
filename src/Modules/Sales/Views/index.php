<div class="page-head">
  <div><h1>Pedidos</h1><p class="muted">Borrador → pendiente → confirmado → preparado → entregado. La venta cuenta al entregar.</p></div>
  <?php if ($can('sales.create')): ?><a class="btn btn-primary" href="<?= $e($url('/sales/create')) ?>"><?= $icon('plus', 16) ?> Nuevo pedido</a><?php endif; ?>
</div>
<form class="toolbar" method="get" action="<?= $e($url('/sales')) ?>">
  <input class="input" type="search" name="q" value="<?= $e($q) ?>" placeholder="Número o cliente" maxlength="100">
  <select class="input" name="status"><option value="">Todos los estados</option>
    <?php foreach ($statuses as $k => $label): ?><option value="<?= $e($k) ?>"<?= $status === $k ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select>
  <button class="btn btn-ghost" type="submit">Filtrar</button>
</form>
<div class="card table-wrap">
<?php if ($rows === []): ?><p class="empty-msg muted">No hay pedidos.</p><?php else: ?>
  <table class="table"><thead><tr><th>Pedido</th><th>Cliente</th><th>Fecha</th><th>Estado</th><th class="right">Total</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td><a href="<?= $e($url('/sales/' . (int) $r['id'])) ?>"><strong><?= $e($r['order_number']) ?></strong></a></td>
      <td><?= $e($r['customer_name'] ?? 'Mostrador') ?></td><td><?= $e($local($r['created_at'])) ?></td>
      <td><span class="badge<?= $r['status'] === 'delivered' ? ' ok' : ($r['status'] === 'cancelled' ? ' off' : '') ?>"><?= $e($statuses[$r['status']]) ?></span></td>
      <td class="right"><?= $e($money($r['total'])) ?></td></tr>
  <?php endforeach; ?></tbody></table>
<?php endif; ?>
</div>
<?= $partial('core::pagination', ['pager' => $pager, 'pagePath' => $pagePath, 'pageQuery' => $pageQuery]) ?>
<?php if ($can('settings.edit')): ?>
<form method="post" action="<?= $e($url('/sales/reserve-setting')) ?>" class="card form"><?= $csrf() ?>
  <h2>Reserva de stock</h2><p class="muted">Cuándo se aparta el stock de un pedido para que nadie más lo venda.</p>
  <div class="toolbar"><select class="input" name="reserve_on"><?php foreach ($reserveOptions as $k => $label): ?><option value="<?= $e($k) ?>"<?= $reserveOn === $k ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select>
  <button class="btn btn-ghost" type="submit">Guardar</button></div>
</form>
<?php endif; ?>
