<div class="page-head">
  <div><h1><?= $e($role['name']) ?></h1><p class="muted"><?= $e($role['description']) ?></p></div>
  <a class="btn btn-ghost" href="<?= $e($url('/roles')) ?>">Volver</a>
</div>

<?php if ($role['code'] === 'admin'): ?>
  <div class="alert alert-info">El rol Administrador siempre tiene todos los permisos, incluidos los de módulos futuros. No se puede modificar.</div>
<?php endif; ?>

<form method="post" action="<?= $e($url('/roles/' . (int) $role['id'])) ?>" class="form">
  <?= $csrf() ?>
  <div class="grid grid-2">
  <?php foreach ($grouped as $module => $perms): ?>
    <section class="card">
      <h2><?= $e($moduleNames[$module] ?? ucfirst($module)) ?></h2>
      <?php foreach ($perms as $p):
          $isChecked = $role['code'] === 'admin' || in_array($p['code'], $checked, true);
          $disabled = $locked || (!$isChecked && !in_array($p['code'], $actorPerms, true)); ?>
        <label class="check<?= $disabled ? ' disabled' : '' ?>">
          <input type="checkbox" name="perm[]" value="<?= $e($p['code']) ?>"<?= $isChecked ? ' checked' : '' ?><?= $disabled ? ' disabled' : '' ?>>
          <span><?= $e($p['description']) ?> <span class="muted code"><?= $e($p['code']) ?></span></span>
        </label>
      <?php endforeach; ?>
    </section>
  <?php endforeach; ?>
  </div>
  <?php if (!$locked): ?>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Guardar permisos</button></div>
  <?php endif; ?>
</form>
