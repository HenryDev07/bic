<?php $action = $editing === null ? '/sales' : '/sales/' . (int) $editing['id']; ?>
<div class="page-head"><div><h1><?= $e($title) ?></h1></div><a class="btn btn-ghost" href="<?= $e($url($editing === null ? '/sales' : '/sales/' . (int) $editing['id'])) ?>">Volver</a></div>
<form method="post" action="<?= $e($url($action)) ?>" class="card form form-wide"><?= $csrf() ?>
  <div class="grid grid-2">
    <label class="field"><span>Cliente (vacío = venta de mostrador)</span><select class="input<?= isset($errors['customer_id']) ? ' invalid' : '' ?>" name="customer_id"><option value="">Mostrador</option>
      <?php foreach ($customers as $c): ?><option value="<?= $e($c['id']) ?>"<?= (string) ($values['customer_id'] ?? '') === (string) $c['id'] ? ' selected' : '' ?>><?= $e($c['name']) ?></option><?php endforeach; ?></select>
      <?php if (isset($errors['customer_id'])): ?><small class="error"><?= $e($errors['customer_id']) ?></small><?php endif; ?></label>
    <label class="field"><span>Almacén de salida</span><select class="input<?= isset($errors['warehouse_id']) ? ' invalid' : '' ?>" name="warehouse_id" required>
      <?php foreach ($warehouses as $w): ?><option value="<?= $e($w['id']) ?>"<?= (string) ($values['warehouse_id'] ?? '') === (string) $w['id'] ? ' selected' : '' ?>><?= $e($w['name']) ?></option><?php endforeach; ?></select>
      <?php if (isset($errors['warehouse_id'])): ?><small class="error"><?= $e($errors['warehouse_id']) ?></small><?php endif; ?></label>
  </div>
  <label class="field"><span>Notas</span><input class="input" name="notes" maxlength="500" value="<?= $e($values['notes'] ?? '') ?>"></label>
  <div class="form-actions"><button class="btn btn-primary" type="submit">Guardar</button></div>
</form>
