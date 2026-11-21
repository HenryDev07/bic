<?php $pid = (int) $p['id']; ?>
<div class="page-head">
  <div><h1><?= $e($p['name']) ?></h1>
    <p class="muted"><?= $p['status'] === 'active' ? '<span class="badge ok">Activo</span>' : '<span class="badge off">' . $e($statuses[$p['status']] ?? $p['status']) . '</span>' ?>
      <?= $e($p['category_name'] ?? 'Sin categoría') ?><?= $p['brand_name'] ? ' · ' . $e($p['brand_name']) : '' ?> · unidad <?= $e($p['unit_code']) ?></p></div>
  <div class="actions">
    <a class="btn btn-ghost" href="<?= $e($url('/products')) ?>">Volver</a>
    <?php if ($can('products.edit')): ?><a class="btn btn-primary" href="<?= $e($url('/products/' . $pid . '/edit')) ?>">Editar</a><?php endif; ?>
  </div>
</div>

<div class="grid grid-3">
  <div class="card stat"><span class="stat-label">Precio de venta <?= (int) $p['price_includes_tax'] === 1 ? '(impuesto incluido)' : '(más impuesto)' ?></span><span class="stat-value"><?= $e($money($p['sale_price'])) ?></span>
    <span class="muted"><?= $e($p['tax_name']) ?> · <?= $e($money($p['tax_rate'], 4)) ?> %<?= $p['promo_price'] !== null ? ' · promo ' . $e($money($p['promo_price'])) : '' ?></span></div>
  <?php if ($showCost): ?>
  <div class="card stat"><span class="stat-label">Costo de referencia</span><span class="stat-value"><?= $e($money($p['reference_cost'], 4)) ?></span><span class="muted">variante predeterminada</span></div>
  <div class="card stat"><span class="stat-label">Margen actual (sobre precio neto)</span><span class="stat-value"><?= $calc['margin'] === null ? '—' : $e($money($calc['margin'])) . ' %' ?></span>
    <span class="muted">Markup: <?= $calc['markup'] === null ? '—' : $e($money($calc['markup'])) . ' %' ?></span></div>
  <?php endif; ?>
</div>

<?php if ($showCost && $calc !== null): ?>
<div class="card">
  <h2>Calculadora de precio</h2>
  <form class="toolbar" method="get" action="<?= $e($url('/products/' . $pid)) ?>">
    <label class="field"><span>Costos variables % (comisión, pasarela)</span><input class="input" name="v" inputmode="decimal" value="<?= $e($calc['v']->toString()) ?>"></label>
    <label class="field"><span>Margen objetivo %</span><input class="input" name="m" inputmode="decimal" value="<?= $e($calc['m']->toString()) ?>"></label>
    <button class="btn btn-ghost" type="submit">Calcular</button>
  </form>
  <?php if ($calc['error']): ?><div class="alert alert-warning"><?= $e($calc['error']) ?></div><?php endif; ?>
  <div class="grid grid-3">
    <div class="stat"><span class="stat-label">Precio de equilibrio (ganancia = 0)</span><span class="stat-value stat-small"><?= $calc['breakEven'] === null ? '—' : $e($money($calc['breakEven'])) ?></span><span class="muted">Costo ÷ (1 − v)</span></div>
    <div class="stat"><span class="stat-label">Precio recomendado (neto de impuesto)</span><span class="stat-value stat-small"><?= $calc['recommended'] === null ? '—' : $e($money($calc['recommended'])) ?></span><span class="muted">Costo ÷ (1 − v − margen)</span></div>
    <div class="stat"><span class="stat-label">Precio de venta actual, neto</span><span class="stat-value stat-small"><?= $calc['net'] === null ? '—' : $e($money($calc['net'], 4)) ?></span><span class="muted">Sin impuesto</span></div>
  </div>
  <p class="muted">Los precios mínimos se redondean hacia arriba al centavo. El de equilibrio nunca incluye margen; el recomendado siempre parte de uno explícito.</p>
</div>
<?php endif; ?>

<div class="card table-wrap">
  <h2 class="card-title">Variantes</h2>
  <table class="table">
    <thead><tr><th>SKU</th><th>Código de barras</th><th>Descripción</th><?php if ($showCost): ?><th>Costo ref.</th><?php endif; ?><th>Estado</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($variants as $v): $canEdit = $can('products.edit'); ?>
      <tr>
        <?php if ($canEdit): $fid = 'vf' . (int) $v['id']; ?>
        <td><form id="<?= $e($fid) ?>" method="post" action="<?= $e($url('/products/' . $pid . '/variants/' . (int) $v['id'])) ?>"><?= $csrf() ?></form>
          <input class="input" form="<?= $e($fid) ?>" name="sku" value="<?= $e($v['sku']) ?>" maxlength="60" required aria-label="SKU"></td>
        <td><input class="input" form="<?= $e($fid) ?>" name="barcode" value="<?= $e($v['barcode']) ?>" maxlength="60" aria-label="Código de barras"></td>
        <td><input class="input" form="<?= $e($fid) ?>" name="variant_name" value="<?= $e($v['name']) ?>" maxlength="120" aria-label="Descripción"></td>
        <?php if ($showCost): ?><td><input class="input" form="<?= $e($fid) ?>" name="reference_cost" inputmode="decimal" value="<?= $e($money($v['reference_cost'], 4)) ?>" aria-label="Costo"></td><?php else: ?><input type="hidden" form="<?= $e($fid) ?>" name="reference_cost" value="<?= $e(\Bic\Core\Money\Decimal::from($v['reference_cost'], 4)->toString()) ?>"><?php endif; ?>
        <td><label class="check"><input type="checkbox" form="<?= $e($fid) ?>" name="is_active" value="1"<?= (int) $v['is_active'] === 1 ? ' checked' : '' ?>> Activa<?= (int) $v['is_default'] === 1 ? ' · principal' : '' ?></label></td>
        <td class="right"><button class="btn btn-ghost btn-sm" form="<?= $e($fid) ?>" type="submit">Guardar</button></td>
        <?php else: ?>
        <td><code><?= $e($v['sku']) ?></code></td><td><?= $e($v['barcode'] ?? '—') ?></td><td><?= $e($v['name'] ?? '—') ?></td>
        <?php if ($showCost): ?><td><?= $e($money($v['reference_cost'], 4)) ?></td><?php endif; ?>
        <td><?= (int) $v['is_active'] === 1 ? 'Activa' : 'Inactiva' ?></td><td></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($can('products.edit')): ?>
  <form method="post" action="<?= $e($url('/products/' . $pid . '/variants')) ?>" class="toolbar card-pad">
    <?= $csrf() ?>
    <input class="input" name="sku" placeholder="SKU nueva variante" maxlength="60" required aria-label="SKU nueva variante">
    <input class="input" name="barcode" placeholder="Código de barras" maxlength="60" aria-label="Código de barras">
    <input class="input" name="variant_name" placeholder="Ej.: Talla M / Rojo" maxlength="120" aria-label="Descripción">
    <input class="input" name="reference_cost" placeholder="Costo" inputmode="decimal" aria-label="Costo">
    <button class="btn btn-primary" type="submit"><?= $icon('plus', 16) ?> Agregar variante</button>
  </form>
  <?php endif; ?>
</div>

<?php if ($showCost && $can('suppliers.view')): ?>
<div class="card table-wrap">
  <h2 class="card-title">Comparación de proveedores</h2>
  <?php if ($suppliers === []): ?>
    <p class="empty-msg muted">Sin cotizaciones. Regístralas desde la ficha del proveedor.</p>
  <?php else: ?>
  <table class="table">
    <thead><tr><th>Proveedor</th><th class="right">Precio</th><th class="right">Flete + otros</th><th class="right">Costo total unitario (USD)</th><th>Mín.</th><th>Cotizado</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($suppliers as $s): ?>
      <tr>
        <td><a href="<?= $e($url('/suppliers/' . (int) $s['supplier_id'])) ?>"><?= $e($s['supplier_name']) ?></a></td>
        <td class="right"><?= $e($money($s['unit_price'], 4)) ?> <?= $e($s['currency']) ?></td>
        <td class="right"><?= $e($money($s['extras'], 4)) ?></td>
        <td class="right"><strong><?= $e($money($s['landed_base'], 4)) ?></strong></td>
        <td><?= $e($money($s['min_quantity'], 3)) ?></td>
        <td><?= $e($s['quoted_on']) ?></td>
        <td><?= !empty($s['best']) ? '<span class="badge ok">Mejor costo</span>' : (!empty($s['expired']) ? '<span class="badge off">Vencida</span>' : '') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="muted card-pad">Costo total = (precio + flete + otros) × tipo de cambio de la cotización. Se compara la última cotización de cada proveedor activo.</p>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card table-wrap">
  <h2 class="card-title">Historial de precios y costos</h2>
  <?php if ($history === []): ?><p class="empty-msg muted">Sin cambios registrados.</p><?php else: ?>
  <table class="table">
    <thead><tr><th>Fecha</th><th>Concepto</th><th class="right">Antes</th><th class="right">Después</th><th>Usuario</th></tr></thead>
    <tbody>
    <?php $labels = ['sale_price' => 'Precio de venta', 'promo_price' => 'Promoción', 'reference_cost' => 'Costo de referencia']; ?>
    <?php foreach ($history as $h): if ($h['kind'] === 'reference_cost' && !$showCost) { continue; } ?>
      <tr>
        <td class="muted"><?= $e($local($h['created_at'])) ?></td>
        <td><?= $e($labels[$h['kind']] ?? $h['kind']) ?><?= $h['sku'] ? ' <span class="muted">(' . $e($h['sku']) . ')</span>' : '' ?></td>
        <td class="right"><?= $h['old_price'] === null ? '—' : $e($money($h['old_price'], $h['kind'] === 'reference_cost' ? 4 : 2)) ?></td>
        <td class="right"><?= $h['new_price'] === null ? 'sin promoción' : $e($money($h['new_price'], $h['kind'] === 'reference_cost' ? 4 : 2)) ?></td>
        <td><?= $e($h['user_name'] ?? '—') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
