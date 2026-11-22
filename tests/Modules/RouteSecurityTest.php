<?php

declare(strict_types=1);

namespace Bic\Tests\Modules;

use Bic\Tests\Support\AppTestCase;

/** Recorre TODAS las rutas registradas: ninguna puede quedar sin protección. */
final class RouteSecurityTest extends AppTestCase
{
    /** @return list<array{method:string,path:string,permission:?string,public:bool}> */
    private function routes(): array
    {
        $out = [];
        foreach ($this->app->router->routes() as $r) {
            $p = substr($r['regex'], 2, -2);
            $p = preg_replace('/\(\?P<id>\\\\d\+\)/', '1', $p);
            $p = preg_replace('/\(\?P<\w+>\[\^\/\]\+\)/', 'x', $p);
            $out[] = ['method' => $r['method'], 'path' => stripslashes((string) $p), 'permission' => $r['permission'], 'public' => $r['public']];
        }

        return $out;
    }

    public function testThereAreRoutes(): void
    {
        $this->assertTrue(count($this->routes()) > 80);
    }

    public function testNoPrivateRouteIsReachableWithoutLogin(): void
    {
        foreach ($this->routes() as $r) {
            if ($r['public']) {
                continue;
            }
            $res = $r['method'] === 'GET' ? $this->get($r['path']) : $this->post($r['path'], []);
            $this->assertTrue(in_array($res->status, [302, 401, 403], true), "{$r['method']} {$r['path']} respondió {$res->status} sin sesión");
            if ($res->status === 302) {
                $this->assertStringContainsString('/login', (string) ($res->headers['Location'] ?? ''), "{$r['method']} {$r['path']}");
            }
        }
    }

    public function testPostWithoutCsrfIsRejectedEvenForAdmin(): void
    {
        $this->loginAs('admin');
        foreach ($this->routes() as $r) {
            if ($r['method'] !== 'POST' || $r['path'] === '/logout' || $r['public']) {
                continue;
            }
            $res = $this->post($r['path'], [], false);
            $this->assertTrue(in_array($res->status, [302, 400, 403, 419], true), "POST {$r['path']} sin CSRF respondió {$res->status}");
            $this->assertTrue($res->status !== 302 || !str_contains((string) ($res->headers['Location'] ?? ''), 'ok'), $r['path']);
        }
    }

    public function testEveryPermissionCodeExists(): void
    {
        $codes = array_column($this->db->select('SELECT code FROM permissions'), 'code');
        foreach ($this->routes() as $r) {
            if ($r['permission'] !== null) {
                $this->assertTrue(in_array($r['permission'], $codes, true), "permiso inexistente: {$r['permission']} en {$r['path']}");
            }
        }
    }

    public function testAdminCanOpenEveryParameterlessPage(): void
    {
        $this->loginAs('admin');
        foreach ($this->routes() as $r) {
            if ($r['method'] !== 'GET' || $r['public'] || str_contains($r['path'], '/1') || str_contains($r['path'], '/x')) {
                continue;
            }
            $res = $this->get($r['path']);
            $this->assertTrue(in_array($res->status, [200, 302], true), "GET {$r['path']} respondió {$res->status}");
        }
    }

    public function testUnprivilegedRoleIsDeniedOnProtectedPages(): void
    {
        $uid = $this->loginAs('seller');
        $denied = 0;
        $mine = $this->permissionsOf($uid);
        foreach ($this->routes() as $r) {
            if ($r['method'] !== 'GET' || $r['public'] || $r['permission'] === null || str_contains($r['path'], '/1') || str_contains($r['path'], '/x')) {
                continue;
            }
            $has = in_array($r['permission'], $mine, true);
            if (!$has) {
                $this->assertSame(403, $this->get($r['path'])->status, "{$r['path']} debía dar 403");
                $denied++;
            }
        }
        $this->assertTrue($denied > 5);
    }
}
