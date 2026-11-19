<?php

declare(strict_types=1);

namespace Bic\Core\Auth;

final class PasswordPolicy
{
    /** @return string|null mensaje de error, o null si la contraseña es válida */
    public static function check(string $password, string $email, int $minLength): ?string
    {
        if (mb_strlen($password) < $minLength) {
            return "La contraseña debe tener al menos {$minLength} caracteres.";
        }
        if (strlen($password) > 72) {
            return 'La contraseña no puede superar 72 bytes.';
        }
        if (!preg_match('/\p{L}/u', $password) || !preg_match('/\d/', $password)) {
            return 'La contraseña debe incluir al menos una letra y un número.';
        }
        if (mb_strtolower($password) === mb_strtolower($email)) {
            return 'La contraseña no puede ser igual al correo.';
        }

        return null;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}
