<div class="page-head">
  <div><h1><?= $e($c['name']) ?> <?= (int) $c['is_active'] === 1 ? '<span class="badge ok">Activo</span>' : '<span class="badge off">Inactivo</span>' ?></h1>
    <p class="muted"><?= $e(implode(' · ', array_filter([$c['phone'], $c['email'], $c['tax_id'], $c['address']]))) ?></p></div>
  <div class="actions"><a class="btn btn-ghost" href="<?= $e($url('/customers')) ?>">Volver</a>
    <?php if ($can('customers.edit')): ?><a class="btn btn-ghost" href="<?= $e($url('/customers/' . (int) $c['id'] . '/edit')) ?>">Editar</a><?php endif; ?>
    <?php if ($can('sales.create')): ?><a class="btn btn-primary" href="<?= $e($url('/sales/create?customer_id=' . (int) $c['id'])) ?>"><?= $icon('plus', 16) ?> Nuevo pedido</a><?php endif; ?></div>
</div>
<?php if ($c['notes']): ?><div class="card card-pad"><?= nl2br($e($c['notes'])) ?></div><?php endif; ?>
<?php if ($can('sales.view')): ?>
<div class="card table-wrap"><h2 class="card-title">Pedidos</h2>
<?php if ($orders === []): ?><p class="empty-msg muted">Sin pedidos todavía.</p><?php else: ?>
  <table class="table"><thead><tr><th>Pedido</th><th>Fecha</th><th>Estado</th><th class="right">Total</th></tr></thead><tbody>
  <?php foreach ($orders as $o): ?>
    <tr><td><a href="<?= $e($url('/sales/' . (int) $o['id'])) ?>"><?= $e($o['order_number']) ?></a></td><td><?= $e($local($o['created_at'])) ?></td>
      <td><?= $e($o['status']) ?></td><td class="right"><?= $e($money($o['total'])) ?></td></tr>
  <?php endforeach; ?></tbody></table><?php endif; ?></div>
<?php endif; ?>
