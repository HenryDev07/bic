<div class="page-head"><div><h1>Nuevo movimiento</h1><p class="muted">Cobros, pagos y gastos se registrarán desde sus propios módulos; aquí van capital, transferencias, otros ingresos y ajustes.</p></div>
  <a class="btn btn-ghost" href="<?= $e($url('/finance/ledger')) ?>">Volver</a></div>
<?php $err = static fn (string $k) => $errors[$k] ?? null; ?>
<form method="post" action="<?= $e($url('/finance/movements')) ?>" class="card form form-wide"><?= $csrf() ?>
  <div class="grid grid-2">
    <label class="field"><span>Tipo</span><select class="input" name="type">
      <?php foreach ($types as $k => $l): ?><option value="<?= $e($k) ?>"<?= ($values['type'] ?? '') === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select>
      <?php if ($err('type')): ?><small class="error"><?= $e($err('type')) ?></small><?php endif; ?></label>
    <label class="field"><span>Fecha</span><input class="input<?= $err('occurred_at') ? ' invalid' : '' ?>" type="date" name="occurred_on" value="<?= $e($values['occurred_on'] ?? '') ?>" required>
      <?php if ($err('occurred_at')): ?><small class="error"><?= $e($err('occurred_at')) ?></small><?php endif; ?></label>
    <label class="field"><span>Cuenta (origen en transferencias)</span><select class="input<?= $err('account_id') ? ' invalid' : '' ?>" name="account_id" required><option value="">Selecciona…</option>
      <?php foreach ($accounts as $a): ?><option value="<?= $e($a['id']) ?>"<?= (string) ($values['account_id'] ?? '') === (string) $a['id'] ? ' selected' : '' ?>><?= $e($a['name']) ?> (<?= $e($money($a['balance'])) ?>)</option><?php endforeach; ?></select>
      <?php if ($err('account_id')): ?><small class="error"><?= $e($err('account_id')) ?></small><?php endif; ?></label>
    <label class="field"><span>Cuenta destino (solo transferencias)</span><select class="input<?= $err('to_account_id') ? ' invalid' : '' ?>" name="to_account_id"><option value="">—</option>
      <?php foreach ($accounts as $a): ?><option value="<?= $e($a['id']) ?>"<?= (string) ($values['to_account_id'] ?? '') === (string) $a['id'] ? ' selected' : '' ?>><?= $e($a['name']) ?></option><?php endforeach; ?></select>
      <?php if ($err('to_account_id')): ?><small class="error"><?= $e($err('to_account_id')) ?></small><?php endif; ?></label>
    <label class="field"><span>Importe</span><input class="input<?= $err('amount') ? ' invalid' : '' ?>" name="amount" inputmode="decimal" value="<?= $e($values['amount'] ?? '') ?>" required>
      <?php if ($err('amount')): ?><small class="error"><?= $e($err('amount')) ?></small><?php endif; ?></label>
    <label class="field"><span>Socio (solo capital, opcional)</span><input class="input" name="partner_name" maxlength="150" value="<?= $e($values['partner_name'] ?? '') ?>"></label>
  </div>
  <label class="field"><span>Descripción / motivo (obligatorio en ajustes)</span><input class="input<?= $err('description') ? ' invalid' : '' ?>" name="description" maxlength="255" value="<?= $e($values['description'] ?? '') ?>">
    <?php if ($err('description')): ?><small class="error"><?= $e($err('description')) ?></small><?php endif; ?></label>
  <div class="form-actions"><button class="btn btn-primary" type="submit">Registrar</button><a class="btn btn-ghost" href="<?= $e($url('/finance/ledger')) ?>">Cancelar</a></div>
</form>
