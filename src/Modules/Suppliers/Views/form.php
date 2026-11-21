<?php
$action = $editing === null ? '/suppliers' : '/suppliers/' . (int) $editing['id'];
$f = static function (string $k, string $label, int $max, string $type = 'text', bool $req = false) use ($values, $errors, $e): string {
    $inv = isset($errors[$k]) ? ' invalid' : '';
    return '<label class="field"><span>' . $e($label) . '</span><input class="input' . $inv . '" type="' . $type . '" name="' . $k . '" value="' . $e($values[$k] ?? '') . '" maxlength="' . $max . '"' . ($req ? ' required' : '') . '>'
        . (isset($errors[$k]) ? '<small class="error">' . $e($errors[$k]) . '</small>' : '') . '</label>';
};
?>
<div class="page-head"><div><h1><?= $e($title) ?></h1></div><a class="btn btn-ghost" href="<?= $e($url($editing === null ? '/suppliers' : '/suppliers/' . (int) $editing['id'])) ?>">Volver</a></div>
<form method="post" action="<?= $e($url($action)) ?>" class="card form form-wide">
  <?= $csrf() ?>
  <div class="grid grid-2">
    <?= $f('name', 'Nombre', 190, 'text', true) ?><?= $f('tax_id', 'Identificación fiscal', 50) ?>
    <?= $f('contact_name', 'Contacto', 150) ?><?= $f('email', 'Correo', 190, 'email') ?>
    <?= $f('phone', 'Teléfono', 50) ?><?= $f('country', 'País', 80) ?>
    <?= $f('currency', 'Moneda habitual (3 letras)', 3) ?><?= $f('lead_time_days', 'Plazo de entrega (días)', 4) ?>
  </div>
  <?= $f('payment_terms', 'Condiciones de pago', 120) ?>
  <label class="field"><span>Notas</span><textarea class="input" name="notes" rows="2" maxlength="2000"><?= $e($values['notes'] ?? '') ?></textarea></label>
  <label class="check"><input type="checkbox" name="is_active" value="1"<?= !empty($values['is_active']) ? ' checked' : '' ?>><span><strong>Activo</strong></span></label>
  <div class="form-actions"><button class="btn btn-primary" type="submit">Guardar</button><a class="btn btn-ghost" href="<?= $e($url('/suppliers')) ?>">Cancelar</a></div>
</form>
