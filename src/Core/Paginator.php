<?php

declare(strict_types=1);

namespace Bic\Core;

final class Paginator
{
    /** @return array{page:int,pages:int,per_page:int,offset:int,total:int} */
    public static function make(int $total, mixed $page, int $perPage = 25): array
    {
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, (int) $page), $pages);

        return ['page' => $page, 'pages' => $pages, 'per_page' => $perPage, 'offset' => ($page - 1) * $perPage, 'total' => $total];
    }

    /** @param array<string,mixed> $query */
    public static function url(string $path, array $query, int $page): string
    {
        $query = array_filter($query, static fn ($v): bool => $v !== '' && $v !== null);
        $query['page'] = $page;

        return $path . '?' . http_build_query($query);
    }

    /** Escapa comodines LIKE (se usa con ESCAPE '!'). */
    public static function like(string $text): string
    {
        return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text) . '%';
    }
}
