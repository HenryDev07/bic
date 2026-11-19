<?php

declare(strict_types=1);

namespace Bic\Core;

use Throwable;

/** Registro de errores en storage/logs (nunca interrumpe la petición). */
final class Logger
{
    public function __construct(private readonly string $dir)
    {
    }

    public function error(string $message, ?Throwable $e = null): void
    {
        $line = sprintf("[%s] %s%s\n", gmdate('Y-m-d H:i:s'), $message, $e ? ' | ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() : '');
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
        @file_put_contents($this->dir . '/app-' . gmdate('Y-m') . '.log', $line, FILE_APPEND | LOCK_EX);
    }
}
