<section class="card guest-card">
  <h1>Iniciar sesión</h1>
  <p class="muted">Accede a tu centro de operaciones comerciales.</p>
  <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= $e($error) ?></div><?php endif; ?>
  <form method="post" action="<?= $e($url('/login')) ?>" class="form">
    <?= $csrf() ?>
    <label class="field"><span>Correo electrónico</span>
      <input class="input" type="email" name="email" value="<?= $e($email) ?>" autocomplete="username" maxlength="190" required autofocus>
    </label>
    <label class="field"><span>Contraseña</span>
      <input class="input" type="password" name="password" autocomplete="current-password" required>
    </label>
    <button class="btn btn-primary btn-block" type="submit">Entrar</button>
  </form>
</section>
