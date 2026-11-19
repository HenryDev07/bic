<div class="page-head"><div><h1>Mi perfil</h1><p class="muted">Tu cuenta y tu contraseña.</p></div></div>

<div class="grid grid-2">
  <section class="card">
    <h2>Cuenta</h2>
    <dl class="dl">
      <dt>Nombre</dt><dd><?= $e($user['name']) ?></dd>
      <dt>Correo</dt><dd><?= $e($user['email']) ?></dd>
      <dt>Último acceso</dt><dd><?= $e($local($user['last_login_at'])) ?></dd>
    </dl>
  </section>

  <section class="card">
    <h2>Cambiar contraseña</h2>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= $e($error) ?></div><?php endif; ?>
    <form method="post" action="<?= $e($url('/profile/password')) ?>" class="form">
      <?= $csrf() ?>
      <label class="field"><span>Contraseña actual</span>
        <input class="input" type="password" name="current_password" autocomplete="current-password" required>
      </label>
      <label class="field"><span>Nueva contraseña</span>
        <input class="input" type="password" name="new_password" autocomplete="new-password" minlength="<?= $e($minLength) ?>" required>
        <small class="muted">Mínimo <?= $e($minLength) ?> caracteres, con letras y números.</small>
      </label>
      <label class="field"><span>Confirmar nueva contraseña</span>
        <input class="input" type="password" name="new_password_confirm" autocomplete="new-password" required>
      </label>
      <button class="btn btn-primary" type="submit">Actualizar contraseña</button>
    </form>
  </section>
</div>
