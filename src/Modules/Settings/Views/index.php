<div class="page-head">
  <div><h1>Configuración</h1><p class="muted">Parámetros de seguridad del negocio.</p></div>
</div>

<form method="post" action="<?= $e($url('/settings')) ?>" class="card form form-wide">
  <?= $csrf() ?>
  <h2>Seguridad</h2>
  <?php foreach ($definitions as $key => $def): ?>
    <label class="field"><span><?= $e($def['label']) ?></span>
      <input class="input<?= isset($errors[$key]) ? ' invalid' : '' ?>" type="number" name="<?= $e($key) ?>" value="<?= $e($values[$key] ?? $def['default']) ?>"
             min="<?= $e($def['min']) ?>" max="<?= $e($def['max']) ?>" required<?= $canEdit ? '' : ' disabled' ?>>
      <small class="muted">Entre <?= $e($def['min']) ?> y <?= $e($def['max']) ?>.</small>
      <?php if (isset($errors[$key])): ?><small class="error"><?= $e($errors[$key]) ?></small><?php endif; ?>
    </label>
  <?php endforeach; ?>
  <?php if ($canEdit): ?><div class="form-actions"><button class="btn btn-primary" type="submit">Guardar configuración</button></div><?php endif; ?>
</form>
