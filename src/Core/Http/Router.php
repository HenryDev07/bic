<?php

declare(strict_types=1);

namespace Bic\Core\Http;

/**
 * Rutas con parámetros {nombre}. {id} solo acepta dígitos.
 * Cada ruta declara el permiso que exige (null = solo sesión iniciada)
 * o si es pública (login).
 */
final class Router
{
    /** @var list<array{method:string,regex:string,handler:array{0:class-string,1:string},permission:?string,public:bool}> */
    private array $routes = [];

    /** @param array{0:class-string,1:string} $handler */
    public function get(string $path, array $handler, ?string $permission = null, bool $public = false): void
    {
        $this->add('GET', $path, $handler, $permission, $public);
    }

    /** @param array{0:class-string,1:string} $handler */
    public function post(string $path, array $handler, ?string $permission = null, bool $public = false): void
    {
        $this->add('POST', $path, $handler, $permission, $public);
    }

    /** @param array{0:class-string,1:string} $handler */
    private function add(string $method, string $path, array $handler, ?string $permission, bool $public): void
    {
        $regex = preg_replace_callback(
            '/\\\{(\w+)\\\}/',
            static fn (array $m): string => $m[1] === 'id' ? '(?P<id>\d+)' : '(?P<' . $m[1] . '>[^/]+)',
            preg_quote($path, '#')
        );
        $this->routes[] = [
            'method' => $method, 'regex' => '#^' . $regex . '$#', 'handler' => $handler,
            'permission' => $permission, 'public' => $public,
        ];
    }

    /**
     * @return array{route:?array,params:array<string,string>,error:?int} error: 404 | 405 | null
     */
    public function match(string $method, string $path): array
    {
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] === $method) {
                $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);

                return ['route' => $route, 'params' => $params, 'error' => null];
            }
        }

        return ['route' => null, 'params' => [], 'error' => $pathMatched ? 405 : 404];
    }
}
