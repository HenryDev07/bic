<?php

declare(strict_types=1);

namespace Bic\Core;

use Bic\Core\Http\HttpException;
use Bic\Core\Http\Request;
use Bic\Core\Http\Response;

/** Base de los controladores: solo orquesta; la lógica de negocio vive en servicios. */
abstract class Controller
{
    public function __construct(protected readonly App $app)
    {
    }

    /** @param array<string,mixed> $data */
    protected function render(string $template, array $data = [], int $status = 200): Response
    {
        $response = $this->app->renderPage($template, $data);
        $response->status = $status;

        return $response;
    }

    protected function redirect(string $path): Response
    {
        return $this->app->redirect($path);
    }

    protected function flash(string $type, string $message): void
    {
        $this->app->session->flash($type, $message);
    }

    protected function forbid(): never
    {
        throw new HttpException(403);
    }

    protected function notFound(): never
    {
        throw new HttpException(404);
    }

    protected function id(array $params): int
    {
        return (int) ($params['id'] ?? 0);
    }

    protected function page(Request $request): int
    {
        return max(1, (int) ($request->query['page'] ?? 1));
    }
}
