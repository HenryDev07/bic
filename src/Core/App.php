<?php

declare(strict_types=1);

namespace Bic\Core;

use Bic\Core\Audit\AuditLogger;
use Bic\Core\Auth\AuthService;
use Bic\Core\Http\HttpException;
use Bic\Core\Http\Request;
use Bic\Core\Money\Decimal;
use Bic\Core\Http\Response;
use Bic\Core\Http\Router;
use Bic\Core\Database\Database;
use Bic\Core\Security\Csrf;
use Bic\Core\Security\Gate;
use Bic\Core\Settings\SettingsService;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Núcleo de la aplicación: para cada petición resuelve la sesión, busca la ruta y
 * aplica, en este orden, autenticación → CSRF (todo POST) → permiso → controlador.
 */
final class App
{
    public readonly Router $router;
    public readonly ModuleRegistry $modules;
    public readonly Csrf $csrf;
    public readonly View $view;
    public readonly SettingsService $settings;
    public readonly AuditLogger $auditLogger;
    public readonly AuthService $auth;
    public readonly Logger $logger;

    private ?Request $request = null;
    /** @var array<string,mixed>|null */
    private ?array $user = null;
    private ?Gate $gate = null;
    private ?string $timezone = null;

    public function __construct(
        public readonly Config $config,
        public readonly Database $db,
        public readonly Session $session,
        public readonly Clock $clock,
        private readonly string $root,
    ) {
        $this->csrf = new Csrf($session);
        $this->view = new View($root);
        $this->settings = new SettingsService($db);
        $this->auditLogger = new AuditLogger($db, $clock);
        $this->auth = new AuthService($db, $this->settings, $this->auditLogger, $clock);

        $storage = (string) $config->get('storage.path', 'storage');
        $absolute = str_starts_with($storage, '/') || (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $storage);
        $this->logger = new Logger(($absolute ? $storage : $root . '/' . $storage) . '/logs');

        $this->router = new Router();
        $this->modules = new ModuleRegistry($root . '/src/Modules');
        $this->modules->registerRoutes($this->router);
    }

    public function handle(Request $request): Response
    {
        $this->request = $request;
        $this->user = null;
        $this->gate = null;

        try {
            $this->resolveUser();
            $match = $this->router->match($request->method, $request->path);
            if ($match['error'] !== null) {
                return $this->errorPage($match['error']);
            }
            $route = $match['route'];

            if (!$route['public'] && $this->user === null) {
                if ($request->method === 'GET') {
                    $this->session->set('intended', $request->path);
                }

                return $this->redirect('/login');
            }
            if ($request->isPost() && !$this->csrf->validate($request->input('_csrf') ?? $request->header('X-CSRF-Token'))) {
                return $this->errorPage(419);
            }
            if ($route['permission'] !== null && !$this->can($route['permission'])) {
                return $this->errorPage(403);
            }

            [$class, $method] = $route['handler'];

            return (new $class($this))->$method($request, $match['params']);
        } catch (HttpException $e) {
            return $this->errorPage($e->status);
        } catch (Throwable $e) {
            $this->logger->error('Error no controlado en ' . $request->method . ' ' . $request->path, $e);

            return $this->errorPage(500, (bool) $this->config->get('app.debug') ? $e->getMessage() : null);
        }
    }

    public function request(): Request
    {
        return $this->request ?? throw new \LogicException('No hay petición activa.');
    }

    /** @return array<string,mixed>|null */
    public function user(): ?array
    {
        return $this->user;
    }

    public function can(string $permission): bool
    {
        return $this->gate?->can($permission) ?? false;
    }

    /** Contexto del usuario actual para los servicios (permisos incluidos). */
    public function actor(): array
    {
        $user = $this->user ?? throw new \LogicException('No hay usuario autenticado.');

        return [
            'id' => (int) $user['id'],
            'business_id' => (int) $user['business_id'],
            'permissions' => $this->gate?->permissions() ?? [],
            'ip' => $this->request()->ip(),
            'ua' => $this->request()->userAgent(),
        ];
    }

    /** @param array<string,mixed>|null $old @param array<string,mixed>|null $new */
    public function audit(string $action, string $entityType, ?int $entityId = null, ?array $old = null, ?array $new = null): void
    {
        $a = $this->actor();
        $this->auditLogger->log($a['business_id'], $a['id'], $action, $entityType, $entityId, $old, $new, $a['ip'], $a['ua']);
    }

    public function url(string $path = '/'): string
    {
        return ($this->request?->basePath ?? '') . $path;
    }

    public function asset(string $path): string
    {
        $file = $this->root . '/public/assets/' . $path;

        return $this->url('/assets/' . $path) . (is_file($file) ? '?v=' . filemtime($file) : '');
    }

    public function redirect(string $path): Response
    {
        return Response::redirect($this->url($path));
    }

    /** @param array<string,mixed> $data */
    public function renderPage(string $template, array $data = []): Response
    {
        return Response::html($this->page($template, $data));
    }

    /** @param array<string,mixed> $data */
    private function page(string $template, array $data, int $status = 200): string
    {
        $shared = [
            'e'         => static fn (mixed $v): string => View::e($v),
            'url'       => fn (string $p = '/'): string => $this->url($p),
            'asset'     => fn (string $p): string => $this->asset($p),
            'icon'      => static fn (string $n, int $s = 18): string => Icons::svg($n, $s),
            'csrf'      => fn (): string => $this->csrf->field(),
            'can'       => fn (string $p): bool => $this->can($p),
            'money'     => static fn (mixed $v, int $d = 2): string => ($n = Decimal::tryFrom($v, $d)) !== null ? $n->format() : '—',
            'local'     => fn (?string $t, string $f = 'd/m/Y H:i'): string => $this->localTime($t, $f),
            'user'      => $this->user,
            'csrfToken' => $this->csrf->token(),
            'appName'   => (string) $this->config->get('app.name', 'BIC'),
            'menu'      => $this->gate !== null ? $this->modules->menu($this->gate) : [],
            'currentPath' => $this->request?->path ?? '/',
            'flashes'   => $this->session->pullFlashes(),
        ];
        $shared['partial'] = fn (string $t, array $v = []): string => $this->view->render($t, array_merge($shared, $v));

        $content = $this->view->render($template, array_merge($shared, $data));
        $layout = $this->user !== null ? 'core::layout' : 'core::layout_guest';

        return $this->view->render($layout, array_merge($shared, ['content' => $content, 'title' => $data['title'] ?? $shared['appName']]));
    }

    public function errorPage(int $status, ?string $detail = null): Response
    {
        $messages = [
            403 => ['Sin permiso', 'No tienes permiso para realizar esta acción.'],
            404 => ['No encontrado', 'La página que buscas no existe.'],
            405 => ['Método no permitido', 'Esta dirección no admite esa operación.'],
            419 => ['Formulario expirado', 'El formulario caducó o no es válido. Recarga la página e inténtalo de nuevo.'],
            500 => ['Error del sistema', 'Ocurrió un error inesperado. Ya quedó registrado.'],
        ];
        [$heading, $message] = $messages[$status] ?? $messages[500];

        try {
            return Response::html($this->page('core::error', [
                'title' => $heading, 'status' => $status, 'heading' => $heading, 'message' => $message, 'detail' => $detail,
            ]), $status);
        } catch (Throwable) {
            return Response::html('<h1>' . $status . ' · ' . View::e($heading) . '</h1>', $status);
        }
    }

    public function localTime(?string $utc, string $format = 'd/m/Y H:i'): string
    {
        if ($utc === null || $utc === '') {
            return '—';
        }

        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone($this->timezone()))
            ->format($format);
    }

    /** Inicio (00:00 local) de una fecha Y-m-d, más $plusDays, expresado en UTC. */
    public function utcBoundary(string $ymd, int $plusDays = 0): string
    {
        $local = new DateTimeImmutable($ymd . ' 00:00:00', new DateTimeZone($this->timezone()));

        return $local->modify(sprintf('%+d days', $plusDays))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function timezone(): string
    {
        if ($this->timezone === null) {
            $id = $this->user['business_id'] ?? $this->db->value('SELECT MIN(id) FROM businesses');
            $tz = (string) ($this->db->value('SELECT timezone FROM businesses WHERE id = ?', [$id]) ?? 'UTC');
            try {
                new DateTimeZone($tz);
            } catch (Throwable) {
                $tz = 'UTC';
            }
            $this->timezone = $tz;
        }

        return $this->timezone;
    }

    private function resolveUser(): void
    {
        $id = $this->session->get('user_id');
        if (!is_int($id)) {
            return;
        }
        $user = $this->auth->findActiveUser($id);
        if ($user === null) {
            $this->session->invalidate();

            return;
        }

        $now = $this->clock->now()->getTimestamp();
        $timeout = $this->settings->int((int) $user['business_id'], 'session_lifetime_minutes') * 60;
        if ($now - (int) $this->session->get('last_activity', 0) > $timeout) {
            $this->session->invalidate();
            $this->session->flash('warning', 'Tu sesión expiró por inactividad. Inicia sesión de nuevo.');

            return;
        }

        $this->session->set('last_activity', $now);
        $this->user = $user;
        $this->gate = new Gate($this->auth->permissionsFor($id));
        $this->timezone = null;
    }
}
