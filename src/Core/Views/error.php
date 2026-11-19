<section class="card error-card">
  <div class="error-code"><?= $e($status) ?></div>
  <h1><?= $e($heading) ?></h1>
  <p class="muted"><?= $e($message) ?></p>
  <?php if (!empty($detail)): ?><pre class="detail"><?= $e($detail) ?></pre><?php endif; ?>
  <p><a class="btn btn-primary" href="<?= $e($url('/')) ?>">Volver al inicio</a></p>
</section>
