<div class="page-head">
  <div><h1>Roles y permisos</h1><p class="muted">Cada permiso se valida en el servidor en cada operación.</p></div>
</div>

<div class="grid grid-2">
<?php foreach ($roles as $role): ?>
  <section class="card">
    <div class="card-head">
      <h2><?= $e($role['name']) ?></h2>
      <?php if ($role['code'] === 'admin'): ?><span class="badge"><?= $icon('lock', 12) ?> Bloqueado</span><?php endif; ?>
    </div>
    <p class="muted"><?= $e($role['description']) ?></p>
    <p><span class="badge"><?= $e($role['permission_count']) ?> permisos</span> <span class="badge"><?= $e($role['user_count']) ?> usuarios</span></p>
    <a class="btn btn-ghost btn-sm" href="<?= $e($url('/roles/' . (int) $role['id'])) ?>"><?= $can('roles.edit') && $role['code'] !== 'admin' ? 'Editar permisos' : 'Ver permisos' ?></a>
  </section>
<?php endforeach; ?>
</div>
