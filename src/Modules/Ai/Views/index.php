<div class="page-head"><div><h1>Asistente IA</h1><p class="muted">Pregunta por tus cifras. Solo consulta datos (no modifica nada) y respeta tus permisos. Proveedor: <strong><?= $e($provider === 'local' ? 'modo local (sin IA externa)' : $provider) ?></strong> · mensajes restantes hoy: <?= $e($remaining) ?></p></div>
  <a class="btn btn-ghost" href="<?= $e($url('/ai')) ?>"><?= $icon('plus', 16) ?> Nueva conversación</a></div>
<div class="grid grid-2 ai-layout">
  <section class="card card-pad">
    <?php if ($messages === []): ?><p class="muted">Ejemplos: «¿Cuánto vendí este mes?», «¿Cuál fue mi ganancia neta del trimestre?», «¿Qué debo reponer?», «¿Quiénes son mis mejores clientes?».</p><?php endif; ?>
    <?php foreach ($messages as $m): ?>
      <div class="ai-msg <?= $m['role'] === 'user' ? 'ai-user' : 'ai-bot' ?>"><strong><?= $m['role'] === 'user' ? 'Tú' : 'Asistente' ?></strong><p><?= nl2br($e($m['content'])) ?></p></div>
    <?php endforeach; ?>
    <form method="post" action="<?= $e($url('/ai')) ?>" class="form"><?= $csrf() ?><input type="hidden" name="conversation_id" value="<?= $e($cid) ?>">
      <label class="field"><span>Tu pregunta</span><textarea class="input" name="message" rows="2" maxlength="1000" required></textarea></label>
      <div class="form-actions"><button class="btn btn-primary" type="submit">Preguntar</button></div></form>
    <p class="muted">Herramientas disponibles para tu rol: <?= $tools === [] ? 'ninguna' : $e(implode(', ', $tools)) ?>.</p>
  </section>
  <section class="card table-wrap"><h2 class="card-title">Conversaciones</h2>
    <?php if ($conversations === []): ?><p class="empty-msg muted">Aún no hay conversaciones.</p><?php else: ?>
    <table class="table"><tbody><?php foreach ($conversations as $c): ?><tr><td><a href="<?= $e($url('/ai?c=' . (int) $c['id'])) ?>"><?= $e($c['title']) ?></a><br><span class="muted"><?= $e($local($c['created_at'])) ?></span></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></section>
</div>
