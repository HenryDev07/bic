<div class="page-head"><div><h1>Reportes</h1><p class="muted">Cada reporte se puede ver en pantalla y exportar a CSV, Excel o PDF. Moneda base, importes sin impuesto salvo donde se indique.</p></div></div>
<form class="toolbar" id="range" action="<?= $e($url('/reports')) ?>" method="get"><input class="input" type="date" name="from" value="<?= $e($from) ?>" aria-label="Desde"><input class="input" type="date" name="to" value="<?= $e($to) ?>" aria-label="Hasta"><button class="btn btn-ghost" type="submit">Aplicar período</button></form>
<div class="grid grid-3">
<?php foreach ($reports as $k => $d): ?>
  <section class="card card-pad"><h2><?= $e($d['label']) ?></h2><p class="muted"><?= $e($d['desc']) ?></p>
    <p><a class="btn btn-primary" href="<?= $e($url('/reports/' . $k . ($d['ranged'] ? '?from=' . $from . '&to=' . $to : ''))) ?>">Abrir</a></p></section>
<?php endforeach; ?>
<?php if ($reports === []): ?><p class="muted">Tu rol no tiene reportes disponibles.</p><?php endif; ?>
</div>
