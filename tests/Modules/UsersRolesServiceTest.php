<?php

declare(strict_types=1);

namespace Bic\Tests\Modules;

use Bic\Core\Audit\AuditLogger;
use Bic\Core\Auth\RoleRepository;
use Bic\Core\Settings\SettingsService;
use Bic\Modules\Roles\RoleService;
use Bic\Modules\Users\UserRepository;
use Bic\Modules\Users\UserService;
use Bic\Tests\Support\DatabaseTestCase;

final class UsersRolesServiceTest extends DatabaseTestCase
{
    private function users(): UserService
    {
        return new UserService(
            $this->db, new UserRepository($this->db), new RoleRepository($this->db),
            new SettingsService($this->db), new AuditLogger($this->db, $this->clock), $this->clock
        );
    }

    private function roles(): RoleService
    {
        return new RoleService($this->db, new RoleRepository($this->db), new AuditLogger($this->db, $this->clock));
    }

    /** @return array<string,mixed> */
    private function input(string $role, array $override = []): array
    {
        return array_merge(['name' => 'Ana Pérez', 'email' => 'Ana@Ejemplo.com', 'password' => 'ClaveSegura123', 'is_active' => true, 'roles' => [$this->roleId($role)]], $override);
    }

    public function testCreateUserHashesPasswordNormalizesEmailAndAudits(): void
    {
        $admin = $this->actorFor($this->makeUser('admin'));
        $result = $this->users()->create($admin, $this->input('seller'));

        $this->assertTrue($result['ok']);
        $row = $this->db->first('SELECT email, password_hash FROM users WHERE id = ?', [$result['id']]);
        $this->assertSame('ana@ejemplo.com', $row['email']);
        $this->assertTrue(password_verify('ClaveSegura123', (string) $row['password_hash']));
        $this->assertSame(1, $this->countRows('user_roles', 'user_id = ?', [$result['id']]));
        $audit = (string) $this->db->value("SELECT new_values FROM audit_logs WHERE action = 'user.create'");
        $this->assertStringNotContainsString('ClaveSegura123', $audit);
    }

    public function testCreateValidation(): void
    {
        $admin = $this->actorFor($this->makeUser('admin'));
        $this->makeUser('seller', 'ocupado@ejemplo.com');

        $this->assertArrayHasKey('email', $this->users()->create($admin, $this->input('seller', ['email' => 'OCUPADO@ejemplo.com']))['errors']);
        $this->assertArrayHasKey('password', $this->users()->create($admin, $this->input('seller', ['password' => 'corta']))['errors']);
        $this->assertArrayHasKey('roles', $this->users()->create($admin, $this->input('seller', ['roles' => []]))['errors']);
        $this->assertArrayHasKey('roles', $this->users()->create($admin, $this->input('seller', ['roles' => [9999]]))['errors']);
        $this->assertArrayHasKey('name', $this->users()->create($admin, $this->input('seller', ['name' => '']))['errors']);
    }

    public function testNoPrivilegeEscalationByAssigningRoles(): void
    {
        $manager = $this->actorFor($this->makeUser('manager'));
        $this->db->execute("INSERT OR IGNORE INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE code = 'users.create'", [$this->roleId('manager')]);
        $manager = $this->actorFor($manager['id']);

        $result = $this->users()->create($manager, $this->input('admin'));
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('no tienes', (string) $result['errors']['roles']);

        $roles = $this->users()->assignableRoles($manager);
        $byCode = array_column($roles, 'assignable', 'code');
        $this->assertFalse($byCode['admin']);
        $this->assertTrue($byCode['seller']);
    }

    public function testCannotEditUserWithMorePermissionsThanActor(): void
    {
        $adminId = $this->makeUser('admin');
        $manager = $this->actorFor($this->makeUser('manager'));

        $result = $this->users()->update($manager, $adminId, $this->input('manager', ['email' => $this->emailOf($adminId)]));
        $this->assertSame(403, $result['status']);
        $this->assertSame(404, $this->users()->update($manager, 99999, $this->input('manager'))['status']);
    }

    public function testCannotDeactivateSelf(): void
    {
        $id = $this->makeUser('admin');
        $this->makeUser('admin');
        $result = $this->users()->update($this->actorFor($id), $id, $this->input('admin', ['email' => $this->emailOf($id), 'is_active' => false]));

        $this->assertArrayHasKey('is_active', $result['errors']);
        $this->assertSame(1, $this->countRows('users', 'id = ? AND is_active = 1', [$id]));
    }

    public function testAlwaysKeepOneActiveAdmin(): void
    {
        $first = $this->makeUser('admin');
        $result = $this->users()->update($this->actorFor($first), $first, $this->input('analyst', ['email' => $this->emailOf($first)]));
        $this->assertStringContainsString('al menos un administrador', (string) $result['errors']['roles']);

        $second = $this->makeUser('admin');
        $ok = $this->users()->update($this->actorFor($first), $first, $this->input('analyst', ['email' => $this->emailOf($first)]));
        $this->assertTrue($ok['ok'], 'con otro administrador activo sí se puede');

        $deactivate = $this->users()->update($this->actorFor($second), $first, $this->input('analyst', ['email' => $this->emailOf($first), 'is_active' => false]));
        $this->assertTrue($deactivate['ok']);
        $this->assertSame(1, (new UserRepository($this->db))->countActiveAdmins($this->businessId(), null));
    }

    public function testPasswordResetAndAuditDiff(): void
    {
        $adminId = $this->makeUser('admin');
        $targetId = $this->makeUser('seller');
        $result = $this->users()->update($this->actorFor($adminId), $targetId, $this->input('seller', ['email' => $this->emailOf($targetId), 'password' => 'OtraClave98765']));

        $this->assertTrue($result['ok']);
        $this->assertTrue(password_verify('OtraClave98765', (string) $this->db->value('SELECT password_hash FROM users WHERE id = ?', [$targetId])));
        $audit = (string) $this->db->value("SELECT new_values FROM audit_logs WHERE action = 'user.update'");
        $this->assertStringContainsString('"credentials_changed":true', $audit);
        $this->assertStringNotContainsString('OtraClave98765', $audit);
    }

    public function testRolePermissionsRules(): void
    {
        $admin = $this->actorFor($this->makeUser('admin'));
        $sellerRole = $this->roleId('seller');

        $this->assertStringContainsString('no se puede modificar', (string) $this->roles()->updatePermissions($admin, $this->roleId('admin'), [])['error']);
        $this->assertSame(404, $this->roles()->updatePermissions($admin, 9999, [])['status']);
        $this->assertStringContainsString('no existen', (string) $this->roles()->updatePermissions($admin, $sellerRole, ['inventada.x'])['error']);

        $ok = $this->roles()->updatePermissions($admin, $sellerRole, ['dashboard.view', 'audit.view']);
        $this->assertTrue($ok['ok']);
        $this->assertSame(['audit.view', 'dashboard.view'], (new RoleRepository($this->db))->codesForRole($sellerRole));
        $this->assertSame(1, $this->countRows('audit_logs', "action = 'role.permissions_update'"));

        $this->roles()->updatePermissions($admin, $sellerRole, ['dashboard.view', 'audit.view']);
        $this->assertSame(1, $this->countRows('audit_logs', "action = 'role.permissions_update'"), 'sin cambios no se audita');
    }

    public function testCannotGrantPermissionsActorDoesNotHave(): void
    {
        $this->db->execute("INSERT OR IGNORE INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE code = 'roles.edit'", [$this->roleId('manager')]);
        $manager = $this->actorFor($this->makeUser('manager'));

        $result = $this->roles()->updatePermissions($manager, $this->roleId('seller'), ['dashboard.view', 'users.delete']);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('users.delete', (string) $result['error']);
        $this->assertSame(['customers.create', 'customers.edit', 'customers.view', 'dashboard.view', 'inventory.view', 'products.view', 'sales.create', 'sales.edit', 'sales.pay', 'sales.view'], (new RoleRepository($this->db))->codesForRole($this->roleId('seller')), 'users.delete no se concedió');
    }

    public function testSettingsValidationAndUpdate(): void
    {
        $settings = new SettingsService($this->db);
        $bad = $settings->update($this->businessId(), ['session_lifetime_minutes' => '2', 'max_login_attempts' => 'x', 'login_lockout_minutes' => '15', 'password_min_length' => '10']);
        $this->assertSame(['session_lifetime_minutes', 'max_login_attempts'], array_keys($bad['errors']));

        $good = $settings->update($this->businessId(), ['session_lifetime_minutes' => '60', 'max_login_attempts' => '4', 'login_lockout_minutes' => '30', 'password_min_length' => '12']);
        $this->assertSame([], $good['errors']);
        $this->assertSame(60, $settings->int($this->businessId(), 'session_lifetime_minutes'));
        $this->assertSame(12, (new SettingsService($this->db))->int($this->businessId(), 'password_min_length'), 'persistido en la base');
    }
}
