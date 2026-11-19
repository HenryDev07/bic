<div class="page-head">
  <div><h1>Usuarios</h1><p class="muted">Personas con acceso al sistema.</p></div>
  <?php if ($can('users.create')): ?><a class="btn btn-primary" href="<?= $e($url('/users/create')) ?>"><?= $icon('plus', 16) ?> Nuevo usuario</a><?php endif; ?>
</div>

<form class="toolbar" method="get" action="<?= $e($url('/users')) ?>">
  <input class="input" type="search" name="q" value="<?= $e($q) ?>" placeholder="Buscar por nombre o correo" maxlength="100">
  <select class="input" name="status" aria-label="Estado">
    <option value="">Todos</option>
    <option value="active"<?= $status === 'active' ? ' selected' : '' ?>>Activos</option>
    <option value="inactive"<?= $status === 'inactive' ? ' selected' : '' ?>>Inactivos</option>
  </select>
  <button class="btn btn-ghost" type="submit">Filtrar</button>
</form>

<div class="card table-wrap">
<?php if ($rows === []): ?>
  <p class="empty-msg muted">No hay usuarios que coincidan con el filtro.</p>
<?php else: ?>
  <table class="table">
    <thead><tr><th>Nombre</th><th>Correo</th><th>Roles</th><th>Estado</th><th>Último acceso</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
      <tr>
        <td><strong><?= $e($row['name']) ?></strong></td>
        <td><?= $e($row['email']) ?></td>
        <td><?php foreach ($row['roles'] ?? [] as $roleName): ?><span class="badge"><?= $e($roleName) ?></span> <?php endforeach; ?></td>
        <td><?= (int) $row['is_active'] === 1 ? '<span class="badge ok">Activo</span>' : '<span class="badge off">Inactivo</span>' ?></td>
        <td class="muted"><?= $e($local($row['last_login_at'])) ?></td>
        <td class="right"><?php if ($can('users.edit')): ?><a class="btn btn-ghost btn-sm" href="<?= $e($url('/users/' . (int) $row['id'] . '/edit')) ?>">Editar</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>
<?= $partial('core::pagination', ['pager' => $pager, 'pagePath' => $pagePath, 'pageQuery' => $pageQuery]) ?>
