<?php $action = '/catalog/' . $entity . ($editing === null ? '' : '/' . (int) $editing['id']); ?>
<div class="page-head">
  <div><h1><?= $e($title) ?></h1></div>
  <a class="btn btn-ghost" href="<?= $e($url('/catalog/' . $entity)) ?>">Volver</a>
</div>
<form method="post" action="<?= $e($url($action)) ?>" class="card form form-wide">
  <?= $csrf() ?>
  <div class="grid grid-2">
    <?php if (isset($cfg['cols']['code'])): ?>
    <label class="field"><span>Código</span>
      <input class="input<?= isset($errors['code']) ? ' invalid' : '' ?>" name="code" value="<?= $e($values['code'] ?? '') ?>" maxlength="30" required>
      <?php if (isset($errors['code'])): ?><small class="error"><?= $e($errors['code']) ?></small><?php endif; ?>
    </label>
    <?php endif; ?>
    <label class="field"><span>Nombre</span>
      <input class="input<?= isset($errors['name']) ? ' invalid' : '' ?>" name="name" value="<?= $e($values['name'] ?? '') ?>" maxlength="120" required>
      <?php if (isset($errors['name'])): ?><small class="error"><?= $e($errors['name']) ?></small><?php endif; ?>
    </label>
    <?php if ($entity === 'tax-rates'): ?>
    <label class="field"><span>Tasa (%)</span>
      <input class="input<?= isset($errors['rate']) ? ' invalid' : '' ?>" name="rate" inputmode="decimal" value="<?= $e($values['rate'] ?? '0') ?>" required>
      <?php if (isset($errors['rate'])): ?><small class="error"><?= $e($errors['rate']) ?></small><?php endif; ?>
    </label>
    <?php endif; ?>
  </div>
  <?php if ($entity === 'tax-rates'): ?>
  <label class="check"><input type="checkbox" name="is_default" value="1"<?= !empty($values['is_default']) ? ' checked' : '' ?>><span><strong>Predeterminado</strong> <span class="muted">— se propone al crear productos nuevos.</span></span></label>
  <?php if (isset($errors['is_default'])): ?><small class="error"><?= $e($errors['is_default']) ?></small><?php endif; ?>
  <?php endif; ?>
  <label class="check"><input type="checkbox" name="is_active" value="1"<?= !empty($values['is_active']) ? ' checked' : '' ?>><span><strong>Activo</strong> <span class="muted">— los inactivos no se ofrecen en formularios nuevos.</span></span></label>
  <?php if (isset($errors['is_active'])): ?><small class="error"><?= $e($errors['is_active']) ?></small><?php endif; ?>
  <div class="form-actions">
    <button class="btn btn-primary" type="submit">Guardar</button>
    <a class="btn btn-ghost" href="<?= $e($url('/catalog/' . $entity)) ?>">Cancelar</a>
  </div>
</form>
