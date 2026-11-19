<?php

declare(strict_types=1);

namespace Bic\Core\Security;

use Bic\Core\Session;

/** Token CSRF por sesión, comparado en tiempo constante. */
final class Csrf
{
    private const KEY = '_csrf';

    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::KEY);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::KEY, $token);
        }

        return $token;
    }

    public function validate(mixed $submitted): bool
    {
        $stored = $this->session->get(self::KEY);

        return is_string($submitted) && is_string($stored) && $stored !== '' && hash_equals($stored, $submitted);
    }

    public function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars($this->token(), ENT_QUOTES, 'UTF-8') . '">';
    }
}
