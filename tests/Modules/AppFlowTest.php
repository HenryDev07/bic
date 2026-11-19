<?php

declare(strict_types=1);

namespace Bic\Tests\Modules;

use Bic\Tests\Support\AppTestCase;

/** Peticiones completas: router, sesión, CSRF, permisos, controladores y vistas. */
final class AppFlowTest extends AppTestCase
{
    public function testAnonymousIsRedirectedToLoginAndLoginPageRenders(): void
    {
        $response = $this->get('/users');
        $this->assertSame(302, $response->status);
        $this->assertSame('/login', $response->headers['Location']);

        $login = $this->get('/login');
        $this->assertSame(200, $login->status);
        $this->assertStringContainsString('name="_csrf"', $login->body);
        $this->assertStringContainsString('Iniciar sesión', $login->body);
    }

    public function testEveryPostRequiresCsrf(): void
    {
        $id = $this->makeUser('admin');
        $this->get('/login');
        $this->assertSame(419, $this->post('/login', ['email' => $this->emailOf($id), 'password' => 'Secreto12345'], false)->status);

        $this->loginAs('admin');
        $this->assertSame(419, $this->post('/logout', [], false)->status);
        $this->assertSame(419, $this->post('/users', ['name' => 'x'], false)->status);
    }

    public function testLoginFailureAndSuccessRedirectToIntendedPage(): void
    {
        $id = $this->makeUser('admin');
        $this->get('/audit');
        $bad = $this->login($this->emailOf($id), 'incorrecta1');
        $this->assertSame(401, $bad->status);
        $this->assertStringContainsString('Correo o contraseña incorrectos.', $bad->body);

        $ok = $this->login($this->emailOf($id));
        $this->assertSame('/audit', $ok->headers['Location'], 'vuelve a la página que pidió');
        $this->assertSame(200, $this->get('/')->status);
        $this->assertStringContainsString('Hola, Admin Prueba', $this->get('/')->body);
    }

    public function testPermissionsAreEnforcedOnTheServer(): void
    {
        $this->loginAs('seller');
        $dashboard = $this->get('/');
        $this->assertSame(200, $dashboard->status);
        $this->assertStringNotContainsString('href="/users"', $dashboard->body, 'el menú oculta lo que no puede usar');

        foreach (['/users', '/users/create', '/roles', '/settings', '/audit'] as $path) {
            $this->assertSame(403, $this->get($path)->status, "GET {$path}");
        }
        $this->assertSame(403, $this->post('/users', ['name' => 'x', 'email' => 'x@x.co', 'password' => 'ClaveSegura123', 'roles' => [1]])->status);
        $this->assertSame(0, $this->countRows('users', "email = 'x@x.co'"));
        $this->assertSame(403, $this->post('/roles/' . $this->roleId('seller'), ['perm' => ['users.view']])->status);
        $this->assertSame(403, $this->post('/settings', ['max_login_attempts' => '9'])->status);
    }

    public function testAdminCanListSearchCreateAndEditUsers(): void
    {
        $this->loginAs('admin');
        $this->makeUser('seller', 'maria@ejemplo.com');

        $list = $this->get('/users');
        $this->assertSame(200, $list->status);
        $this->assertStringContainsString('maria@ejemplo.com', $list->body);
        $this->assertStringNotContainsString('maria@ejemplo.com', $this->get('/users', ['q' => 'zzz'])->body);
        $this->assertStringContainsString('maria@ejemplo.com', $this->get('/users', ['q' => 'MARIA'])->body);

        $created = $this->post('/users', ['name' => 'Luis', 'email' => 'luis@ejemplo.com', 'password' => 'ClaveSegura123', 'is_active' => '1', 'roles' => [(string) $this->roleId('analyst')]]);
        $this->assertSame(302, $created->status);
        $luis = (int) $this->db->value("SELECT id FROM users WHERE email = 'luis@ejemplo.com'");
        $this->assertTrue($luis > 0);

        $dup = $this->post('/users', ['name' => 'Otro', 'email' => 'luis@ejemplo.com', 'password' => 'ClaveSegura123', 'roles' => [(string) $this->roleId('analyst')]]);
        $this->assertSame(422, $dup->status);
        $this->assertStringContainsString('Ya existe un usuario con ese correo.', $dup->body);

        $this->assertSame(200, $this->get("/users/{$luis}/edit")->status);
        $update = $this->post("/users/{$luis}", ['name' => 'Luis R.', 'email' => 'luis@ejemplo.com', 'roles' => [(string) $this->roleId('seller')]]);
        $this->assertSame(302, $update->status);
        $this->assertSame(0, $this->countRows('users', "id = {$luis} AND is_active = 1"), 'sin la casilla, queda inactivo');
        $this->assertSame(404, $this->get('/users/99999/edit')->status);
    }

    public function testUserContentIsEscapedAgainstXss(): void
    {
        $this->loginAs('admin');
        $this->post('/users', ['name' => '<script>alert(1)</script>', 'email' => 'xss@ejemplo.com', 'password' => 'ClaveSegura123', 'is_active' => '1', 'roles' => [(string) $this->roleId('seller')]]);
        $body = $this->get('/users')->body;

        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
    }

    public function testRolesAndSettingsAndAuditScreens(): void
    {
        $this->loginAs('admin');
        $seller = $this->roleId('seller');

        $this->assertSame(200, $this->get('/roles')->status);
        $this->assertStringContainsString('siempre tiene todos los permisos', $this->get('/roles/' . $this->roleId('admin'))->body);
        $this->assertSame(302, $this->post("/roles/{$seller}", ['perm' => ['dashboard.view', 'audit.view']])->status);
        $this->assertSame(422, $this->post('/roles/' . $this->roleId('admin'), ['perm' => []])->status);

        $this->assertSame(200, $this->get('/settings')->status);
        $this->assertSame(422, $this->post('/settings', ['session_lifetime_minutes' => '1', 'max_login_attempts' => '5', 'login_lockout_minutes' => '15', 'password_min_length' => '10'])->status);
        $this->assertSame(302, $this->post('/settings', ['session_lifetime_minutes' => '90', 'max_login_attempts' => '5', 'login_lockout_minutes' => '15', 'password_min_length' => '10'])->status);

        $audit = $this->get('/audit');
        $this->assertSame(200, $audit->status);
        $this->assertStringContainsString('role.permissions_update', $audit->body);
        $this->assertStringContainsString('settings.update', $audit->body);
        $this->assertStringContainsString('role.permissions_update', $this->get('/audit', ['action' => 'role.permissions_update'])->body);
        $this->assertStringNotContainsString('settings.update</span>', $this->get('/audit', ['action' => 'role.permissions_update'])->body);
        $this->assertSame(200, $this->get('/audit', ['from' => '2026-01-15', 'to' => '2026-01-15'])->status);
    }

    public function testProfilePasswordChangeAndThemeSave(): void
    {
        $id = $this->loginAs('seller');
        $this->assertSame(200, $this->get('/profile')->status);
        $this->assertSame(422, $this->post('/profile/password', ['current_password' => 'Secreto12345', 'new_password' => 'NuevaClave123', 'new_password_confirm' => 'distinta'])->status);
        $this->assertSame(302, $this->post('/profile/password', ['current_password' => 'Secreto12345', 'new_password' => 'NuevaClave123', 'new_password_confirm' => 'NuevaClave123'])->status);
        $this->assertTrue(password_verify('NuevaClave123', (string) $this->db->value('SELECT password_hash FROM users WHERE id = ?', [$id])));

        $this->assertSame(200, $this->post('/preferences/theme', ['theme' => 'dark'])->status);
        $this->assertSame('dark', $this->db->value('SELECT theme FROM users WHERE id = ?', [$id]));
        $this->assertStringContainsString('data-theme="dark"', $this->get('/')->body);
        $this->assertSame(422, $this->post('/preferences/theme', ['theme' => 'rojo'])->status);
    }

    public function testLogoutErrorsAndSessionTimeout(): void
    {
        $this->assertSame(404, $this->get('/no-existe')->status);

        $this->loginAs('admin');
        $this->assertSame(405, $this->get('/logout')->status);
        $this->assertSame(302, $this->post('/logout')->status);
        $this->assertSame('/login', $this->get('/')->headers['Location'], 'tras cerrar sesión se exige iniciar de nuevo');

        $this->loginAs('admin');
        $this->clock->advance(121 * 60);
        $expired = $this->get('/');
        $this->assertSame('/login', $expired->headers['Location']);
        $this->assertStringContainsString('expiró por inactividad', $this->get('/login')->body);
    }

    public function testDeactivatedUserIsKickedOutImmediately(): void
    {
        $id = $this->loginAs('seller');
        $this->assertSame(200, $this->get('/')->status);
        $this->db->execute('UPDATE users SET is_active = 0 WHERE id = ?', [$id]);

        $this->assertSame('/login', $this->get('/')->headers['Location']);
    }

    public function testPermissionChangesApplyOnNextRequest(): void
    {
        $this->loginAs('seller');
        $this->assertSame(403, $this->get('/audit')->status);
        $this->db->execute("INSERT INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE code = 'audit.view'", [$this->roleId('seller')]);

        $this->assertSame(200, $this->get('/audit')->status);
    }
}
