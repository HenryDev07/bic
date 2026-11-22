<?php

declare(strict_types=1);

namespace Bic\Core;

/**
 * Configuración centralizada: config/app.php (defaults) + config/local.php
 * (instalación) + variables de entorno (servidor). Gana la última.
 */
final class Config
{
    /** clave => variable de entorno */
    public const ENV_MAP = [
        'database.host'     => 'BIC_DB_HOST',
        'database.port'     => 'BIC_DB_PORT',
        'database.name'     => 'BIC_DB_NAME',
        'database.user'     => 'BIC_DB_USER',
        'database.password' => 'BIC_DB_PASSWORD',
        'app.env'           => 'BIC_ENV',
        'app.debug'         => 'BIC_DEBUG',
        'app.url'           => 'BIC_URL',
        'app.logo'          => 'BIC_LOGO',
        'app.logo_alt'      => 'BIC_LOGO_ALT',
        'storage.path'      => 'BIC_STORAGE_PATH',
        'ai.provider'       => 'BIC_AI_PROVIDER',
        'ai.api_key'        => 'BIC_AI_API_KEY',
        'ai.model'          => 'BIC_AI_MODEL',
        'ai.base_url'       => 'BIC_AI_BASE_URL',
    ];

    /** @param array<string,mixed> $data */
    public function __construct(private array $data = [])
    {
    }

    public static function load(string $root): self
    {
        $app = require $root . '/config/app.php';
        $localFile = $root . '/config/local.php';
        $local = is_file($localFile) ? require $localFile : [];

        $config = new self(array_replace_recursive($app, $local));
        $env = getenv();
        $config->applyEnv(is_array($env) ? $env : []);

        return $config;
    }

    /** @param array<string,string> $env */
    public function applyEnv(array $env): void
    {
        foreach (self::ENV_MAP as $key => $var) {
            if (isset($env[$var]) && $env[$var] !== '') {
                $this->set($key, $env[$var]);
            }
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $node = $this->data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }

        return $node;
    }

    public function set(string $key, mixed $value): void
    {
        $node = &$this->data;
        foreach (explode('.', $key) as $part) {
            if (!isset($node[$part]) || !is_array($node[$part])) {
                $node[$part] = [];
            }
            $node = &$node[$part];
        }
        $node = $value;
    }

    /** Escribe config/local.php con permisos restringidos. */
    public static function writeLocal(string $file, array $data): void
    {
        $php = "<?php\n\n// Generado por bin/install.php. NO versionar. No contiene la contraseña de root.\nreturn "
            . var_export($data, true) . ";\n";
        file_put_contents($file, $php, LOCK_EX);
        @chmod($file, 0600);
    }
}
