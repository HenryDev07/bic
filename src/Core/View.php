<?php

declare(strict_types=1);

namespace Bic\Core;

use InvalidArgumentException;
use Throwable;

/**
 * Plantillas PHP. Nombre "modulo::ruta": core::layout → src/Core/Views/layout.php,
 * users::index → src/Modules/Users/Views/index.php.
 * Las plantillas escapan con $e(...) (htmlspecialchars); nunca imprimir datos sin $e.
 */
final class View
{
    public function __construct(private readonly string $root)
    {
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param array<string,mixed> $vars */
    public function render(string $template, array $vars = []): string
    {
        if (!preg_match('#^([a-z]+)::([a-z_/]+)$#', $template, $m)) {
            throw new InvalidArgumentException("Nombre de plantilla inválido: {$template}");
        }
        $dir = $m[1] === 'core' ? 'src/Core/Views' : 'src/Modules/' . ucfirst($m[1]) . '/Views';
        $path = $this->root . '/' . $dir . '/' . $m[2] . '.php';
        if (!is_file($path)) {
            throw new InvalidArgumentException("No existe la plantilla {$template}");
        }

        $render = static function (string $__path, array $__vars): string {
            extract($__vars, EXTR_SKIP);
            ob_start();
            try {
                require $__path;

                return (string) ob_get_clean();
            } catch (Throwable $e) {
                ob_end_clean();
                throw $e;
            }
        };

        return $render($path, $vars);
    }
}
