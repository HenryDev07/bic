<?php $action = $editing === null ? '/research' : '/research/' . (int) $editing['id'];
$err = static fn (string $k) => isset($errors[$k]) ? '<small class="error">' . $e($errors[$k]) . '</small>' : ''; $val = static fn (string $k) => $e($values[$k] ?? ''); ?>
<div class="page-head"><div><h1><?= $e($title) ?></h1></div><a class="btn btn-ghost" href="<?= $e($url($editing === null ? '/research' : '/research/' . (int) $editing['id'])) ?>">Volver</a></div>
<form method="post" action="<?= $e($url($action)) ?>" class="card form form-wide"><?= $csrf() ?>
  <div class="grid grid-2">
    <label class="field"><span>Nombre</span><input class="input<?= isset($errors['name']) ? ' invalid' : '' ?>" name="name" maxlength="190" value="<?= $val('name') ?>" required><?= $err('name') ?></label>
    <label class="field"><span>Estado</span><select class="input" name="status"><?php foreach ($statuses as $k => $l): ?><option value="<?= $e($k) ?>"<?= ($values['status'] ?? 'idea') === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select><?= $err('status') ?></label>
    <label class="field"><span>Costo estimado (USD)</span><input class="input<?= isset($errors['estimated_cost']) ? ' invalid' : '' ?>" name="estimated_cost" inputmode="decimal" value="<?= $val('estimated_cost') ?>"><?= $err('estimated_cost') ?></label>
    <label class="field"><span>Precio objetivo (USD)</span><input class="input<?= isset($errors['target_price']) ? ' invalid' : '' ?>" name="target_price" inputmode="decimal" value="<?= $val('target_price') ?>"><?= $err('target_price') ?></label>
  </div>
  <label class="field"><span>Producto del catálogo (si ya existe)</span><select class="input" name="product_id"><option value="">—</option>
    <?php foreach ($products as $p): ?><option value="<?= $e($p['id']) ?>"<?= (string) ($values['product_id'] ?? '') === (string) $p['id'] ? ' selected' : '' ?>><?= $e($p['name']) ?></option><?php endforeach; ?></select></label>
  <label class="field"><span>Notas</span><textarea class="input" name="notes" rows="3" maxlength="4000"><?= $val('notes') ?></textarea><?= $err('notes') ?></label>
  <div class="form-actions"><button class="btn btn-primary" type="submit">Guardar</button></div>
</form>
