<?php

declare(strict_types=1);

namespace Bic\Modules\Auth;

use Bic\Core\Controller;
use Bic\Core\Http\Request;
use Bic\Core\Http\Response;

final class AuthController extends Controller
{
    public function loginForm(): Response
    {
        if ($this->app->user() !== null) {
            return $this->redirect('/');
        }

        return $this->render('auth::login', ['title' => 'Iniciar sesión', 'email' => '', 'error' => null]);
    }

    public function login(Request $request): Response
    {
        if ($this->app->user() !== null) {
            return $this->redirect('/');
        }
        $email = (string) $request->string('email', '');
        $result = $this->app->auth->attempt(
            $this->app->session, $email, (string) ($request->post['password'] ?? ''), $request->ip(), $request->userAgent()
        );

        if (!$result['ok']) {
            return $this->render('auth::login', ['title' => 'Iniciar sesión', 'email' => $email, 'error' => $result['error']], 401);
        }

        $intended = $this->app->session->get('intended');
        $this->app->session->forget('intended');
        $safe = is_string($intended) && str_starts_with($intended, '/') && !str_starts_with($intended, '//') && !str_contains($intended, '\\');

        return $this->redirect($safe ? $intended : '/');
    }

    public function logout(Request $request): Response
    {
        $this->app->auth->logout($this->app->session, $this->app->user(), $request->ip(), $request->userAgent());

        return $this->redirect('/login');
    }

    public function profile(): Response
    {
        return $this->profileView(null);
    }

    public function changePassword(Request $request): Response
    {
        $new = (string) ($request->post['new_password'] ?? '');
        if ($new !== (string) ($request->post['new_password_confirm'] ?? '')) {
            return $this->profileView('La confirmación no coincide con la nueva contraseña.', 422);
        }
        $error = $this->app->auth->changePassword(
            $this->app->user() ?? [], (string) ($request->post['current_password'] ?? ''), $new, $request->ip(), $request->userAgent()
        );
        if ($error !== null) {
            return $this->profileView($error, 422);
        }
        $this->flash('success', 'Contraseña actualizada.');

        return $this->redirect('/profile');
    }

    public function theme(Request $request): Response
    {
        $theme = $request->string('theme');
        if (!in_array($theme, ['light', 'dark'], true)) {
            return Response::json(['ok' => false], 422);
        }
        $this->app->db->execute('UPDATE users SET theme = ? WHERE id = ?', [$theme, (int) $this->app->user()['id']]);

        return Response::json(['ok' => true]);
    }

    private function profileView(?string $error, int $status = 200): Response
    {
        $minLength = $this->app->settings->int((int) $this->app->user()['business_id'], 'password_min_length');

        return $this->render('auth::profile', ['title' => 'Mi perfil', 'error' => $error, 'minLength' => $minLength], $status);
    }
}
