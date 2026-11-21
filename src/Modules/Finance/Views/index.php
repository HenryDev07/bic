<div class="page-head">
  <div><h1>Cuentas</h1><p class="muted">El saldo se calcula del libro de caja; no se edita a mano.</p></div>
  <div class="actions">
    <?php if ($can('finance.create')): ?><a class="btn btn-primary" href="<?= $e($url('/finance/movements/create')) ?>"><?= $icon('plus', 16) ?> Nuevo movimiento</a><?php endif; ?>
    <?php if ($can('finance.manage')): ?><a class="btn btn-ghost" href="<?= $e($url('/finance/accounts/create')) ?>">Nueva cuenta</a><?php endif; ?>
  </div>
</div>
<?php if ($issues === []): ?>
  <div class="alert alert-success">Conciliación correcta: todos los asientos tienen su documento y las cuentas cuadran.</div>
<?php else: ?>
  <div class="alert alert-danger"><strong>Conciliación con diferencias:</strong><?php foreach ($issues as $i): ?><br><?= $e($i) ?><?php endforeach; ?></div>
<?php endif; ?>
<div class="grid grid-3"><div class="card stat"><span class="stat-label">Dinero disponible (cuentas activas)</span><span class="stat-value"><?= $e($money($total)) ?></span></div></div>
<div class="card table-wrap">
<?php if ($accounts === []): ?><p class="empty-msg muted">Crea tu primera cuenta (caja, banco…) para empezar a registrar movimientos.</p><?php else: ?>
  <table class="table"><thead><tr><th>Cuenta</th><th>Tipo</th><th>Moneda</th><th class="right">Saldo</th><th>Estado</th><th></th></tr></thead><tbody>
  <?php $types = ['cash' => 'Efectivo', 'bank' => 'Banco', 'wallet' => 'Billetera', 'other' => 'Otra']; ?>
  <?php foreach ($accounts as $a): ?>
    <tr><td><strong><?= $e($a['name']) ?></strong></td><td><?= $e($types[$a['type']] ?? $a['type']) ?></td><td><?= $e($a['currency']) ?></td>
      <td class="right"><strong><?= $e($money($a['balance'])) ?></strong></td>
      <td><?= (int) $a['is_active'] === 1 ? '<span class="badge ok">Activa</span>' : '<span class="badge off">Inactiva</span>' ?></td>
      <td class="right"><?php if ($can('finance.manage')): ?><a class="btn btn-ghost btn-sm" href="<?= $e($url('/finance/accounts/' . (int) $a['id'] . '/edit')) ?>">Editar</a><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table>
<?php endif; ?>
</div>
<div class="card table-wrap"><h2 class="card-title">Últimos movimientos</h2>
<?php if ($recent === []): ?><p class="empty-msg muted">Sin movimientos.</p><?php else: ?>
  <table class="table"><tbody>
  <?php foreach ($recent as $t): ?>
    <tr<?= $t['voided_at'] !== null ? ' class="voided"' : '' ?>><td class="muted"><?= $e($local($t['occurred_at'])) ?></td><td><?= $e($t['account_name']) ?></td><td><?= $e($t['description']) ?></td>
      <td class="right"><?= $t['direction'] === 'in' ? '+' : '−' ?><?= $e($money($t['amount'])) ?></td></tr>
  <?php endforeach; ?></tbody></table>
<?php endif; ?>
</div>
