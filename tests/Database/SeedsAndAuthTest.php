<?php

declare(strict_types=1);

namespace Bic\Tests\Database;

use Bic\Core\Audit\AuditLogger;
use Bic\Core\Auth\AuthService;
use Bic\Core\Session;
use Bic\Core\Settings\SettingsService;
use Bic\Tests\Support\DatabaseTestCase;
use Bic\Tests\Support\SqliteSchema;

final class SeedsAndAuthTest extends DatabaseTestCase
{
    private function auth(): AuthService
    {
        $settings = new SettingsService($this->db);

        return new AuthService($this->db, $settings, new AuditLogger($this->db, $this->clock), $this->clock);
    }

    public function testSeedsAreIdempotent(): void
    {
        $before = [$this->countRows('businesses'), $this->countRows('warehouses'), $this->countRows('settings'), $this->countRows('roles'), $this->countRows('permissions'), $this->countRows('role_permissions')];
        SqliteSchema::seed($this->pdo, $this->root() . '/database/seeds');
        SqliteSchema::seed($this->pdo, $this->root() . '/database/seeds');
        $after = [$this->countRows('businesses'), $this->countRows('warehouses'), $this->countRows('settings'), $this->countRows('roles'), $this->countRows('permissions'), $this->countRows('role_permissions')];

        $this->assertSame($before, $after);
        $this->assertSame(1, $before[0]);
        $this->assertSame(4, $before[3]);
    }

    public function testRoleDefaults(): void
    {
        $perms = static fn (self $t, string $role): array => array_map(
            static fn (array $r): string => (string) $r['code'],
            $t->db->select('SELECT p.code FROM permissions p JOIN role_permissions rp ON rp.permission_id = p.id WHERE rp.role_id = ? ORDER BY p.code', [$t->roleId($role)])
        );

        $this->assertSame($this->countRows('permissions'), count($perms($this, 'admin')), 'el administrador tiene todos los permisos');
        $this->assertSame([
            'ai.use', 'audit.view', 'catalog.manage', 'catalog.view', 'customers.create', 'customers.edit', 'customers.view', 'dashboard.view', 'expenses.create', 'expenses.view', 'expenses.void', 'finance.create', 'finance.manage', 'finance.view', 'finance.void',
            'insights.view', 'inventory.adjust', 'inventory.view', 'products.cost', 'products.create', 'products.edit', 'products.view', 'purchases.cancel', 'purchases.create', 'purchases.pay', 'purchases.receive', 'purchases.view', 'reports.export', 'reports.view', 'research.manage', 'research.view', 'sales.cancel', 'sales.create', 'sales.edit', 'sales.pay', 'sales.refund', 'sales.return', 'sales.view', 'settings.view', 'suppliers.create', 'suppliers.edit', 'suppliers.view',
        ], $perms($this, 'manager'));
        $this->assertSame(['ai.use', 'catalog.view', 'customers.view', 'dashboard.view', 'expenses.view', 'finance.view', 'insights.view', 'inventory.view', 'products.cost', 'products.view', 'purchases.view', 'reports.export', 'reports.view', 'research.view', 'sales.view', 'suppliers.view'], $perms($this, 'analyst'));
        $this->assertSame(['customers.create', 'customers.edit', 'customers.view', 'dashboard.view', 'inventory.view', 'products.view', 'sales.create', 'sales.edit', 'sales.pay', 'sales.view'], $perms($this, 'seller'), 'el vendedor ve precios y existencias pero no costos, compras ni finanzas');
    }

    public function testLoginSuccessUpdatesSessionAndAudit(): void
    {
        $id = $this->makeUser('admin', 'Jefe@Ejemplo.com');
        $store = [];
        $result = $this->auth()->attempt(new Session($store), '  jefe@ejemplo.com ', 'Secreto12345', '127.0.0.1', 'ua');

        $this->assertTrue($result['ok']);
        $this->assertSame($id, $store['user_id']);
        $this->assertSame('2026-01-15 12:00:00', $this->db->value('SELECT last_login_at FROM users WHERE id = ?', [$id]));
        $this->assertSame(1, $this->countRows('audit_logs', "action = 'auth.login' AND user_id = ?", [$id]));
    }

    public function testBadCredentialsDoNotRevealWhichPartFailed(): void
    {
        $id = $this->makeUser('admin');
        $store = [];
        $wrongPass = $this->auth()->attempt(new Session($store), $this->emailOf($id), 'incorrecta1', '127.0.0.1', 'ua');
        $unknown = $this->auth()->attempt(new Session($store), 'nadie@ejemplo.com', 'incorrecta1', '127.0.0.1', 'ua');

        $this->assertFalse($wrongPass['ok']);
        $this->assertSame($wrongPass['error'], $unknown['error']);
        $this->assertSame([], $store, 'no se abre sesión');
        $this->assertSame(1, $this->countRows('audit_logs', "action = 'auth.login_failed'"));
        $this->assertSame(2, $this->countRows('login_attempts', 'success = 0'));
    }

    public function testInactiveUserCannotLogIn(): void
    {
        $id = $this->makeUser('admin', null, 'Secreto12345', false);
        $store = [];

        $this->assertFalse($this->auth()->attempt(new Session($store), $this->emailOf($id), 'Secreto12345', '127.0.0.1', 'ua')['ok']);
    }

    public function testLockoutAfterMaxAttemptsAndRecovery(): void
    {
        $id = $this->makeUser('admin');
        $email = $this->emailOf($id);
        $auth = $this->auth();
        $store = [];

        for ($i = 0; $i < 5; $i++) {
            $auth->attempt(new Session($store), $email, 'mala-clave-' . $i, '127.0.0.1', 'ua');
        }
        $blocked = $auth->attempt(new Session($store), $email, 'Secreto12345', '127.0.0.1', 'ua');
        $this->assertFalse($blocked['ok'], 'aun con la clave correcta, el bloqueo manda');
        $this->assertStringContainsString('Demasiados intentos', (string) $blocked['error']);

        $this->clock->advance(16 * 60);
        $this->assertTrue($auth->attempt(new Session($store), $email, 'Secreto12345', '127.0.0.1', 'ua')['ok']);
        $this->assertSame(0, $this->countRows('login_attempts', 'email = ? AND success = 0', [$email]), 'un acceso correcto borra los fallos');
    }

    public function testChangePassword(): void
    {
        $id = $this->makeUser('admin');
        $user = $this->auth()->findActiveUser($id);
        $auth = $this->auth();

        $this->assertStringContainsString('actual', (string) $auth->changePassword($user, 'incorrecta', 'NuevaClave123', '127.0.0.1', 'ua'));
        $this->assertStringContainsString('al menos', (string) $auth->changePassword($user, 'Secreto12345', 'corta1', '127.0.0.1', 'ua'));
        $this->assertNull($auth->changePassword($user, 'Secreto12345', 'NuevaClave123', '127.0.0.1', 'ua'));

        $store = [];
        $this->assertTrue($auth->attempt(new Session($store), $this->emailOf($id), 'NuevaClave123', '127.0.0.1', 'ua')['ok']);
    }

    public function testAuditLogRedactsSecretsAndForeignKeysAreEnforced(): void
    {
        (new AuditLogger($this->db, $this->clock))->log($this->businessId(), null, 'x.test', 'thing', 1, null, ['password' => 'secreto', 'nested' => ['api_token' => 'abc', 'ok' => 'visible']]);
        $json = (string) $this->db->value("SELECT new_values FROM audit_logs WHERE action = 'x.test'");

        $this->assertStringNotContainsString('secreto', $json);
        $this->assertStringNotContainsString('abc', $json);
        $this->assertStringContainsString('visible', $json);

        $this->expectException(\PDOException::class);
        $this->db->execute('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)', [999999, 1]);
    }
}
