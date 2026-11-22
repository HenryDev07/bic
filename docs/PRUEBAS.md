# BIC — Verificación en tu equipo

Las pruebas automáticas del desarrollo corrieron sobre SQLite en memoria. Lo siguiente **debes** confirmarlo en tu WAMP con MariaDB real.

## Automático
```
composer install
composer test                 # ~181 pruebas, 0 fallos
php bin/migrate.php --seed
php bin/db-check.php          # denegaciones del usuario bic_app
php bin/doctor.php            # estado general (debe terminar sin ERROR)
```

## Respaldo y restauración (en una base de prueba)
1. `php bin/backup.php` → crea `.sql.gz` y `.sha256`.
2. Cambia `BIC_DB_NAME` a una base vacía de prueba, `php bin/migrate.php` y `php bin/restore.php <archivo>`.
3. `php bin/doctor.php` en la base restaurada: sin descuadres.
4. Corrompe un byte del `.gz` y comprueba que `restore` lo rechaza.

## Aceptación manual (UAT)
- [ ] Iniciar sesión, bloqueo tras intentos fallidos, cierre de sesión.
- [ ] Crear producto con variante; precio visible y costo oculto para Vendedor.
- [ ] Compra → recepción parcial → recepción final → pago; costo promedio correcto.
- [ ] Venta con anticipo → entrega → saldo; doble clic en «Cobrar» no duplica.
- [ ] Devolución con y sin reingreso; stock y estado de resultados coherentes.
- [ ] Gasto y anulación; saldo de la cuenta correcto.
- [ ] Reportes: CSV, Excel (requiere `zip`) y PDF abren bien.
- [ ] Dashboard coincide con los módulos.
- [ ] Asistente de IA con clave real: responde con cifras que coinciden con Reportes.
- [ ] Rutas `/config/local.php` y `/src/...` dan 403.
