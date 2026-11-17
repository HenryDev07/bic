# BIC — Business Intelligence Commerce

Estado: **Etapa 2 (base de datos)**. Arquitectura y plan completos en [`docs/ARQUITECTURA.md`](docs/ARQUITECTURA.md).

## Requisitos
PHP 8.3+ (extensiones `pdo_mysql`, `mbstring`), MySQL 8 / MariaDB 10.4+, Composer.

## Instalación (Windows + WAMP)
Abre una terminal en la carpeta del proyecto (usa el `php.exe` de WAMP, p. ej. `C:\wamp64\bin\php\php8.3.x\php.exe`, o añádelo al PATH).

```
composer install
php bin/install.php
php bin/db-check.php
composer test
```

* `install.php` pide la contraseña del administrador de MySQL (root) **solo para esa ejecución; no se guarda**. Crea la base `business_intelligence_commerce`, aplica migraciones y datos iniciales, crea el usuario limitado `bic_app` con contraseña aleatoria y escribe `config/local.php`.
* Para no teclearla: `set BIC_DB_ADMIN_PASSWORD=...` (PowerShell: `$env:BIC_DB_ADMIN_PASSWORD="..."`) antes del comando.
* `db-check.php` conecta como `bic_app` y comprueba que **no** puede alterar el esquema ni editar `audit_logs`.
* Alternativa por phpMyAdmin: importar `database/schema.sql` (solo estructura), luego `php bin/migrate.php --seed`.

## Flujo de trabajo del esquema
| Quiero… | Comando |
|---|---|
| Cambiar la base de datos | Crear `database/migrations/000N_descripcion.sql` (nunca editar una ya aplicada) |
| Aplicarla | `php bin/migrate.php` (también actualiza los privilegios de `bic_app`) |
| Regenerar `schema.sql` | `php bin/schema.php build` |
| Validar sin MySQL | `php bin/schema.php lint` y `php bin/schema.php check` |

`composer test` falla si `schema.sql` no coincide con las migraciones.

## Variables de entorno (opcionales, para servidor)
`BIC_DB_HOST`, `BIC_DB_PORT`, `BIC_DB_NAME`, `BIC_DB_USER`, `BIC_DB_PASSWORD`, `BIC_ENV`, `BIC_DEBUG`, `BIC_URL`, `BIC_STORAGE_PATH`, y para CLI: `BIC_DB_ADMIN_USER`, `BIC_DB_ADMIN_PASSWORD`, `BIC_DB_APP_HOST` (por defecto `localhost`).

## Git
```
git init && git add . && git commit -m "Etapa 2: base de datos, migraciones y provisión de usuario"
```
`config/local.php` y `vendor/` ya están en `.gitignore`.
