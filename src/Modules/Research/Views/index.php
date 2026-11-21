<div class="page-head"><div><h1>Investigación</h1><p class="muted">Ideas de producto, costo estimado y precios observados en el mercado.</p></div>
  <?php if ($can('research.manage')): ?><a class="btn btn-primary" href="<?= $e($url('/research/create')) ?>"><?= $icon('plus', 16) ?> Nueva investigación</a><?php endif; ?></div>
<form class="toolbar" method="get" action="<?= $e($url('/research')) ?>">
  <input class="input" type="search" name="q" value="<?= $e($q) ?>" placeholder="Nombre" maxlength="100">
  <select class="input" name="status" aria-label="Estado"><option value="">Todos los estados</option>
    <?php foreach ($statuses as $k => $l): ?><option value="<?= $e($k) ?>"<?= $status === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select>
  <button class="btn btn-ghost" type="submit">Filtrar</button></form>
<div class="card table-wrap">
<?php if ($rows === []): ?><p class="empty-msg muted">No hay investigaciones.</p><?php else: ?>
  <table class="table"><thead><tr><th>Producto</th><th>Estado</th><th class="right">Costo est.</th><th class="right">Precio objetivo</th><th class="right">Mín. observado</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td><a href="<?= $e($url('/research/' . (int) $r['id'])) ?>"><strong><?= $e($r['name']) ?></strong></a></td><td><span class="badge<?= $r['status'] === 'approved' ? ' ok' : ($r['status'] === 'discarded' ? ' off' : '') ?>"><?= $e($statuses[$r['status']]) ?></span></td>
      <td class="right"><?= $r['estimated_cost'] === null ? '—' : $e($money($r['estimated_cost'])) ?></td><td class="right"><?= $r['target_price'] === null ? '—' : $e($money($r['target_price'])) ?></td>
      <td class="right"><?= $r['min_price'] === null ? '—' : $e($money($r['min_price'])) ?></td></tr>
  <?php endforeach; ?></tbody></table>
<?php endif; ?></div>
<?= $partial('core::pagination', ['pager' => $pager, 'pagePath' => $pagePath, 'pageQuery' => $pageQuery]) ?>
