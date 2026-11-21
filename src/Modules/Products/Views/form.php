<?php
$action = $mode === 'create' ? '/products' : '/products/' . (int) $editing['id'];
$err = static fn (string $k) => $errors[$k] ?? null;
?>
<div class="page-head">
  <div><h1><?= $e($title) ?></h1><p class="muted">Los precios se guardan con historial; el impuesto se elige por producto.</p></div>
  <a class="btn btn-ghost" href="<?= $e($url($mode === 'create' ? '/products' : '/products/' . (int) $editing['id'])) ?>">Volver</a>
</div>
<form method="post" action="<?= $e($url($action)) ?>" class="card form form-wide">
  <?= $csrf() ?>
  <label class="field"><span>Nombre</span>
    <input class="input<?= $err('name') ? ' invalid' : '' ?>" name="name" value="<?= $e($values['name'] ?? '') ?>" maxlength="190" required>
    <?php if ($err('name')): ?><small class="error"><?= $e($err('name')) ?></small><?php endif; ?>
  </label>
  <label class="field"><span>Descripción (opcional)</span>
    <textarea class="input" name="description" rows="2" maxlength="2000"><?= $e($values['description'] ?? '') ?></textarea>
    <?php if ($err('description')): ?><small class="error"><?= $e($err('description')) ?></small><?php endif; ?>
  </label>

  <?php if ($mode === 'create'): ?>
  <div class="grid grid-3">
    <label class="field"><span>SKU</span>
      <input class="input<?= $err('sku') ? ' invalid' : '' ?>" name="sku" value="<?= $e($values['sku'] ?? '') ?>" maxlength="60" required>
      <?php if ($err('sku')): ?><small class="error"><?= $e($err('sku')) ?></small><?php endif; ?>
    </label>
    <label class="field"><span>Código de barras</span>
      <input class="input<?= $err('barcode') ? ' invalid' : '' ?>" name="barcode" value="<?= $e($values['barcode'] ?? '') ?>" maxlength="60">
      <?php if ($err('barcode')): ?><small class="error"><?= $e($err('barcode')) ?></small><?php endif; ?>
    </label>
    <label class="field"><span>Costo de referencia</span>
      <input class="input<?= $err('reference_cost') ? ' invalid' : '' ?>" name="reference_cost" inputmode="decimal" value="<?= $e($values['reference_cost'] ?? '0') ?>">
      <?php if ($err('reference_cost')): ?><small class="error"><?= $e($err('reference_cost')) ?></small><?php endif; ?>
    </label>
  </div>
  <?php endif; ?>

  <div class="grid grid-3">
    <label class="field"><span>Categoría</span>
      <select class="input" name="category_id"><option value="">— Sin categoría —</option>
        <?php foreach ($options['categories'] as $o): ?><option value="<?= $e($o['id']) ?>"<?= (string) ($values['category_id'] ?? '') === (string) $o['id'] ? ' selected' : '' ?>><?= $e($o['name']) ?></option><?php endforeach; ?>
      </select>
      <?php if ($err('category_id')): ?><small class="error"><?= $e($err('category_id')) ?></small><?php endif; ?>
    </label>
    <label class="field"><span>Marca</span>
      <select class="input" name="brand_id"><option value="">— Sin marca —</option>
        <?php foreach ($options['brands'] as $o): ?><option value="<?= $e($o['id']) ?>"<?= (string) ($values['brand_id'] ?? '') === (string) $o['id'] ? ' selected' : '' ?>><?= $e($o['name']) ?></option><?php endforeach; ?>
      </select>
      <?php if ($err('brand_id')): ?><small class="error"><?= $e($err('brand_id')) ?></small><?php endif; ?>
    </label>
    <label class="field"><span>Unidad</span>
      <select class="input<?= $err('unit_id') ? ' invalid' : '' ?>" name="unit_id" required><option value="">Selecciona…</option>
        <?php foreach ($options['units'] as $o): ?><option value="<?= $e($o['id']) ?>"<?= (string) ($values['unit_id'] ?? '') === (string) $o['id'] ? ' selected' : '' ?>><?= $e($o['code']) ?> — <?= $e($o['name']) ?></option><?php endforeach; ?>
      </select>
      <?php if ($err('unit_id')): ?><small class="error"><?= $e($err('unit_id')) ?></small><?php endif; ?>
    </label>
  </div>

  <div class="grid grid-3">
    <label class="field"><span>Precio de venta</span>
      <input class="input<?= $err('sale_price') ? ' invalid' : '' ?>" name="sale_price" inputmode="decimal" value="<?= $e($values['sale_price'] ?? '0') ?>" required>
      <?php if ($err('sale_price')): ?><small class="error"><?= $e($err('sale_price')) ?></small><?php endif; ?>
    </label>
    <label class="field"><span>Precio promocional (opcional)</span>
      <input class="input<?= $err('promo_price') ? ' invalid' : '' ?>" name="promo_price" inputmode="decimal" value="<?= $e($values['promo_price'] ?? '') ?>">
      <?php if ($err('promo_price')): ?><small class="error"><?= $e($err('promo_price')) ?></small><?php endif; ?>
    </label>
    <label class="field"><span>Stock mínimo</span>
      <input class="input<?= $err('min_stock') ? ' invalid' : '' ?>" name="min_stock" inputmode="decimal" value="<?= $e($values['min_stock'] ?? '0') ?>">
      <?php if ($err('min_stock')): ?><small class="error"><?= $e($err('min_stock')) ?></small><?php endif; ?>
    </label>
  </div>

  <div class="grid grid-2">
    <label class="field"><span>Impuesto</span>
      <select class="input<?= $err('tax_rate_id') ? ' invalid' : '' ?>" name="tax_rate_id" required>
        <?php foreach ($options['taxes'] as $o): ?><option value="<?= $e($o['id']) ?>"<?= (string) ($values['tax_rate_id'] ?? '') === (string) $o['id'] ? ' selected' : '' ?>><?= $e($o['name']) ?> (<?= $e($money($o['rate'], 4)) ?> %)</option><?php endforeach; ?>
      </select>
      <?php if ($err('tax_rate_id')): ?><small class="error"><?= $e($err('tax_rate_id')) ?></small><?php endif; ?>
    </label>
    <label class="field"><span>Estado</span>
      <select class="input" name="status">
        <?php foreach ($statuses as $k => $label): ?><option value="<?= $e($k) ?>"<?= ($values['status'] ?? 'active') === $k ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?>
      </select>
    </label>
  </div>
  <label class="check"><input type="checkbox" name="price_includes_tax" value="1"<?= !empty($values['price_includes_tax']) ? ' checked' : '' ?>><span><strong>El precio ya incluye el impuesto</strong> <span class="muted">— si no, el impuesto se suma al precio.</span></span></label>

  <div class="form-actions">
    <button class="btn btn-primary" type="submit"><?= $mode === 'create' ? 'Crear producto' : 'Guardar cambios' ?></button>
    <a class="btn btn-ghost" href="<?= $e($url($mode === 'create' ? '/products' : '/products/' . (int) $editing['id'])) ?>">Cancelar</a>
  </div>
</form>
