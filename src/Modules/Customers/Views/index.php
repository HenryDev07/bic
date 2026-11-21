<div class="page-head">
  <div><h1>Clientes</h1><p class="muted">Quienes compran. En una venta de mostrador el cliente es opcional.</p></div>
  <?php if ($can('customers.create')): ?><a class="btn btn-primary" href="<?= $e($url('/customers/create')) ?>"><?= $icon('plus', 16) ?> Nuevo cliente</a><?php endif; ?>
</div>
<form class="toolbar" method="get" action="<?= $e($url('/customers')) ?>">
  <input class="input" type="search" name="q" value="<?= $e($q) ?>" placeholder="Nombre, correo, teléfono o identificación" maxlength="100">
  <button class="btn btn-ghost" type="submit">Buscar</button>
</form>
<div class="card table-wrap">
<?php if ($rows === []): ?><p class="empty-msg muted">No hay clientes.</p><?php else: ?>
  <table class="table"><thead><tr><th>Cliente</th><th>Contacto</th><th>Identificación</th><th>Estado</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td><a href="<?= $e($url('/customers/' . (int) $r['id'])) ?>"><strong><?= $e($r['name']) ?></strong></a></td>
      <td><?= $e($r['phone'] ?? '—') ?><?= $r['email'] ? '<br><span class="muted">' . $e($r['email']) . '</span>' : '' ?></td>
      <td><?= $e($r['tax_id'] ?? '—') ?></td>
      <td><?= (int) $r['is_active'] === 1 ? '<span class="badge ok">Activo</span>' : '<span class="badge off">Inactivo</span>' ?></td></tr>
  <?php endforeach; ?></tbody></table>
<?php endif; ?>
</div>
<?= $partial('core::pagination', ['pager' => $pager, 'pagePath' => $pagePath, 'pageQuery' => $pageQuery]) ?>
