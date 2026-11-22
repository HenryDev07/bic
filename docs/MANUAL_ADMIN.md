# BIC — Manual del administrador

## Instalación (WAMP, Windows)
1. Requisitos: PHP 8.3 (extensiones `pdo_mysql`, `mbstring`, `json`, `ctype`, `openssl`; recomendadas `zlib` para respaldos, `zip` para Excel, `curl` para IA), MariaDB/MySQL, Composer, Apache con `mod_rewrite`.
2. Carpeta del proyecto en `C:\wamp64\www\bic`; en una terminal: `composer install`, `php bin/install.php` (pide la contraseña root solo para esa ejecución), `php bin/create-admin.php`.
3. Abre `http://localhost/bic/`. Comprueba que `http://localhost/bic/config/local.php` dé **403**.
4. Verifica: `php bin/db-check.php`, `php bin/doctor.php`, `composer test`.

## Configuración
- `config/local.php` (no se versiona): base de datos, `app.url`, `app.debug` (**false** en uso real), `ai.*`. Alternativa: variables `BIC_*` (ver `config/local.example.php`).
- *Configuración* en la aplicación: moneda base (USD), impuestos, reserva de stock, umbrales de inteligencia, límite diario de IA.
- Usuarios y roles: *Usuarios* / *Roles*. Un rol no puede dar permisos que su creador no tenga (anti-escalada). Desactiva usuarios en lugar de borrarlos.

## Respaldos (obligatorio)
```
php bin/backup.php --keep=14
```
Crea `storage/backups/bic_<base>_<fecha>.sql.gz` + `.sha256`, lo relee para comprobarlo y conserva los 14 más recientes. Usa solo el usuario `bic_app`.

**Programación diaria (Programador de tareas):**
```
schtasks /Create /SC DAILY /ST 23:30 /TN "BIC Respaldo" /TR "C:\wamp64\bin\php\php8.3.14\php.exe C:\wamp64\www\bic\bin\backup.php --keep=14"
```
Copia periódicamente `storage/backups` a **otro disco o la nube**: un respaldo en la misma máquina no protege de un fallo del disco.

## Restauración
```
php bin/restore.php storage\backups\bic_..._.sql.gz
```
Verifica suma y archivo completo, pide escribir el nombre de la base como confirmación y la credencial de administrador de MySQL. **Reemplaza** las tablas actuales. Pruébala al menos una vez en una base de prueba (`BIC_DB_NAME`) antes de necesitarla.

## Actualizaciones
Respalda → copia el código nuevo → `composer install` → `php bin/migrate.php --seed` → `php bin/doctor.php`. Las migraciones aplicadas no se editan: `doctor` avisa si alguna fue modificada.

## IA
En `config/local.php`: `ai.provider` (`anthropic`, `openai` u omitir para modo local), `ai.api_key`, `ai.model`, `ai.base_url` (servidores compatibles). La clave nunca se muestra ni se guarda en la base. Permiso `ai.use` por rol.

## Diagnóstico
| Síntoma | Qué hacer |
|---|---|
| 404 en todas las rutas | Activar `rewrite_module` y `AllowOverride All` |
| «Falta la extensión zip» al exportar Excel | Activar `zip` en php.ini (CLI y Apache) |
| Respaldo falla por zlib | Activar `zlib` |
| Cuenta bloqueada | Esperar el bloqueo temporal o que otro admin restablezca la contraseña |
| `doctor` reporta descuadre | No «arreglar» a mano: revisar auditoría y kardex del documento indicado |
| Pantalla de error genérica | Ver `storage/logs`; nunca dejar `debug=true` en producción |
