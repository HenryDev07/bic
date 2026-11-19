/* BIC — comportamiento común. Sin dependencias; compatible con CSP (sin código en línea). */
(function () {
  'use strict';

  var csrf = document.querySelector('meta[name="csrf-token"]');
  var token = csrf ? csrf.getAttribute('content') : '';

  // Menú lateral en pantallas pequeñas.
  document.querySelectorAll('[data-sidebar-toggle]').forEach(function (el) {
    el.addEventListener('click', function () { document.body.classList.toggle('sidebar-open'); });
  });

  // Tema claro/oscuro: se aplica al instante y se guarda en la cuenta del usuario.
  document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var root = document.documentElement;
      var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
      root.setAttribute('data-theme', next);
      var body = new URLSearchParams({ theme: next });
      fetch(btn.getAttribute('data-url'), {
        method: 'POST',
        headers: { 'X-CSRF-Token': token, 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(),
        credentials: 'same-origin'
      }).catch(function () { /* el cambio visual ya se aplicó */ });
    });
  });

  // Confirmación opcional: <form data-confirm="¿Seguro?">
  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      if (!window.confirm(form.getAttribute('data-confirm'))) { ev.preventDefault(); }
    });
  });

  // Anti doble envío: tras el primer envío se desactivan los botones del formulario.
  document.querySelectorAll('form[method="post"]').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      if (ev.defaultPrevented) { return; }
      form.querySelectorAll('button[type="submit"]').forEach(function (b) { b.disabled = true; });
    });
  });
  // Al volver con el botón "atrás", se reactivan.
  window.addEventListener('pageshow', function () {
    document.querySelectorAll('form[method="post"] button[type="submit"]').forEach(function (b) { b.disabled = false; });
  });
})();
