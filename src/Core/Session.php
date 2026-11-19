<?php

declare(strict_types=1);

namespace Bic\Core;

/**
 * Envoltorio de sesión. En producción usa $_SESSION nativo; en pruebas un array.
 */
final class Session
{
    /** @var array<string,mixed> */
    private array $data;

    /** @param array<string,mixed> $data */
    public function __construct(array &$data, private readonly bool $native = false)
    {
        $this->data = &$data;
    }

    public static function native(bool $secure): self
    {
        session_name('bic_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_start();

        return new self($_SESSION, true);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($this->data[$key]);
    }

    /** Nuevo ID de sesión (evita fijación de sesión al iniciar sesión). */
    public function regenerate(): void
    {
        if ($this->native && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /** Cierra la sesión del usuario y deja una sesión limpia. */
    public function invalidate(): void
    {
        $this->data = [];
        $this->regenerate();
    }

    public function flash(string $type, string $message): void
    {
        $this->data['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return list<array{type:string,message:string}> */
    public function pullFlashes(): array
    {
        $flashes = $this->data['_flash'] ?? [];
        unset($this->data['_flash']);

        return $flashes;
    }
}
