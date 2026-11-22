# BIC — Seguridad

| Amenaza | Control |
|---|---|
| Acceso no autorizado | Sesión con regeneración al iniciar, contraseñas con hash (`password_hash`), bloqueo por intentos, permisos verificados **en el servidor** en cada ruta (probado recorriendo todas las rutas) |
| Escalada de privilegios | Roles con anti-escalada; roles del sistema protegidos; auditoría de cambios |
| CSRF | Token en todo POST (probado: sin token se rechaza) |
| XSS | Escape en todas las vistas y CSP `default-src 'self'` (sin scripts ni estilos en línea) |
| Inyección SQL | PDO con consultas preparadas; la IA no tiene herramienta SQL |
| Manipulación de datos | Usuario `bic_app` sin DDL; tablas de historial (`audit_logs`, `stock_movements`, pagos, etc.) solo-inserción; libro financiero sin DELETE; `db-check.php` lo verifica |
| Doble envío | Claves de idempotencia en cobros |
| Fuga de archivos | `.htaccess` y `public/` como única raíz expuesta; `config/local.php` y `src/` dan 403 |
| Pérdida de datos | Respaldos comprimidos con SHA-256, relectura al crear, separador aleatorio, restauración con confirmación |
| Prompt injection (IA) | Catálogo cerrado de 8 herramientas de lectura, resultados como `untrusted_data`, permisos revalidados, límites de longitud/rondas/uso diario |
| Datos entre negocios | `business_id` obligatorio en toda tabla de negocio (lo exige el linter) |

**Responsabilidades del operador:** `debug=false`, HTTPS si hay acceso fuera del equipo, copias de respaldo fuera de la máquina, contraseña root de MySQL no reutilizada, actualizar PHP/MariaDB, no compartir `config/local.php`.
