<?php

declare(strict_types=1);

namespace Bic\Tests\Core;

use Bic\Core\Auth\PasswordPolicy;
use Bic\Core\Http\Request;
use Bic\Core\Http\Router;
use Bic\Core\Paginator;
use Bic\Core\Security\Csrf;
use Bic\Core\Security\Gate;
use Bic\Core\Session;
use Bic\Core\Validation\Validator;
use Bic\Core\View;
use PHPUnit\Framework\TestCase;

final class WebCoreTest extends TestCase
{
    public function testRouterMatchesParamsAnd404And405(): void
    {
        $router = new Router();
        $router->get('/users/{id}/edit', ['X', 'edit'], 'users.edit');
        $router->post('/users/{id}', ['X', 'update'], 'users.edit');

        $hit = $router->match('GET', '/users/42/edit');
        $this->assertSame('42', $hit['params']['id']);
        $this->assertSame('users.edit', $hit['route']['permission']);
        $this->assertSame(404, $router->match('GET', '/nada')['error']);
        $this->assertSame(404, $router->match('GET', '/users/abc/edit')['error'], '{id} solo acepta dígitos');
        $this->assertSame(405, $router->match('GET', '/users/42')['error']);
    }

    public function testCsrfTokenIsStableAndValidatedInConstantTime(): void
    {
        $store = [];
        $csrf = new Csrf(new Session($store));
        $token = $csrf->token();
        $this->assertSame(64, strlen($token));
        $this->assertSame($token, $csrf->token());
        $this->assertTrue($csrf->validate($token));
        $this->assertFalse($csrf->validate('otro'));
        $this->assertFalse($csrf->validate(null));
        $this->assertFalse($csrf->validate(['array']));
        $this->assertStringContainsString('name="_csrf"', $csrf->field());
    }

    public function testCsrfFailsWithoutSessionToken(): void
    {
        $store = [];
        $this->assertFalse((new Csrf(new Session($store)))->validate(''));
    }

    public function testGate(): void
    {
        $gate = new Gate(['users.view']);
        $this->assertTrue($gate->can('users.view'));
        $this->assertFalse($gate->can('users.edit'));
        $this->assertFalse((new Gate())->can('users.view'));
    }

    public function testSessionFlashIsPulledOnce(): void
    {
        $store = [];
        $session = new Session($store);
        $session->flash('success', 'ok');
        $this->assertCount(1, $session->pullFlashes());
        $this->assertCount(0, $session->pullFlashes());
        $session->set('a', 1);
        $session->invalidate();
        $this->assertNull($session->get('a'));
    }

    public function testValidator(): void
    {
        $errors = Validator::validate(
            ['name' => '', 'email' => 'no-es-correo', 'age' => '12x', 'mode' => 'z', 'long' => str_repeat('a', 11), 'n' => '50'],
            ['name' => 'required', 'email' => 'required|email', 'age' => 'int', 'mode' => 'in:a,b', 'long' => 'max:10', 'n' => 'between:1,10', 'opt' => 'email']
        );
        $this->assertSame(['name', 'email', 'age', 'mode', 'long', 'n'], array_keys($errors));
        $this->assertSame([], Validator::validate(['email' => 'a@b.co', 'pass' => 'x', 'pass2' => 'x'], ['email' => 'required|email', 'pass2' => 'same:pass']));
    }

    public function testViewEscapesAndRejectsBadTemplateNames(): void
    {
        $this->assertSame('&lt;b&gt;&quot;x&quot; &amp; &#039;y&#039;', View::e('<b>"x" & \'y\''));
        $this->expectException(\InvalidArgumentException::class);
        (new View(dirname(__DIR__, 2)))->render('core::../../config/app');
    }

    public function testRequestStripsBasePathAndIgnoresForwardedIp(): void
    {
        $old = [$_SERVER, $_GET, $_POST];
        $_SERVER = ['SCRIPT_NAME' => '/bic/public/index.php', 'REQUEST_URI' => '/bic/public/users//5/edit/?x=1', 'REQUEST_METHOD' => 'get', 'REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6'];
        $_GET = ['x' => '1'];
        $_POST = [];
        $request = Request::fromGlobals();
        [$_SERVER, $_GET, $_POST] = $old;

        $this->assertSame('/users/5/edit', $request->path);
        $this->assertSame('/bic/public', $request->basePath);
        $this->assertSame('GET', $request->method);
        $this->assertSame('10.0.0.5', $request->ip());
    }

    public function testPaginatorAndLikeEscaping(): void
    {
        $this->assertSame(['page' => 3, 'pages' => 3, 'per_page' => 25, 'offset' => 50, 'total' => 60], Paginator::make(60, 99));
        $this->assertSame(1, Paginator::make(0, -4)['page']);
        $this->assertSame('%50!%!_x!!%', Paginator::like('50%_x!'));
        $this->assertSame('/u?a=1&page=2', Paginator::url('/u', ['a' => 1, 'b' => ''], 2));
    }

    public function testPasswordPolicy(): void
    {
        $this->assertNull(PasswordPolicy::check('Secreto12345', 'a@b.co', 10));
        $this->assertStringContainsString('al menos 10', (string) PasswordPolicy::check('corta1', 'a@b.co', 10));
        $this->assertStringContainsString('letra y un número', (string) PasswordPolicy::check('sololetrasaqui', 'a@b.co', 10));
        $this->assertStringContainsString('72', (string) PasswordPolicy::check(str_repeat('a1', 40), 'a@b.co', 10));
        $this->assertStringContainsString('igual al correo', (string) PasswordPolicy::check('usuario1@dominio.com', 'usuario1@dominio.com', 10));
    }
}
