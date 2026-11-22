<!doctype html>
<html lang="es" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= $e($csrfToken) ?>">
<title><?= $e($title) ?> · <?= $e($appName) ?></title>
<link rel="stylesheet" href="<?= $e($asset('css/app.css')) ?>">
<script src="<?= $e($asset('js/app.js')) ?>" defer></script>
</head>
<body class="guest">
  <main class="guest-wrap">
    <div class="guest-brand">
      <img class="guest-logo" src="<?= $e($logoUrl) ?>" alt="<?= $e($logoAlt) ?>">
    </div>
    <?php foreach ($flashes as $flash): ?>
      <div class="alert alert-<?= $e($flash['type']) ?>" role="alert"><?= $e($flash['message']) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
  </main>
</body>
</html>
