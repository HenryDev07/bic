<?php $sid = (int) $s['id']; ?>
<div class="page-head">
  <div><h1><?= $e($s['name']) ?></h1><p class="muted"><?= (int) $s['is_active'] === 1 ? '<span class="badge ok">Activo</span>' : '<span class="badge off">Inactivo</span>' ?>
    <?= $e($s['country'] ?? '') ?> · moneda <?= $e($s['currency']) ?><?= $s['payment_terms'] ? ' · ' . $e($s['payment_terms']) : '' ?></p></div>
  <div class="actions"><a class="btn btn-ghost" href="<?= $e($url('/suppliers')) ?>">Volver</a>
    <?php if ($can('suppliers.edit')): ?><a class="btn btn-primary" href="<?= $e($url('/suppliers/' . $sid . '/edit')) ?>">Editar</a><?php endif; ?></div>
</div>
<div class="card">
  <p><?= $e($s['contact_name'] ?? 'Sin contacto') ?><?= $s['email'] ? ' · ' . $e($s['email']) : '' ?><?= $s['phone'] ? ' · ' . $e($s['phone']) : '' ?></p>
  <?php if ($s['notes']): ?><p class="muted"><?= nl2br($e($s['notes'])) ?></p><?php endif; ?>
</div>
<?php if ($canCost): ?>
<?php if ($can('suppliers.edit')): ?>
<form method="post" action="<?= $e($url('/suppliers/' . $sid . '/quotes')) ?>" class="card form">
  <?= $csrf() ?><h2>Registrar cotización</h2>
  <p class="muted">Los precios se guardan como historial; la última cotización de cada proveedor es la vigente. Flete y otros costos son por unidad.</p>
  <div class="grid grid-3">
    <label class="field"><span>Producto</span><select class="input" name="product_id" required><option value="">Selecciona…</option>
      <?php foreach ($products as $p): ?><option value="<?= $e($p['id']) ?>"><?= $e($p['name']) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Precio unitario</span><input class="input" name="unit_price" inputmode="decimal" required></label>
    <label class="field"><span>Cantidad mínima</span><input class="input" name="min_quantity" inputmode="decimal" value="1"></label>
    <label class="field"><span>Flete por unidad</span><input class="input" name="freight_cost" inputmode="decimal" value="0"></label>
    <label class="field"><span>Otros costos por unidad</span><input class="input" name="other_cost" inputmode="decimal" value="0"></label>
    <label class="field"><span>Moneda</span><input class="input" name="currency" maxlength="3" value="<?= $e($s['currency']) ?>"></label>
    <label class="field"><span>Tipo de cambio a moneda base</span><input class="input" name="exchange_rate" inputmode="decimal" placeholder="1 si es la moneda base"></label>
    <label class="field"><span>Fecha de cotización</span><input class="input" type="date" name="quoted_on"></label>
    <label class="field"><span>Vigente hasta</span><input class="input" type="date" name="valid_until"></label>
  </div>
  <div class="form-actions"><button class="btn btn-primary" type="submit">Guardar cotización</button></div>
</form>
<?php endif; ?>
<div class="card table-wrap">
  <h2 class="card-title">Historial de cotizaciones</h2>
  <?php if ($quotes === []): ?><p class="empty-msg muted">Sin cotizaciones.</p><?php else: ?>
  <table class="table">
    <thead><tr><th>Fecha</th><th>Producto</th><th class="right">Precio</th><th class="right">Flete + otros</th><th>Cambio</th><th>Mín.</th><th>Vigencia</th></tr></thead>
    <tbody>
    <?php foreach ($quotes as $q): ?>
      <tr><td><?= $e($q['quoted_on']) ?></td><td><a href="<?= $e($url('/products/' . (int) $q['product_id'])) ?>"><?= $e($q['product_name']) ?></a></td>
        <td class="right"><?= $e($money($q['unit_price'], 4)) ?> <?= $e($q['currency']) ?></td>
        <td class="right"><?= $e($money(\Bic\Core\Money\Decimal::from($q['freight_cost'], 4)->add(\Bic\Core\Money\Decimal::from($q['other_cost'], 4)), 4)) ?></td>
        <td><?= $e(rtrim(rtrim(\Bic\Core\Money\Decimal::from($q['exchange_rate'], 8)->toString(), '0'), '.')) ?></td>
        <td><?= $e($money($q['min_quantity'], 3)) ?></td><td><?= $e($q['valid_until'] ?? '—') ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php endif; ?>
