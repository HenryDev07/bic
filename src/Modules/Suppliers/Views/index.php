<div class="page-head">
  <div><h1>Proveedores</h1><p class="muted">Contactos, condiciones y cotizaciones.</p></div>
  <?php if ($can('suppliers.create')): ?><a class="btn btn-primary" href="<?= $e($url('/suppliers/create')) ?>"><?= $icon('plus', 16) ?> Nuevo proveedor</a><?php endif; ?>
</div>
<form class="toolbar" method="get" action="<?= $e($url('/suppliers')) ?>">
  <input class="input" type="search" name="q" value="<?= $e($q) ?>" placeholder="Nombre, contacto o correo" maxlength="100">
  <button class="btn btn-ghost" type="submit">Buscar</button>
</form>
<div class="card table-wrap">
<?php if ($rows === []): ?><p class="empty-msg muted">No hay proveedores.</p><?php else: ?>
  <table class="table">
    <thead><tr><th>Proveedor</th><th>Contacto</th><th>País</th><th>Moneda</th><th>Entrega</th><th>Estado</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><a href="<?= $e($url('/suppliers/' . (int) $r['id'])) ?>"><strong><?= $e($r['name']) ?></strong></a></td>
        <td><?= $e($r['contact_name'] ?? '—') ?><?= $r['email'] ? '<br><span class="muted">' . $e($r['email']) . '</span>' : '' ?></td>
        <td><?= $e($r['country'] ?? '—') ?></td><td><?= $e($r['currency']) ?></td>
        <td><?= $r['lead_time_days'] === null ? '—' : $e($r['lead_time_days']) . ' d' ?></td>
        <td><?= (int) $r['is_active'] === 1 ? '<span class="badge ok">Activo</span>' : '<span class="badge off">Inactivo</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>
<?= $partial('core::pagination', ['pager' => $pager, 'pagePath' => $pagePath, 'pageQuery' => $pageQuery]) ?>
