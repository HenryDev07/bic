<div class="page-head">
  <div><h1>Productos</h1><p class="muted">Catálogo con variantes, precios e impuestos.</p></div>
  <div class="actions">
    <a class="btn btn-ghost" href="<?= $e($url('/products/export?' . http_build_query(array_filter(['q' => $q, 'status' => $status, 'category_id' => $categoryId ?: ''])))) ?>">Exportar CSV</a>
    <?php if ($can('products.create')): ?><a class="btn btn-primary" href="<?= $e($url('/products/create')) ?>"><?= $icon('plus', 16) ?> Nuevo producto</a><?php endif; ?>
  </div>
</div>

<form class="toolbar" method="get" action="<?= $e($url('/products')) ?>">
  <input class="input" type="search" name="q" value="<?= $e($q) ?>" placeholder="Nombre, SKU o código de barras" maxlength="100">
  <select class="input" name="category_id" aria-label="Categoría">
    <option value="">Todas las categorías</option>
    <?php foreach ($options['categories'] as $c): ?><option value="<?= $e($c['id']) ?>"<?= (int) $c['id'] === $categoryId ? ' selected' : '' ?>><?= $e($c['name']) ?></option><?php endforeach; ?>
  </select>
  <select class="input" name="status" aria-label="Estado">
    <option value="">Todos los estados</option>
    <?php foreach ($statuses as $key => $label): ?><option value="<?= $e($key) ?>"<?= $status === $key ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?>
  </select>
  <button class="btn btn-ghost" type="submit">Filtrar</button>
</form>

<div class="card table-wrap">
<?php if ($rows === []): ?>
  <p class="empty-msg muted">No hay productos que coincidan.</p>
<?php else: ?>
  <table class="table">
    <thead><tr><th>SKU</th><th>Producto</th><th>Categoría</th><th class="right">Precio</th><?php if ($showCost): ?><th class="right">Costo ref.</th><?php endif; ?><th>Impuesto</th><th>Estado</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><code><?= $e($r['sku']) ?></code></td>
        <td><a href="<?= $e($url('/products/' . (int) $r['id'])) ?>"><strong><?= $e($r['name']) ?></strong></a><?php if ($r['brand_name']): ?><br><span class="muted"><?= $e($r['brand_name']) ?></span><?php endif; ?></td>
        <td><?= $e($r['category_name'] ?? '—') ?></td>
        <td class="right"><?= $e($money($r['sale_price'])) ?><?php if ($r['promo_price'] !== null): ?><br><span class="badge ok">Promo <?= $e($money($r['promo_price'])) ?></span><?php endif; ?></td>
        <?php if ($showCost): ?><td class="right"><?= $e($money($r['reference_cost'], 4)) ?></td><?php endif; ?>
        <td><?= $e($r['tax_name']) ?> <span class="muted">(<?= (int) $r['price_includes_tax'] === 1 ? 'incluido' : 'se suma' ?>)</span></td>
        <td><?= $r['status'] === 'active' ? '<span class="badge ok">Activo</span>' : '<span class="badge off">' . $e($statuses[$r['status']] ?? $r['status']) . '</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>
<?= $partial('core::pagination', ['pager' => $pager, 'pagePath' => $pagePath, 'pageQuery' => $pageQuery]) ?>
