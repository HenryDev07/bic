<?php $err = static fn (string $k) => $errors[$k] ?? null; $sel = static fn (mixed $a, mixed $b): string => (string) $a === (string) $b ? ' selected' : '';
$action = $editing === null ? '/purchases' : '/purchases/' . (int) $editing['id']; ?>
<div class="page-head"><div><h1><?= $e($title) ?></h1></div><a class="btn btn-ghost" href="<?= $e($url($editing === null ? '/purchases' : '/purchases/' . (int) $editing['id'])) ?>">Volver</a></div>
<form method="post" action="<?= $e($url($action)) ?>" class="card form form-wide"><?= $csrf() ?>
  <div class="grid grid-2">
    <label class="field"><span>Proveedor</span><select class="input<?= $err('supplier_id') ? ' invalid' : '' ?>" name="supplier_id" required><option value="">Selecciona…</option>
      <?php foreach ($suppliers as $s): ?><option value="<?= $e($s['id']) ?>"<?= $sel($values['supplier_id'] ?? '', $s['id']) ?>><?= $e($s['name']) ?> (<?= $e($s['currency']) ?>)</option><?php endforeach; ?></select>
      <?php if ($err('supplier_id')): ?><small class="error"><?= $e($err('supplier_id')) ?></small><?php endif; ?></label>
    <label class="field"><span>Almacén de destino</span><select class="input<?= $err('warehouse_id') ? ' invalid' : '' ?>" name="warehouse_id" required>
      <?php foreach ($warehouses as $w): ?><option value="<?= $e($w['id']) ?>"<?= $sel($values['warehouse_id'] ?? '', $w['id']) ?>><?= $e($w['name']) ?></option><?php endforeach; ?></select>
      <?php if ($err('warehouse_id')): ?><small class="error"><?= $e($err('warehouse_id')) ?></small><?php endif; ?></label>
    <label class="field"><span>Moneda (vacío = la del proveedor)</span><input class="input<?= $err('currency') ? ' invalid' : '' ?>" name="currency" maxlength="3" value="<?= $e($values['currency'] ?? '') ?>">
      <?php if ($err('currency')): ?><small class="error"><?= $e($err('currency')) ?></small><?php endif; ?></label>
    <label class="field"><span>Tipo de cambio a moneda base (si la moneda es otra)</span><input class="input<?= $err('exchange_rate') ? ' invalid' : '' ?>" name="exchange_rate" inputmode="decimal" value="<?= $e($values['exchange_rate'] ?? '') ?>">
      <?php if ($err('exchange_rate')): ?><small class="error"><?= $e($err('exchange_rate')) ?></small><?php endif; ?></label>
    <label class="field"><span>Entrega esperada</span><input class="input<?= $err('expected_on') ? ' invalid' : '' ?>" type="date" name="expected_on" value="<?= $e($values['expected_on'] ?? '') ?>"></label>
  </div>
  <label class="field"><span>Notas</span><input class="input" name="notes" maxlength="500" value="<?= $e($values['notes'] ?? '') ?>"></label>
  <?php if ($err('status')): ?><small class="error"><?= $e($err('status')) ?></small><?php endif; ?>
  <div class="form-actions"><button class="btn btn-primary" type="submit">Guardar</button><a class="btn btn-ghost" href="<?= $e($url('/purchases')) ?>">Cancelar</a></div>
</form>
