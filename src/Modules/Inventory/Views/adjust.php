<?php $err = static fn (string $k) => $errors[$k] ?? null; $sel = static fn (mixed $a, mixed $b): string => (string) $a === (string) $b ? ' selected' : ''; ?>
<div class="page-head"><div><h1>Ajuste de stock</h1><p class="muted">Entradas y salidas manuales (con motivo) y transferencias entre almacenes. Las compras se reciben desde la orden de compra.</p></div>
  <a class="btn btn-ghost" href="<?= $e($url('/inventory')) ?>">Volver</a></div>
<form method="post" action="<?= $e($url('/inventory/adjust')) ?>" class="card form form-wide"><?= $csrf() ?>
  <div class="grid grid-2">
    <label class="field"><span>Tipo</span><select class="input" name="type">
      <option value="in"<?= $sel($values['type'] ?? '', 'in') ?>>Entrada (ajuste positivo)</option><option value="out"<?= $sel($values['type'] ?? '', 'out') ?>>Salida (ajuste negativo)</option><option value="transfer"<?= $sel($values['type'] ?? '', 'transfer') ?>>Transferencia entre almacenes</option></select></label>
    <label class="field"><span>Variante</span><select class="input<?= $err('variant_id') ? ' invalid' : '' ?>" name="variant_id" required><option value="">Selecciona…</option>
      <?php foreach ($variants as $v): ?><option value="<?= $e($v['id']) ?>"<?= $sel($values['variant_id'] ?? '', $v['id']) ?>><?= $e($v['name']) ?> — <?= $e($v['sku']) ?><?= $v['variant_name'] ? ' (' . $e($v['variant_name']) . ')' : '' ?></option><?php endforeach; ?></select>
      <?php if ($err('variant_id')): ?><small class="error"><?= $e($err('variant_id')) ?></small><?php endif; ?></label>
    <label class="field"><span>Almacén (origen en transferencias)</span><select class="input<?= $err('warehouse_id') ? ' invalid' : '' ?>" name="warehouse_id" required><option value="">Selecciona…</option>
      <?php foreach ($warehouses as $w): ?><option value="<?= $e($w['id']) ?>"<?= $sel($values['warehouse_id'] ?? '', $w['id']) ?>><?= $e($w['name']) ?></option><?php endforeach; ?></select>
      <?php if ($err('warehouse_id')): ?><small class="error"><?= $e($err('warehouse_id')) ?></small><?php endif; ?></label>
    <label class="field"><span>Almacén destino (solo transferencias)</span><select class="input<?= $err('to_warehouse_id') ? ' invalid' : '' ?>" name="to_warehouse_id"><option value="">—</option>
      <?php foreach ($warehouses as $w): ?><option value="<?= $e($w['id']) ?>"<?= $sel($values['to_warehouse_id'] ?? '', $w['id']) ?>><?= $e($w['name']) ?></option><?php endforeach; ?></select>
      <?php if ($err('to_warehouse_id')): ?><small class="error"><?= $e($err('to_warehouse_id')) ?></small><?php endif; ?></label>
    <label class="field"><span>Cantidad</span><input class="input<?= $err('quantity') ? ' invalid' : '' ?>" name="quantity" inputmode="decimal" value="<?= $e($values['quantity'] ?? '') ?>" required>
      <?php if ($err('quantity')): ?><small class="error"><?= $e($err('quantity')) ?></small><?php endif; ?></label>
    <?php if ($showCost): ?><label class="field"><span>Costo unitario (solo entradas; vacío = costo promedio actual)</span><input class="input<?= $err('unit_cost') ? ' invalid' : '' ?>" name="unit_cost" inputmode="decimal" value="<?= $e($values['unit_cost'] ?? '') ?>">
      <?php if ($err('unit_cost')): ?><small class="error"><?= $e($err('unit_cost')) ?></small><?php endif; ?></label><?php endif; ?>
  </div>
  <label class="field"><span>Motivo (obligatorio)</span><input class="input<?= $err('reason') ? ' invalid' : '' ?>" name="reason" maxlength="255" value="<?= $e($values['reason'] ?? '') ?>" required>
    <?php if ($err('reason')): ?><small class="error"><?= $e($err('reason')) ?></small><?php endif; ?></label>
  <div class="form-actions"><button class="btn btn-primary" type="submit">Registrar</button><a class="btn btn-ghost" href="<?= $e($url('/inventory')) ?>">Cancelar</a></div>
</form>
