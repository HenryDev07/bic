<div class="page-head"><div><h1>Libro de caja</h1><p class="muted">Registro único de entradas y salidas. Los movimientos no se borran: se anulan con motivo.</p></div>
  <?php if ($can('finance.create')): ?><a class="btn btn-primary" href="<?= $e($url('/finance/movements/create')) ?>"><?= $icon('plus', 16) ?> Nuevo movimiento</a><?php endif; ?></div>
<form class="toolbar" method="get" action="<?= $e($url('/finance/ledger')) ?>">
  <select class="input" name="account_id" aria-label="Cuenta"><option value="">Todas las cuentas</option>
    <?php foreach ($accounts as $a): ?><option value="<?= $e($a['id']) ?>"<?= (int) $a['id'] === (int) $f['account_id'] ? ' selected' : '' ?>><?= $e($a['name']) ?></option><?php endforeach; ?></select>
  <select class="input" name="direction" aria-label="Tipo"><option value="">Entradas y salidas</option><option value="in"<?= $f['direction'] === 'in' ? ' selected' : '' ?>>Entradas</option><option value="out"<?= $f['direction'] === 'out' ? ' selected' : '' ?>>Salidas</option></select>
  <select class="input" name="source" aria-label="Origen"><option value="">Todos los orígenes</option>
    <?php foreach ($sources as $k => $l): ?><option value="<?= $e($k) ?>"<?= $f['source'] === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select>
  <input class="input" type="date" name="from" value="<?= $e($f['from_date']) ?>" aria-label="Desde"><input class="input" type="date" name="to" value="<?= $e($f['to_date']) ?>" aria-label="Hasta">
  <button class="btn btn-ghost" type="submit">Filtrar</button>
</form>
<div class="card table-wrap">
<?php if ($rows === []): ?><p class="empty-msg muted">No hay movimientos.</p><?php else: ?>
  <table class="table"><thead><tr><th>Fecha</th><th>Cuenta</th><th>Origen</th><th>Descripción</th><th class="right">Importe</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $t): $voided = $t['voided_at'] !== null; ?>
    <tr<?= $voided ? ' class="voided"' : '' ?>>
      <td class="muted"><?= $e($local($t['occurred_at'])) ?></td><td><?= $e($t['account_name']) ?></td><td><span class="badge"><?= $e($sources[$t['source_type']] ?? $t['source_type']) ?></span></td>
      <td><?= $e($t['description']) ?><?= $voided ? '<br><span class="badge off">Anulado</span> <span class="muted">' . $e($t['void_reason']) . '</span>' : '' ?></td>
      <td class="right"><?= $t['direction'] === 'in' ? '+' : '−' ?><?= $e($money($t['amount'])) ?></td>
      <td class="right"><?php if (!$voided && $can('finance.void') && !in_array($t['source_type'], ['order_payment', 'purchase_payment', 'expense'], true)): ?>
        <form method="post" class="inline-form" action="<?= $e($url('/finance/ledger/' . (int) $t['id'] . '/void')) ?>"><?= $csrf() ?>
          <input class="input" name="reason" placeholder="Motivo" maxlength="255" required aria-label="Motivo de anulación"><button class="btn btn-ghost btn-sm" type="submit">Anular</button></form><?php endif; ?></td>
    </tr>
  <?php endforeach; ?></tbody></table>
<?php endif; ?>
</div>
<?= $partial('core::pagination', ['pager' => $pager, 'pagePath' => $pagePath, 'pageQuery' => $pageQuery]) ?>
