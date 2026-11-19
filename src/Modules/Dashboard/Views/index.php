<div class="page-head">
  <div><h1>Hola, <?= $e($user['name']) ?></h1><p class="muted"><?= $e($business['name'] ?? '') ?> · moneda base <?= $e($business['base_currency'] ?? '') ?> · zona horaria <?= $e($business['timezone'] ?? '') ?></p></div>
</div>

<div class="grid grid-3">
  <section class="card stat"><span class="stat-label">Usuarios activos</span><span class="stat-value"><?= $e($activeUsers) ?></span></section>
  <section class="card stat"><span class="stat-label">Roles</span><span class="stat-value"><?= $e($roleCount) ?></span></section>
  <section class="card stat"><span class="stat-label">Tu último acceso</span><span class="stat-value stat-small"><?= $e($local($user['last_login_at'])) ?></span></section>
</div>

<section class="card empty">
  <h2>Indicadores comerciales</h2>
  <p class="muted">Ventas, ganancias, inventario y gráficos aparecerán aquí cuando existan los módulos que los generan.
    Este panel solo mostrará datos reales de tu base de datos, nunca cifras de ejemplo.</p>
</section>
