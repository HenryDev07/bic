<!doctype html>
<html lang="es" data-theme="<?= $e($user['theme'] ?? 'light') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= $e($csrfToken) ?>">
<title><?= $e($title) ?> · <?= $e($appName) ?></title>
<link rel="stylesheet" href="<?= $e($asset('css/app.css')) ?>">
<script src="<?= $e($asset('js/app.js')) ?>" defer></script>
</head>
<body>
<aside class="sidebar" id="sidebar">
  <a class="brand" href="<?= $e($url('/')) ?>"><span class="brand-mark">BIC</span><span class="brand-name">Business Intelligence<br>Commerce</span></a>
  <nav class="nav" aria-label="Principal">
    <?php
    // Solo se marca la opción cuya ruta coincide con más especificidad (/finance/ledger no enciende /finance).
    $bestPath = '';
    foreach ($menu as $g) {
        foreach ($g['items'] as $it) {
            $p = (string) $it['path'];
            $hit = $p === '/' ? $currentPath === '/' : ($currentPath === $p || str_starts_with($currentPath, $p . '/'));
            if ($hit && strlen($p) > strlen($bestPath)) {
                $bestPath = $p;
            }
        }
    }
    ?>
    <?php foreach ($menu as $group): ?>
      <details class="nav-group" open>
        <summary><span><?= $e($group['label']) ?></span><?= $icon('chevron', 14) ?></summary>
        <?php foreach ($group['items'] as $item):
            $active = $item['path'] === $bestPath; ?>
          <a class="nav-link<?= $active ? ' active' : '' ?>" href="<?= $e($url($item['path'])) ?>"<?= $active ? ' aria-current="page"' : '' ?>>
            <?= $icon($item['icon']) ?><span><?= $e($item['label']) ?></span>
          </a>
        <?php endforeach; ?>
      </details>
    <?php endforeach; ?>
  </nav>
</aside>
<div class="backdrop" data-sidebar-toggle></div>

<div class="main">
  <header class="topbar">
    <button class="icon-btn menu-btn" type="button" data-sidebar-toggle aria-label="Abrir menú"><?= $icon('menu', 20) ?></button>
    <div class="topbar-title"><?= $e($title) ?></div>
    <div class="topbar-actions">
      <button class="icon-btn" type="button" data-theme-toggle data-url="<?= $e($url('/preferences/theme')) ?>" aria-label="Cambiar tema claro/oscuro">
        <span class="when-light"><?= $icon('moon', 18) ?></span><span class="when-dark"><?= $icon('sun', 18) ?></span>
      </button>
      <a class="user-chip" href="<?= $e($url('/profile')) ?>" title="Mi perfil">
        <span class="avatar"><?= $e(mb_strtoupper(mb_substr((string) $user['name'], 0, 1))) ?></span>
        <span class="user-name"><?= $e($user['name']) ?></span>
      </a>
      <form method="post" action="<?= $e($url('/logout')) ?>">
        <?= $csrf() ?>
        <button class="icon-btn" type="submit" aria-label="Cerrar sesión" title="Cerrar sesión"><?= $icon('logout', 18) ?></button>
      </form>
    </div>
  </header>

  <main class="content">
    <?php foreach ($flashes as $flash): ?>
      <div class="alert alert-<?= $e($flash['type']) ?>" role="alert"><?= $e($flash['message']) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
  </main>
</div>
</body>
</html>
