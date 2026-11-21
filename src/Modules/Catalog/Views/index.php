<div class="page-head">
  <div><h1>Catálogo</h1><p class="muted">Tablas de apoyo de los productos.</p></div>
  <?php if ($can('catalog.manage')): ?><a class="btn btn-primary" href="<?= $e($url('/catalog/' . $entity . '/create')) ?>"><?= $icon('plus', 16) ?> Nuevo</a><?php endif; ?>
</div>
<nav class="tabs" aria-label="Tablas del catálogo">
  <?php foreach ($entities as $key => $info): ?>
    <a class="tab<?= $key === $entity ? ' active' : '' ?>" href="<?= $e($url('/catalog/' . $key)) ?>"><?= $e($info['title']) ?></a>
  <?php endforeach; ?>
</nav>
<div class="card table-wrap">
<?php if ($rows === []): ?>
  <p class="empty-msg muted">Aún no hay registros en «<?= $e($cfg['title']) ?>».</p>
<?php else: ?>
  <table class="table">
    <thead><tr><?php foreach ($cfg['cols'] as $label): ?><th><?= $e($label) ?></th><?php endforeach; ?><th>Estado</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
      <tr>
        <?php foreach ($cfg['cols'] as $col => $label): ?>
          <td><?= $col === 'rate' ? $e($money($row[$col], 4)) . ' %' : $e($row[$col]) ?></td>
        <?php endforeach; ?>
        <td>
          <?= (int) $row['is_active'] === 1 ? '<span class="badge ok">Activo</span>' : '<span class="badge off">Inactivo</span>' ?>
          <?php if (!empty($row['is_default'])): ?> <span class="badge">Predeterminado</span><?php endif; ?>
        </td>
        <td class="right"><?php if ($can('catalog.manage')): ?><a class="btn btn-ghost btn-sm" href="<?= $e($url('/catalog/' . $entity . '/' . (int) $row['id'] . '/edit')) ?>">Editar</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>
<?php if ($entity === 'tax-rates'): ?>
  <p class="muted">No existe una tasa universal: define aquí las que aplican a tu negocio y asígnalas a cada producto. «Exento» es una tasa 0 explícita.</p>
<?php endif; ?>
