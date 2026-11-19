<?php use Bic\Core\Paginator; ?>
<?php if ($pager['pages'] > 1): ?>
<nav class="pagination" aria-label="Paginación">
  <span class="muted">Página <?= $e($pager['page']) ?> de <?= $e($pager['pages']) ?> · <?= $e($pager['total']) ?> registros</span>
  <span class="pagination-links">
    <?php if ($pager['page'] > 1): ?><a class="btn btn-ghost" href="<?= $e($url(Paginator::url($pagePath, $pageQuery, $pager['page'] - 1))) ?>">Anterior</a><?php endif; ?>
    <?php if ($pager['page'] < $pager['pages']): ?><a class="btn btn-ghost" href="<?= $e($url(Paginator::url($pagePath, $pageQuery, $pager['page'] + 1))) ?>">Siguiente</a><?php endif; ?>
  </span>
</nav>
<?php endif; ?>
