<div class="page-head"><div><h1>Gastos</h1><p class="muted">Gastos operativos. Los fletes y aranceles de una compra van en la orden de compra, no aquí.</p></div>
  <?php if ($can('expenses.create')): ?><a class="btn btn-primary" href="<?= $e($url('/expenses/create')) ?>"><?= $icon('plus', 16) ?> Nuevo gasto</a><?php endif; ?></div>
<form class="toolbar" method="get" action="<?= $e($url('/expenses')) ?>">
  <select class="input" name="category_id" aria-label="Categoría"><option value="">Todas las categorías</option>
    <?php foreach ($categories as $c): ?><option value="<?= $e($c['id']) ?>"<?= (int) $c['id'] === (int) $f['category_id'] ? ' selected' : '' ?>><?= $e($c['name']) ?></option><?php endforeach; ?></select>
  <input class="input" type="date" name="from" value="<?= $e($f['from']) ?>" aria-label="Desde"><input class="input" type="date" name="to" value="<?= $e($f['to']) ?>" aria-label="Hasta">
  <button class="btn btn-ghost" type="submit">Filtrar</button>
</form>
<div class="card table-wrap">
<?php if ($rows === []): ?><p class="empty-msg muted">No hay gastos.</p><?php else: ?>
  <table class="table"><thead><tr><th>Fecha</th><th>Categoría</th><th>Descripción</th><th>Cuenta</th><th class="right">Importe</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): $v = $r['voided_at'] !== null; ?>
    <tr<?= $v ? ' class="voided"' : '' ?>><td><?= $e($r['expense_date']) ?></td><td><?= $e($r['category_name']) ?></td>
      <td><?= $e($r['description']) ?><?= $v ? '<br><span class="badge off">Anulado</span> <span class="muted">' . $e($r['void_reason']) . '</span>' : '' ?></td>
      <td><?= $e($r['account_name']) ?></td><td class="right"><?= $e($money($r['amount'])) ?></td>
      <td class="right"><?php if (!$v && $can('expenses.void')): ?><form method="post" class="inline-form" action="<?= $e($url('/expenses/' . (int) $r['id'] . '/void')) ?>"><?= $csrf() ?>
        <input class="input" name="reason" placeholder="Motivo" maxlength="255" required aria-label="Motivo de anulación"><button class="btn btn-ghost btn-sm" type="submit">Anular</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody>
  <tfoot><tr><th colspan="4" class="right">Total vigente (con los filtros)</th><th class="right"><?= $e($money($sum)) ?></th><th></th></tr></tfoot></table>
<?php endif; ?>
</div>
<?= $partial('core::pagination', ['pager' => $pager, 'pagePath' => $pagePath, 'pageQuery' => $pageQuery]) ?>
<?php if ($can('expenses.create')): ?>
<form method="post" action="<?= $e($url('/expenses/categories')) ?>" class="card form"><?= $csrf() ?><h2>Nueva categoría</h2>
  <div class="toolbar"><input class="input" name="name" maxlength="100" required placeholder="Nombre" aria-label="Nombre de la categoría"><button class="btn btn-ghost" type="submit">Agregar</button></div></form>
<?php endif; ?>
