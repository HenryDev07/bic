<?php use Bic\Core\Money\Decimal;
$id = (int) $o['id']; $st = $o['status']; $draft = $st === 'draft'; $open = !in_array($st, ['draft', 'delivered', 'cancelled'], true);
$kinds = ['deposit' => 'Anticipo', 'payment' => 'Cobro', 'refund' => 'Reembolso']; ?>
<div class="page-head">
  <div><h1><?= $e($o['order_number']) ?> <span class="badge<?= $st === 'delivered' ? ' ok' : ($st === 'cancelled' ? ' off' : '') ?>"><?= $e($statuses[$st]) ?></span></h1>
    <p class="muted"><?php if ($o['customer_id']): ?><a href="<?= $e($url('/customers/' . (int) $o['customer_id'])) ?>"><?= $e($o['customer_name']) ?></a><?php else: ?>Mostrador<?php endif; ?> · salida: <?= $e($o['warehouse_name']) ?><?= $o['delivered_at'] ? ' · entregado ' . $e($local($o['delivered_at'])) : '' ?></p></div>
  <div class="actions"><a class="btn btn-ghost" href="<?= $e($url('/sales')) ?>">Volver</a>
    <?php if ($draft && $can('sales.create')): ?><a class="btn btn-ghost" href="<?= $e($url('/sales/' . $id . '/edit')) ?>">Editar encabezado</a>
      <form method="post" class="inline-form" action="<?= $e($url('/sales/' . $id . '/submit')) ?>"><?= $csrf() ?><button class="btn btn-primary" type="submit">Enviar a pendiente</button></form><?php endif; ?>
    <?php if ($st === 'pending' && $can('sales.create')): ?><form method="post" class="inline-form" action="<?= $e($url('/sales/' . $id . '/confirm')) ?>"><?= $csrf() ?><button class="btn btn-primary" type="submit">Confirmar</button></form><?php endif; ?>
    <?php if ($st === 'confirmed' && $can('sales.edit')): ?><form method="post" class="inline-form" action="<?= $e($url('/sales/' . $id . '/prepare')) ?>"><?= $csrf() ?><button class="btn btn-primary" type="submit">Marcar preparado</button></form><?php endif; ?>
    <?php if ($open && $can('sales.edit')): ?><form method="post" class="inline-form" action="<?= $e($url('/sales/' . $id . '/deliver')) ?>"><?= $csrf() ?><button class="btn btn-primary" type="submit">Entregar</button></form><?php endif; ?></div>
</div>
<?php if ($st === 'cancelled'): ?><div class="alert alert-warning">Cancelado: <?= $e($o['cancel_reason']) ?></div><?php endif; ?>
<?php if ($open): ?><p class="muted">Reserva de stock: <?= $e(['pending' => 'desde pendiente', 'confirmed' => 'desde confirmado', 'never' => 'desactivada'][$reserveOn]) ?>.</p><?php endif; ?>

<div class="grid grid-3">
  <div class="card stat"><span class="stat-label">Total</span><span class="stat-value stat-small"><?= $e($money($totals['total'])) ?></span><span class="muted">Base <?= $e($money($totals['net'])) ?> + impuesto <?= $e($money($totals['tax'])) ?><?= Decimal::from($totals['returned'])->isPositive() ? ' · devuelto ' . $e($money($totals['returned'])) : '' ?></span></div>
  <div class="card stat"><span class="stat-label"><?= $st === 'delivered' ? 'Cobrado' : 'Anticipos recibidos' ?></span><span class="stat-value stat-small"><?= $e($money($totals['net_paid'])) ?></span><span class="muted">Reembolsado <?= $e($money($totals['refunded'])) ?></span></div>
  <div class="card stat"><span class="stat-label"><?= $totals['balance']->isNegative() ? 'Saldo a favor del cliente' : 'Pendiente' ?></span><span class="stat-value stat-small"><?= $e($money($totals['balance']->abs())) ?></span>
    <?php if ($st === 'delivered' && $can('products.cost')): ?><span class="muted">Costo <?= $e($money($totals['cost'])) ?> · utilidad <?= $e($money($totals['profit'])) ?></span><?php endif; ?></div>
</div>

<?php $canReturn = $st === 'delivered' && $can('sales.return'); ?>
<?php if ($canReturn): ?><form method="post" action="<?= $e($url('/sales/' . $id . '/return')) ?>"><?= $csrf() ?><?php endif; ?>
<div class="card table-wrap"><h2 class="card-title">Líneas</h2>
<?php if ($lines === []): ?><p class="empty-msg muted">Agrega productos al pedido.</p><?php else: ?>
  <table class="table"><thead><tr><th>Producto</th><th class="right">Cantidad</th><th class="right">Precio</th><th class="right">Impuesto</th><th class="right">Total</th><?php if ($canReturn): ?><th>Devolver</th><?php endif; ?><th></th></tr></thead><tbody>
  <?php foreach ($lines as $l): $left = Decimal::from($l['quantity'], 3)->sub(Decimal::from($l['returned_quantity'], 3)); ?>
    <tr><td><strong><?= $e($l['product_name']) ?></strong><br><span class="muted"><code><?= $e($l['sku']) ?></code></span></td>
      <td class="right"><?= $e($money($l['quantity'], 3)) ?><?= Decimal::from($l['returned_quantity'], 3)->isPositive() ? '<br><span class="muted">devuelto ' . $e($money($l['returned_quantity'], 3)) . '</span>' : '' ?></td>
      <td class="right"><?= $e($money($l['unit_price'])) ?><?= (int) $l['price_includes_tax'] === 1 ? '<br><span class="muted">con impuesto</span>' : '' ?></td>
      <td class="right"><?= $e($money($l['tax_rate'], 2)) ?> % <span class="muted">(<?= $e($money($l['tax_amount'])) ?>)</span></td>
      <td class="right"><?= $e($money($l['line_total'])) ?></td>
      <?php if ($canReturn): ?><td><?php if ($left->isPositive()): ?><input class="input" name="qty[<?= (int) $l['id'] ?>]" inputmode="decimal" placeholder="máx. <?= $e($left->format()) ?>" aria-label="Cantidad a devolver"><?php else: ?><span class="muted">—</span><?php endif; ?></td><?php endif; ?>
      <td class="right"><?php if ($draft && $can('sales.create')): ?><button class="btn btn-ghost btn-sm" type="submit" form="rm<?= (int) $l['id'] ?>">Quitar</button><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table>
<?php endif; ?></div>
<?php if ($canReturn): ?>
  <div class="card form"><h2>Registrar devolución</h2><p class="muted">Se devuelve el importe proporcional (base e impuesto). Con «reponer stock», las unidades vuelven al almacén al costo con el que salieron. El dinero se reembolsa aparte.</p>
    <div class="grid grid-3"><label class="field"><span>Motivo</span><input class="input" name="reason" maxlength="255" required></label>
      <label class="field"><span>Fecha</span><input class="input" type="date" name="returned_on" value="<?= $e($today) ?>"></label>
      <label class="check"><input type="checkbox" name="restock" value="1" checked><span>Reponer stock (se puede revender)</span></label></div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Registrar devolución</button></div></div></form>
<?php endif; ?>
<?php if ($draft && $can('sales.create')): foreach ($lines as $l): ?>
  <form id="rm<?= (int) $l['id'] ?>" method="post" action="<?= $e($url('/sales/' . $id . '/lines/' . (int) $l['id'] . '/delete')) ?>"><?= $csrf() ?></form>
<?php endforeach; ?>
<form method="post" action="<?= $e($url('/sales/' . $id . '/lines')) ?>" class="card form"><?= $csrf() ?><h2>Agregar línea</h2>
  <p class="muted">Precio vacío = precio promocional o de lista del producto. Impuesto vacío = el del producto.</p>
  <div class="grid grid-3">
    <label class="field"><span>Variante</span><select class="input" name="variant_id" required><option value="">Selecciona…</option>
      <?php foreach ($variants as $v): ?><option value="<?= $e($v['id']) ?>"><?= $e($v['name']) ?> — <?= $e($v['sku']) ?><?= $v['variant_name'] ? ' (' . $e($v['variant_name']) . ')' : '' ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Cantidad</span><input class="input" name="quantity" inputmode="decimal" required></label>
    <label class="field"><span>Precio unitario</span><input class="input" name="unit_price" inputmode="decimal"></label>
    <label class="field"><span>Impuesto %</span><input class="input" name="tax_rate" inputmode="decimal"></label></div>
  <div class="form-actions"><button class="btn btn-primary" type="submit"><?= $icon('plus', 16) ?> Agregar</button></div></form>
<?php endif; ?>

<div class="card table-wrap"><h2 class="card-title">Cobros y reembolsos</h2>
<?php if ($payments === []): ?><p class="empty-msg muted">Sin movimientos de dinero.</p><?php else: ?>
  <table class="table"><thead><tr><th>Fecha</th><th>Tipo</th><th>Cuenta</th><th class="right">Importe</th><th></th></tr></thead><tbody>
  <?php foreach ($payments as $p): $v = $p['voided_at'] !== null; ?>
    <tr<?= $v ? ' class="voided"' : '' ?>><td><?= $e($local($p['occurred_at'])) ?></td><td><?= $e($kinds[$p['kind']]) ?><?= $p['notes'] ? ' <span class="muted">— ' . $e($p['notes']) . '</span>' : '' ?></td>
      <td><?= $e($p['account_name']) ?></td><td class="right"><?= $p['kind'] === 'refund' ? '−' : '' ?><?= $e($money($p['amount'])) ?></td>
      <td class="right"><?php if ($v): ?><span class="badge off">Anulado</span><?php elseif ($can('sales.refund')): ?>
        <form method="post" class="inline-form" action="<?= $e($url('/sales/' . $id . '/payments/' . (int) $p['id'] . '/void')) ?>"><?= $csrf() ?><input class="input" name="reason" placeholder="Motivo" maxlength="255" required aria-label="Motivo de anulación"><button class="btn btn-ghost btn-sm" type="submit">Anular</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table><?php endif; ?></div>

<?php if (!in_array($st, ['draft', 'cancelled'], true) && ($can('sales.pay') || $can('sales.refund'))): ?>
<div class="grid grid-2">
  <?php if ($can('sales.pay') && $totals['balance']->isPositive()): ?>
  <form method="post" action="<?= $e($url('/sales/' . $id . '/pay')) ?>" class="card form"><?= $csrf() ?><input type="hidden" name="token" value="<?= $e($token) ?>">
    <h2><?= $st === 'delivered' ? 'Registrar cobro' : 'Registrar anticipo' ?></h2>
    <div class="grid grid-2"><label class="field"><span>Cuenta</span><select class="input" name="account_id" required><?php foreach ($accounts as $a): ?><option value="<?= $e($a['id']) ?>"><?= $e($a['name']) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>Importe</span><input class="input" name="amount" inputmode="decimal" value="<?= $e($totals['balance']->toString()) ?>" required></label></div>
    <label class="field"><span>Notas</span><input class="input" name="notes" maxlength="255"></label>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Registrar</button></div></form>
  <?php endif; ?>
  <?php if ($can('sales.refund') && $totals['net_paid']->isPositive()): ?>
  <form method="post" action="<?= $e($url('/sales/' . $id . '/refund')) ?>" class="card form"><?= $csrf() ?><h2>Reembolsar al cliente</h2>
    <div class="grid grid-2"><label class="field"><span>Cuenta de salida</span><select class="input" name="account_id" required><?php foreach ($accounts as $a): ?><option value="<?= $e($a['id']) ?>"><?= $e($a['name']) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>Importe</span><input class="input" name="amount" inputmode="decimal" required></label></div>
    <label class="field"><span>Notas</span><input class="input" name="notes" maxlength="255"></label>
    <div class="form-actions"><button class="btn btn-ghost" type="submit">Reembolsar</button></div></form>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($returns !== []): ?><div class="card table-wrap"><h2 class="card-title">Devoluciones</h2>
  <table class="table"><thead><tr><th>Fecha</th><th>Detalle</th><th>Motivo</th><th class="right">Total</th></tr></thead><tbody>
  <?php foreach ($returns as $r): ?><tr><td><?= $e($r['returned_on']) ?></td>
    <td><?php foreach ($r['lines'] as $l): ?><?= $e($l['product_name']) ?> × <?= $e($money($l['quantity'], 3)) ?><br><?php endforeach; ?><span class="muted"><?= (int) $r['restock'] === 1 ? 'Repuesto al stock' : 'Sin reponer' ?></span></td>
    <td><?= $e($r['reason']) ?></td><td class="right"><?= $e($money($r['total'])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>

<?php if ($open || $draft || $st === 'pending' || $st === 'confirmed' || $st === 'prepared'): if ($can('sales.cancel') && !in_array($st, ['delivered', 'cancelled'], true)): ?>
<form method="post" action="<?= $e($url('/sales/' . $id . '/cancel')) ?>" class="card form"><?= $csrf() ?><h2>Cancelar pedido</h2>
  <p class="muted">Libera el stock reservado. Si hay anticipos, reembólsalos primero.</p>
  <div class="toolbar"><input class="input" name="reason" placeholder="Motivo" maxlength="255" required><button class="btn btn-ghost" type="submit">Cancelar pedido</button></div></form>
<?php endif; endif; ?>

<div class="card table-wrap"><h2 class="card-title">Historial</h2>
  <table class="table"><tbody><?php foreach ($history as $h): ?>
    <tr><td><?= $e($local($h['created_at'])) ?></td><td><?= $h['from_status'] ? $e($statuses[$h['from_status']]) . ' → ' : '' ?><strong><?= $e($statuses[$h['to_status']]) ?></strong><?= $h['note'] ? ' <span class="muted">— ' . $e($h['note']) . '</span>' : '' ?></td><td class="muted"><?= $e($h['user_name'] ?? '') ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
