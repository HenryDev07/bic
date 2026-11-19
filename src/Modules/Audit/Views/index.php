<div class="page-head">
  <div><h1>Auditoría</h1><p class="muted">Registro inmutable de acciones importantes. La aplicación solo puede añadir registros, no editarlos ni borrarlos.</p></div>
</div>

<form class="toolbar" method="get" action="<?= $e($url('/audit')) ?>">
  <select class="input" name="action" aria-label="Acción">
    <option value="">Todas las acciones</option>
    <?php foreach ($actions as $a): ?><option value="<?= $e($a) ?>"<?= $filters['action'] === $a ? ' selected' : '' ?>><?= $e($a) ?></option><?php endforeach; ?>
  </select>
  <select class="input" name="entity_type" aria-label="Entidad">
    <option value="">Todas las entidades</option>
    <?php foreach ($entityTypes as $t): ?><option value="<?= $e($t) ?>"<?= $filters['entity_type'] === $t ? ' selected' : '' ?>><?= $e($t) ?></option><?php endforeach; ?>
  </select>
  <select class="input" name="user_id" aria-label="Usuario">
    <option value="">Todos los usuarios</option>
    <?php foreach ($users as $u): ?><option value="<?= $e($u['id']) ?>"<?= $filters['user_id'] === (int) $u['id'] ? ' selected' : '' ?>><?= $e($u['name']) ?></option><?php endforeach; ?>
  </select>
  <input class="input" type="date" name="from" value="<?= $e($filters['from']) ?>" aria-label="Desde">
  <input class="input" type="date" name="to" value="<?= $e($filters['to']) ?>" aria-label="Hasta">
  <button class="btn btn-ghost" type="submit">Filtrar</button>
</form>

<div class="card table-wrap">
<?php if ($rows === []): ?>
  <p class="empty-msg muted">No hay registros para este filtro.</p>
<?php else: ?>
  <table class="table">
    <thead><tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Entidad</th><th>Detalle</th><th>IP</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
      <tr>
        <td class="nowrap"><?= $e($local($row['created_at'], 'd/m/Y H:i:s')) ?></td>
        <td><?= $e($row['user_name'] ?? '—') ?></td>
        <td><span class="badge"><?= $e($row['action']) ?></span></td>
        <td><?= $e($row['entity_type']) ?><?= $row['entity_id'] !== null ? ' #' . $e($row['entity_id']) : '' ?></td>
        <td class="detail-cell"><?php if ($row['old_values'] !== null || $row['new_values'] !== null): ?>
          <details><summary>Ver cambios</summary>
            <?php if ($row['old_values'] !== null): ?><div><span class="muted">Antes:</span> <code><?= $e($row['old_values']) ?></code></div><?php endif; ?>
            <?php if ($row['new_values'] !== null): ?><div><span class="muted">Después:</span> <code><?= $e($row['new_values']) ?></code></div><?php endif; ?>
          </details><?php else: ?>—<?php endif; ?></td>
        <td class="muted"><?= $e($row['ip_address'] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>
<?= $partial('core::pagination', ['pager' => $pager, 'pagePath' => $pagePath, 'pageQuery' => $pageQuery]) ?>
