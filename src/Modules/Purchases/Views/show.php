<?php $id = (int) $o['id']; $st = $o['status']; $cur = $o['currency']; $costsEditable = in_array($st, ['draft', 'ordered'], true) && $receipts === [];
$canReceive = $can('purchases.receive') && in_array($st, ['ordered', 'partial'], true); ?>
<div class="page-head">
  <div><h1><?= $e($o['po_number']) ?> <span class="badge<?= $st === 'received' ? ' ok' : ($st === 'cancelled' ? ' off' : '') ?>"><?= $e($statuses[$st]) ?></span></h1>
    <p class="muted"><a href="<?= $e($url('/suppliers/' . (int) $o['supplier_id'])) ?>"><?= $e($o['supplier_name']) ?></a> · destino: <?= $e($o['warehouse_name']) ?> · <?= $e($cur) ?><?= $cur !== 'USD' ? ' (cambio ' . $e(rtrim(rtrim(\Bic\Core\Money\Decimal::from($o['exchange_rate'], 8)->toString(), '0'), '.')) . ')' : '' ?><?= $o['expected_on'] ? ' · entrega esperada ' . $e($o['expected_on']) : '' ?></p></div>
  <div class="actions"><a class="btn btn-ghost" href="<?= $e($url('/purchases')) ?>">Volver</a>
    <?php if ($st === 'draft' && $can('purchases.create')): ?><a class="btn btn-ghost" href="<?= $e($url('/purchases/' . $id . '/edit')) ?>">Editar encabezado</a>
      <form method="post" class="inline-form" action="<?= $e($url('/purchases/' . $id . '/place')) ?>"><?= $csrf() ?><button class="btn btn-primary" type="submit">Enviar orden</button></form><?php endif; ?></div>
</div>
<?php if ($st === 'cancelled'): ?><div class="alert alert-warning">Cancelada: <?= $e($o['cancel_reason']) ?></div><?php endif; ?>

<div class="grid grid-3">
  <div class="card stat"><span class="stat-label">Total de la orden (<?= $e($cur) ?>)</span><span class="stat-value stat-small"><?= $e($money($totals['total_doc'])) ?></span><span class="muted">Subtotal <?= $e($money($totals['subtotal'])) ?> + impuesto <?= $e($money($totals['tax'])) ?></span></div>
  <div class="card stat"><span class="stat-label">Total a pagar (USD)</span><span class="stat-value stat-small"><?= $e($money($totals['payable'])) ?></span><span class="muted">Mercancía <?= $e($money($totals['goods_base'])) ?> + costos de llegada <?= $e($money($totals['costs_base'])) ?></span></div>
  <div class="card stat"><span class="stat-label">Pendiente de pago</span><span class="stat-value stat-small"><?= $e($money($totals['due'])) ?></span><span class="muted">Pagado <?= $e($money($totals['paid'])) ?></span></div>
</div>

<?php if ($canReceive): ?><form method="post" action="<?= $e($url('/purchases/' . $id . '/receive')) ?>"><?= $csrf() ?><?php endif; ?>
<div class="card table-wrap"><h2 class="card-title">Líneas</h2>
<?php if ($lines === []): ?><p class="empty-msg muted">Agrega productos a la orden.</p><?php else: ?>
  <table class="table"><thead><tr><th>Producto</th><th class="right">Cantidad</th><th class="right">Recibido</th><th class="right">Precio (<?= $e($cur) ?>)</th><th class="right">Impuesto</th><th class="right">Subtotal</th><?php if ($canReceive): ?><th>Recibir ahora</th><?php endif; ?><th></th></tr></thead><tbody>
  <?php foreach ($lines as $l): $pending = \Bic\Core\Money\Decimal::from($l['quantity'], 3)->sub(\Bic\Core\Money\Decimal::from($l['received_quantity'], 3)); ?>
    <tr><td><strong><?= $e($l['product_name']) ?></strong><br><span class="muted"><code><?= $e($l['sku']) ?></code></span></td>
      <td class="right"><?= $e($money($l['quantity'], 3)) ?></td><td class="right"><?= $e($money($l['received_quantity'], 3)) ?></td>
      <td class="right"><?= $e($money($l['unit_price'], 4)) ?></td>
      <td class="right"><?= $e($money($l['tax_rate'], 2)) ?> % <span class="muted"><?= (int) $l['tax_recoverable'] === 1 ? '(recuperable)' : '(al costo)' ?></span></td>
      <td class="right"><?= $e($money($l['line_subtotal'])) ?></td>
      <?php if ($canReceive): ?><td><?php if ($pending->isPositive()): ?><input class="input" name="qty[<?= (int) $l['id'] ?>]" inputmode="decimal" placeholder="máx. <?= $e($pending->format()) ?>" aria-label="Cantidad a recibir"><?php else: ?><span class="badge ok">Completa</span><?php endif; ?></td><?php endif; ?>
      <td class="right"><?php if ($st === 'draft' && $can('purchases.create')): ?>
        <button class="btn btn-ghost btn-sm" type="submit" formmethod="post" formaction="<?= $e($url('/purchases/' . $id . '/lines/' . (int) $l['id'] . '/delete')) ?>" form="rm<?= (int) $l['id'] ?>">Quitar</button><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table>
<?php endif; ?></div>
<?php if ($canReceive): ?>
  <div class="card form"><h2>Registrar recepción</h2>
    <p class="muted">Indica lo que llegó. El costo puesto en almacén incluye el precio (× tipo de cambio), el impuesto no recuperable y la parte de los costos de llegada proporcional al valor de cada línea.</p>
    <div class="grid grid-2"><label class="field"><span>Fecha de recepción</span><input class="input" type="date" name="received_on" value="<?= $e($today) ?>"></label>
      <label class="field"><span>Notas</span><input class="input" name="notes" maxlength="255"></label></div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Recibir mercancía</button></div></div></form>
<?php endif; ?>
<?php if ($st === 'draft' && $can('purchases.create')): foreach ($lines as $l): ?>
  <form id="rm<?= (int) $l['id'] ?>" method="post" action="<?= $e($url('/purchases/' . $id . '/lines/' . (int) $l['id'] . '/delete')) ?>"><?= $csrf() ?></form>
<?php endforeach; ?>
<form method="post" action="<?= $e($url('/purchases/' . $id . '/lines')) ?>" class="card form"><?= $csrf() ?><h2>Agregar línea</h2>
  <p class="muted">Precio vacío = última cotización del proveedor en <?= $e($cur) ?>. Impuesto vacío = el del producto. Desmarca «recuperable» si el impuesto no se puede acreditar: se sumará al costo.</p>
  <div class="grid grid-3">
    <label class="field"><span>Variante</span><select class="input" name="variant_id" required><option value="">Selecciona…</option>
      <?php foreach ($variants as $v): ?><option value="<?= $e($v['id']) ?>"><?= $e($v['name']) ?> — <?= $e($v['sku']) ?><?= $v['variant_name'] ? ' (' . $e($v['variant_name']) . ')' : '' ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Cantidad</span><input class="input" name="quantity" inputmode="decimal" required></label>
    <label class="field"><span>Precio unitario (<?= $e($cur) ?>)</span><input class="input" name="unit_price" inputmode="decimal"></label>
    <label class="field"><span>Impuesto %</span><input class="input" name="tax_rate" inputmode="decimal"></label>
    <label class="check"><input type="checkbox" name="tax_recoverable" value="1" checked><span>Impuesto recuperable</span></label></div>
  <div class="form-actions"><button class="btn btn-primary" type="submit"><?= $icon('plus', 16) ?> Agregar</button></div></form>
<?php endif; ?>

<div class="card table-wrap"><h2 class="card-title">Costos de llegada (USD, se capitalizan al costo)</h2>
<?php if ($costLines === []): ?><p class="empty-msg muted">Sin costos de llegada.</p><?php else: ?>
  <table class="table"><tbody><?php foreach ($costLines as $c): ?>
    <tr><td><?= $e($costKinds[$c['kind']] ?? $c['kind']) ?></td><td><?= $e($c['description'] ?? '') ?></td><td class="right"><?= $e($money($c['amount'])) ?></td>
      <td class="right"><?php if ($costsEditable && $can('purchases.create')): ?><form method="post" class="inline-form" action="<?= $e($url('/purchases/' . $id . '/costs/' . (int) $c['id'] . '/delete')) ?>"><?= $csrf() ?><button class="btn btn-ghost btn-sm" type="submit">Quitar</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table><?php endif; ?>
<?php if ($costsEditable && $can('purchases.create')): ?>
  <form method="post" action="<?= $e($url('/purchases/' . $id . '/costs')) ?>" class="toolbar card-pad"><?= $csrf() ?>
    <select class="input" name="kind" aria-label="Tipo"><?php foreach ($costKinds as $k => $l): ?><option value="<?= $e($k) ?>"><?= $e($l) ?></option><?php endforeach; ?></select>
    <input class="input" name="description" placeholder="Descripción" maxlength="150" aria-label="Descripción"><input class="input" name="amount" inputmode="decimal" placeholder="Importe USD" required aria-label="Importe">
    <button class="btn btn-ghost" type="submit">Agregar costo</button></form>
<?php elseif ($receipts !== []): ?><p class="muted card-pad">Bloqueado: ya hay mercancía recibida y el reparto de costos quedó fijado.</p><?php endif; ?></div>

<div class="card table-wrap"><h2 class="card-title">Recepciones</h2>
<?php if ($receipts === []): ?><p class="empty-msg muted">Aún no se ha recibido mercancía.</p><?php else: ?>
  <table class="table"><thead><tr><th>Recepción</th><th>Fecha</th><th>Producto</th><th class="right">Cantidad</th><th class="right">Costo puesto en almacén</th></tr></thead><tbody>
  <?php foreach ($receipts as $r): foreach ($r['lines'] as $i => $rl): ?>
    <tr><td><?= $i === 0 ? '#' . (int) $r['id'] : '' ?></td><td><?= $i === 0 ? $e($r['received_on']) : '' ?></td><td><?= $e($rl['product_name']) ?> <span class="muted"><code><?= $e($rl['sku']) ?></code></span></td>
      <td class="right"><?= $e($money($rl['quantity'], 3)) ?></td><td class="right"><?= $e($money($rl['unit_cost'], 4)) ?></td></tr>
  <?php endforeach; endforeach; ?></tbody></table><?php endif; ?></div>

<div class="card table-wrap"><h2 class="card-title">Pagos</h2>
<?php if ($payments === []): ?><p class="empty-msg muted">Sin pagos.</p><?php else: ?>
  <table class="table"><tbody><?php foreach ($payments as $p): $v = $p['voided_at'] !== null; ?>
    <tr<?= $v ? ' class="voided"' : '' ?>><td class="muted"><?= $e($local($p['occurred_at'])) ?></td><td><?= $e($p['account_name']) ?></td><td><?= $e($p['notes'] ?? '') ?><?= $v ? ' <span class="badge off">Anulado</span> ' . $e($p['void_reason']) : '' ?></td>
      <td class="right"><?= $e($money($p['amount'])) ?></td>
      <td class="right"><?php if (!$v && $can('purchases.pay')): ?><form method="post" class="inline-form" action="<?= $e($url('/purchases/' . $id . '/payments/' . (int) $p['id'] . '/void')) ?>"><?= $csrf() ?><input class="input" name="reason" placeholder="Motivo" maxlength="255" required aria-label="Motivo"><button class="btn btn-ghost btn-sm" type="submit">Anular</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table><?php endif; ?>
<?php if ($can('purchases.pay') && !in_array($st, ['draft', 'cancelled'], true)): ?>
  <form method="post" action="<?= $e($url('/purchases/' . $id . '/payments')) ?>" class="toolbar card-pad"><?= $csrf() ?>
    <select class="input" name="account_id" required aria-label="Cuenta"><option value="">Cuenta de pago…</option><?php foreach ($accounts as $a): ?><option value="<?= $e($a['id']) ?>"><?= $e($a['name']) ?> (<?= $e($money($a['balance'])) ?>)</option><?php endforeach; ?></select>
    <input class="input" name="amount" inputmode="decimal" placeholder="Importe USD" required aria-label="Importe"><input class="input" name="notes" placeholder="Notas" maxlength="255" aria-label="Notas">
    <button class="btn btn-primary" type="submit">Registrar pago</button></form><?php endif; ?></div>

<?php if (in_array($st, ['draft', 'ordered'], true) && $receipts === [] && $can('purchases.cancel')): ?>
<form method="post" action="<?= $e($url('/purchases/' . $id . '/cancel')) ?>" class="toolbar"><?= $csrf() ?>
  <input class="input" name="reason" placeholder="Motivo de la cancelación" maxlength="255" required aria-label="Motivo"><button class="btn btn-ghost" type="submit">Cancelar orden</button></form>
<?php endif; ?>
