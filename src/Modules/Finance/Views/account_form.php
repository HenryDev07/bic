<?php $action = $editing === null ? '/finance/accounts' : '/finance/accounts/' . (int) $editing['id']; ?>
<div class="page-head"><div><h1><?= $e($title) ?></h1></div><a class="btn btn-ghost" href="<?= $e($url('/finance')) ?>">Volver</a></div>
<form method="post" action="<?= $e($url($action)) ?>" class="card form form-wide"><?= $csrf() ?>
  <div class="grid grid-2">
    <label class="field"><span>Nombre</span><input class="input<?= isset($errors['name']) ? ' invalid' : '' ?>" name="name" value="<?= $e($values['name'] ?? '') ?>" maxlength="120" required>
      <?php if (isset($errors['name'])): ?><small class="error"><?= $e($errors['name']) ?></small><?php endif; ?></label>
    <label class="field"><span>Tipo</span><select class="input" name="type">
      <?php foreach (['cash' => 'Efectivo', 'bank' => 'Banco', 'wallet' => 'Billetera digital', 'other' => 'Otra'] as $k => $l): ?><option value="<?= $e($k) ?>"<?= ($values['type'] ?? 'cash') === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></label>
  </div>
  <label class="field"><span>Saldo inicial</span><input class="input<?= isset($errors['opening_balance']) ? ' invalid' : '' ?>" name="opening_balance" inputmode="decimal" value="<?= $e($values['opening_balance'] ?? '0') ?>"<?= $locked ? ' readonly' : '' ?>>
    <small class="muted"><?= $locked ? 'Bloqueado: la cuenta ya tiene movimientos. Usa un ajuste.' : 'Solo editable mientras la cuenta no tenga movimientos.' ?> Las cuentas usan la moneda base del negocio.</small>
    <?php if (isset($errors['opening_balance'])): ?><small class="error"><?= $e($errors['opening_balance']) ?></small><?php endif; ?></label>
  <label class="check"><input type="checkbox" name="is_active" value="1"<?= !empty($values['is_active']) ? ' checked' : '' ?>><span><strong>Activa</strong></span></label>
  <?php if (isset($errors['is_active'])): ?><small class="error"><?= $e($errors['is_active']) ?></small><?php endif; ?>
  <div class="form-actions"><button class="btn btn-primary" type="submit">Guardar</button><a class="btn btn-ghost" href="<?= $e($url('/finance')) ?>">Cancelar</a></div>
</form>
