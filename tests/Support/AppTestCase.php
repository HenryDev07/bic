<?php

declare(strict_types=1);

namespace Bic\Tests\Support;

use Bic\Core\App;
use Bic\Core\Config;
use Bic\Core\Http\Request;
use Bic\Core\Http\Response;
use Bic\Core\Session;

/** Ejecuta peticiones completas en memoria (router + sesión + CSRF + permisos + vistas). */
abstract class AppTestCase extends DatabaseTestCase
{
    protected App $app;
    /** @var array<string,mixed> */
    protected array $store = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = [];
        $config = new Config(['app' => ['name' => 'BIC', 'debug' => true], 'storage' => ['path' => sys_get_temp_dir() . '/bic_test_' . getmypid()]]);
        $this->app = new App($config, $this->db, new Session($this->store), $this->clock, $this->root());
    }

    /** @param array<string,mixed> $query */
    protected function get(string $path, array $query = []): Response
    {
        return $this->app->handle(new Request('GET', $path, $query, [], ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'phpunit']));
    }

    /** @param array<string,mixed> $data */
    protected function post(string $path, array $data = [], bool $withCsrf = true): Response
    {
        if ($withCsrf) {
            $data['_csrf'] = $this->app->csrf->token();
        }

        return $this->app->handle(new Request('POST', $path, [], $data, ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'phpunit']));
    }

    protected function login(string $email, string $password = 'Secreto12345'): Response
    {
        return $this->post('/login', ['email' => $email, 'password' => $password]);
    }

    protected function loginAs(string $role): int
    {
        $id = $this->makeUser($role);
        $response = $this->login($this->emailOf($id));
        $this->assertSame(302, $response->status, 'el inicio de sesión de prueba debe redirigir');

        return $id;
    }
}
