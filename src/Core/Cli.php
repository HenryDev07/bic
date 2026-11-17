<?php

declare(strict_types=1);

namespace Bic\Core;

/** Utilidades mínimas para los scripts de bin/. */
final class Cli
{
    public static function line(string $message = ''): void
    {
        fwrite(STDOUT, $message . PHP_EOL);
    }

    public static function ok(string $message): void
    {
        self::line('  [OK] ' . $message);
    }

    public static function fail(string $message, int $code = 1): never
    {
        fwrite(STDERR, '  [ERROR] ' . $message . PHP_EOL);
        exit($code);
    }

    public static function prompt(string $question, bool $hidden = false): string
    {
        fwrite(STDOUT, $question);
        $useStty = $hidden && DIRECTORY_SEPARATOR === '/';
        if ($useStty) {
            shell_exec('stty -echo');
        }
        $answer = fgets(STDIN);
        if ($useStty) {
            shell_exec('stty echo');
            fwrite(STDOUT, PHP_EOL);
        }

        return $answer === false ? '' : rtrim($answer, "\r\n");
    }

    /**
     * Credenciales de administrador (solo CLI). Nunca se escriben en disco.
     * Variables: BIC_DB_ADMIN_USER (por defecto root) y BIC_DB_ADMIN_PASSWORD.
     *
     * @return array{0:string,1:string}
     */
    public static function adminCredentials(): array
    {
        $user = getenv('BIC_DB_ADMIN_USER') ?: 'root';
        $password = getenv('BIC_DB_ADMIN_PASSWORD');
        if ($password === false) {
            self::line("Se necesita el administrador de MySQL (usuario '{$user}') solo para esta operación; no se guarda.");
            $password = self::prompt('Contraseña del administrador: ', true);
        }

        return [$user, $password];
    }
}
