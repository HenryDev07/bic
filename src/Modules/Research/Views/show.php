<?php $id = (int) $item['id']; ?>
<div class="page-head"><div><h1><?= $e($item['name']) ?> <span class="badge<?= $item['status'] === 'approved' ? ' ok' : ($item['status'] === 'discarded' ? ' off' : '') ?>"><?= $e($statuses[$item['status']]) ?></span></h1>
  <p class="muted"><?= $item['product_id'] ? 'Producto: <a href="' . $e($url('/products/' . (int) $item['product_id'])) . '">' . $e($item['product_name']) . '</a>' : 'Aún no está en el catálogo' ?></p></div>
  <div class="actions"><a class="btn btn-ghost" href="<?= $e($url('/research')) ?>">Volver</a><?php if ($can('research.manage')): ?><a class="btn btn-ghost" href="<?= $e($url('/research/' . $id . '/edit')) ?>">Editar</a><?php endif; ?></div></div>
<div class="grid grid-3">
  <div class="card stat"><span class="stat-label">Costo estimado → precio objetivo</span><span class="stat-value stat-small"><?= $item['estimated_cost'] === null ? '—' : $e($money($item['estimated_cost'])) ?> → <?= $item['target_price'] === null ? '—' : $e($money($item['target_price'])) ?></span>
    <span class="muted"><?= $a['margin'] === null ? 'Completa costo y precio para ver el margen' : 'Margen ' . $e($money($a['margin'])) . ' % · recargo ' . ($a['markup'] === null ? '—' : $e($money($a['markup'])) . ' %') ?></span></div>
  <div class="card stat"><span class="stat-label">Precios observados (mín. / prom. / máx.)</span><span class="stat-value stat-small"><?= $a['min'] === null ? '—' : $e($money($a['min'])) . ' / ' . $e($money($a['avg'])) . ' / ' . $e($money($a['max'])) ?></span><span class="muted"><?= $e($a['count']) ?> observación(es)</span></div>
  <div class="card stat"><span class="stat-label">Frente al mercado</span><span class="stat-value stat-small"><?php if ($item['target_price'] !== null && $a['avg'] !== null): $diff = \Bic\Core\Money\Decimal::from($item['target_price'], 2)->sub(\Bic\Core\Money\Decimal::from($a['avg'], 2)); ?><?= $diff->isNegative() ? 'Por debajo' : 'Por encima' ?> del promedio<?php else: ?>—<?php endif; ?></span>
    <span class="muted"><?= isset($diff) ? $e($money($diff->abs())) . ' de diferencia' : 'Necesita precio objetivo y observaciones' ?></span></div>
</div>
<?php if ($item['notes']): ?><div class="card card-pad"><?= nl2br($e($item['notes'])) ?></div><?php endif; ?>
<div class="card table-wrap"><h2 class="card-title">Precios observados</h2>
<?php if ($prices === []): ?><p class="empty-msg muted">Sin observaciones todavía.</p><?php else: ?>
  <table class="table"><thead><tr><th>Fecha</th><th>Fuente</th><th class="right">Precio</th><th>Notas</th></tr></thead><tbody>
  <?php foreach ($prices as $p): ?><tr><td><?= $e($p['observed_on']) ?></td><td><?= $p['url'] ? '<a href="' . $e($p['url']) . '" rel="noopener noreferrer" target="_blank">' . $e($p['source']) . '</a>' : $e($p['source']) ?></td>
    <td class="right"><?= $e($money($p['observed_price'])) ?></td><td class="muted"><?= $e($p['notes'] ?? '') ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
<?php if ($can('research.manage')): ?>
<form method="post" action="<?= $e($url('/research/' . $id . '/prices')) ?>" class="card form"><?= $csrf() ?><h2>Registrar precio observado</h2>
  <div class="grid grid-3">
    <label class="field"><span>Fuente</span><input class="input" name="source" maxlength="150" required></label>
    <label class="field"><span>Precio (USD)</span><input class="input" name="observed_price" inputmode="decimal" required></label>
    <label class="field"><span>Fecha</span><input class="input" type="date" name="observed_on" value="<?= $e($today) ?>"></label>
    <label class="field"><span>Enlace (opcional)</span><input class="input" name="url" maxlength="500" placeholder="https://"></label>
    <label class="field"><span>Notas</span><input class="input" name="notes" maxlength="255"></label></div>
  <div class="form-actions"><button class="btn btn-primary" type="submit"><?= $icon('plus', 16) ?> Registrar</button></div></form>
<?php endif; ?>
