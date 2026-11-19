<?php

declare(strict_types=1);

/**
 * Crea (o añade) un usuario Administrador desde la línea de comandos.
 * Usa la conexión de la aplicación (bic_app); no necesita el usuario root.
 *
 * Uso:  php bin/create-admin.php
 * Opcional: variables BIC_ADMIN_NAME, BIC_ADMIN_EMAIL, BIC_ADMIN_PASSWORD (para no teclearlas).
 */

use Bic\Core\Audit\AuditLogger;
use Bic\Core\Auth\PasswordPolicy;
use Bic\Core\Auth\RoleRepository;
use Bic\Core\Clock;
use Bic\Core\Cli;
use Bic\Core\Database\Database;
use Bic\Core\Settings\SettingsService;
use Bic\Core\Validation\Validator;
use Bic\Modules\Users\UserRepository;

$config = require dirname(__DIR__) . '/bootstrap/app.php';

try {
    $db = Database::fromConfig($config);
    $businessId = (int) $db->value('SELECT MIN(id) FROM businesses');
    if ($businessId === 0) {
        Cli::fail('No hay negocio. Ejecuta primero: php bin/install.php');
    }
} catch (Throwable $e) {
    Cli::fail('No se pudo conectar: ' . $e->getMessage());
}

$name = getenv('BIC_ADMIN_NAME') ?: Cli::prompt('Nombre completo: ');
$email = mb_strtolower(trim(getenv('BIC_ADMIN_EMAIL') ?: Cli::prompt('Correo electrónico: ')));
$password = getenv('BIC_ADMIN_PASSWORD') ?: Cli::prompt('Contraseña (se mostrará en pantalla en Windows): ', true);

$errors = Validator::validate(['name' => $name, 'email' => $email], ['name' => 'required|max:150', 'email' => 'required|email|max:190'], ['name' => 'Nombre', 'email' => 'Correo']);
$settings = new SettingsService($db);
$policy = PasswordPolicy::check($password, $email, $settings->int($businessId, 'password_min_length'));
if ($policy !== null) {
    $errors['password'] = $policy;
}
$users = new UserRepository($db);
if (!isset($errors['email']) && $users->emailTaken($email, null)) {
    $errors['email'] = 'Ya existe un usuario con ese correo.';
}
if ($errors !== []) {
    Cli::fail(implode(' ', $errors));
}

$clock = new Clock();
$adminRole = (new RoleRepository($db))->idByCode('admin') ?? Cli::fail('No existe el rol admin. Ejecuta: php bin/migrate.php --seed');

$id = $db->transaction(function (Database $d) use ($users, $businessId, $name, $email, $password, $clock, $adminRole): int {
    $id = $users->insert($businessId, trim($name), $email, PasswordPolicy::hash($password), true, $clock->sql());
    $users->setRoles($id, [$adminRole]);
    (new AuditLogger($d, $clock))->log($businessId, null, 'user.create', 'user', $id, null, ['email' => $email, 'roles' => [$adminRole], 'origin' => 'cli']);

    return $id;
});

Cli::ok("Administrador creado (id {$id}). Ya puedes iniciar sesión en la aplicación.");
