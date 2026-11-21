<?php $err = static fn (string $k) => isset($errors[$k]) ? '<small class="error">' . $e($errors[$k]) . '</small>' : ''; $val = static fn (string $k) => $e($values[$k] ?? ''); ?>
<div class="page-head"><div><h1><?= $e($title) ?></h1></div><a class="btn btn-ghost" href="<?= $e($url('/expenses')) ?>">Volver</a></div>
<form method="post" action="<?= $e($url('/expenses')) ?>" class="card form form-wide"><?= $csrf() ?>
  <div class="grid grid-2">
    <label class="field"><span>Categoría</span><select class="input<?= isset($errors['category_id']) ? ' invalid' : '' ?>" name="category_id" required><option value="">Selecciona…</option>
      <?php foreach ($categories as $c): ?><option value="<?= $e($c['id']) ?>"<?= (string) ($values['category_id'] ?? '') === (string) $c['id'] ? ' selected' : '' ?>><?= $e($c['name']) ?></option><?php endforeach; ?></select><?= $err('category_id') ?></label>
    <label class="field"><span>Se paga desde</span><select class="input<?= isset($errors['account_id']) ? ' invalid' : '' ?>" name="account_id" required><option value="">Selecciona…</option>
      <?php foreach ($accounts as $a): ?><option value="<?= $e($a['id']) ?>"<?= (string) ($values['account_id'] ?? '') === (string) $a['id'] ? ' selected' : '' ?>><?= $e($a['name']) ?> (<?= $e($money($a['balance'])) ?>)</option><?php endforeach; ?></select><?= $err('account_id') ?></label>
    <label class="field"><span>Importe</span><input class="input<?= isset($errors['amount']) ? ' invalid' : '' ?>" name="amount" inputmode="decimal" value="<?= $val('amount') ?>" required><?= $err('amount') ?></label>
    <label class="field"><span>Fecha</span><input class="input<?= isset($errors['expense_date']) ? ' invalid' : '' ?>" type="date" name="expense_date" value="<?= $val('expense_date') ?>"><?= $err('expense_date') ?></label>
  </div>
  <label class="field"><span>Descripción</span><input class="input<?= isset($errors['description']) ? ' invalid' : '' ?>" name="description" maxlength="255" value="<?= $val('description') ?>" required><?= $err('description') ?></label>
  <div class="grid grid-2">
    <label class="field"><span>Proveedor (opcional)</span><select class="input" name="supplier_id"><option value="">—</option>
      <?php foreach ($suppliers as $s): ?><option value="<?= $e($s['id']) ?>"<?= (string) ($values['supplier_id'] ?? '') === (string) $s['id'] ? ' selected' : '' ?>><?= $e($s['name']) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Producto (opcional)</span><select class="input" name="product_id"><option value="">—</option>
      <?php foreach ($products as $p): ?><option value="<?= $e($p['id']) ?>"<?= (string) ($values['product_id'] ?? '') === (string) $p['id'] ? ' selected' : '' ?>><?= $e($p['name']) ?></option><?php endforeach; ?></select></label>
  </div>
  <div class="form-actions"><button class="btn btn-primary" type="submit">Registrar gasto</button><a class="btn btn-ghost" href="<?= $e($url('/expenses')) ?>">Cancelar</a></div>
</form>
