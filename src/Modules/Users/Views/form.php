<?php $action = $mode === 'create' ? '/users' : '/users/' . (int) $editing['id']; ?>
<div class="page-head">
  <div><h1><?= $e($title) ?></h1><p class="muted"><?= $mode === 'create' ? 'Crea una cuenta y asígnale uno o más roles.' : 'Modifica los datos, el estado o los roles.' ?></p></div>
  <a class="btn btn-ghost" href="<?= $e($url('/users')) ?>">Volver</a>
</div>

<form method="post" action="<?= $e($url($action)) ?>" class="card form form-wide">
  <?= $csrf() ?>
  <div class="grid grid-2">
    <label class="field"><span>Nombre</span>
      <input class="input<?= isset($errors['name']) ? ' invalid' : '' ?>" name="name" value="<?= $e($values['name'] ?? '') ?>" maxlength="150" required>
      <?php if (isset($errors['name'])): ?><small class="error"><?= $e($errors['name']) ?></small><?php endif; ?>
    </label>
    <label class="field"><span>Correo electrónico</span>
      <input class="input<?= isset($errors['email']) ? ' invalid' : '' ?>" type="email" name="email" value="<?= $e($values['email'] ?? '') ?>" maxlength="190" required>
      <?php if (isset($errors['email'])): ?><small class="error"><?= $e($errors['email']) ?></small><?php endif; ?>
    </label>
  </div>

  <label class="field"><span><?= $mode === 'create' ? 'Contraseña' : 'Nueva contraseña (opcional)' ?></span>
    <input class="input<?= isset($errors['password']) ? ' invalid' : '' ?>" type="password" name="password" autocomplete="new-password" <?= $mode === 'create' ? 'required' : '' ?>>
    <small class="muted">Mínimo <?= $e($minLength) ?> caracteres, con letras y números.<?= $mode === 'edit' ? ' Déjala vacía para no cambiarla.' : '' ?></small>
    <?php if (isset($errors['password'])): ?><small class="error"><?= $e($errors['password']) ?></small><?php endif; ?>
  </label>

  <fieldset class="field">
    <legend>Roles</legend>
    <?php foreach ($roles as $role): $on = in_array((int) $role['id'], $selected, true); ?>
      <label class="check<?= $role['assignable'] ? '' : ' disabled' ?>">
        <input type="checkbox" name="roles[]" value="<?= $e($role['id']) ?>"<?= $on ? ' checked' : '' ?><?= $role['assignable'] ? '' : ' disabled' ?>>
        <span><strong><?= $e($role['name']) ?></strong> <span class="muted">— <?= $e($role['description']) ?><?= $role['assignable'] ? '' : ' (no puedes asignarlo: otorga permisos que no tienes)' ?></span></span>
      </label>
    <?php endforeach; ?>
    <?php if (isset($errors['roles'])): ?><small class="error"><?= $e($errors['roles']) ?></small><?php endif; ?>
  </fieldset>

  <label class="check">
    <input type="checkbox" name="is_active" value="1"<?= !empty($values['is_active']) ? ' checked' : '' ?><?= $isSelf ? ' data-keep' : '' ?>>
    <span><strong>Cuenta activa</strong> <span class="muted">— los usuarios inactivos no pueden iniciar sesión.</span></span>
  </label>
  <?php if (isset($errors['is_active'])): ?><small class="error"><?= $e($errors['is_active']) ?></small><?php endif; ?>

  <div class="form-actions">
    <button class="btn btn-primary" type="submit"><?= $mode === 'create' ? 'Crear usuario' : 'Guardar cambios' ?></button>
    <a class="btn btn-ghost" href="<?= $e($url('/users')) ?>">Cancelar</a>
  </div>
</form>
