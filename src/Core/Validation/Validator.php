<?php

declare(strict_types=1);

namespace Bic\Core\Validation;

/**
 * Validación de entradas en servidor. Reglas separadas por |:
 * required, email, min:n, max:n (longitud), int, between:a,b, in:a,b,c, same:campo.
 * Un campo vacío y no obligatorio se omite.
 */
final class Validator
{
    /**
     * @param array<string,mixed>  $data
     * @param array<string,string> $rules
     * @param array<string,string> $labels
     * @return array<string,string> campo => mensaje (vacío si todo es válido)
     */
    public static function validate(array $data, array $rules, array $labels = []): array
    {
        $errors = [];
        foreach ($rules as $field => $ruleString) {
            $label = $labels[$field] ?? $field;
            $value = $data[$field] ?? null;
            $value = is_string($value) ? trim($value) : $value;
            $list = explode('|', $ruleString);

            if ($value === null || $value === '') {
                if (in_array('required', $list, true)) {
                    $errors[$field] = "El campo «{$label}» es obligatorio.";
                }
                continue;
            }
            if (!is_scalar($value)) {
                $errors[$field] = "El campo «{$label}» no es válido.";
                continue;
            }
            $value = (string) $value;

            foreach ($list as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $message = match ($name) {
                    'email'   => filter_var($value, FILTER_VALIDATE_EMAIL) ? null : "«{$label}» no es un correo válido.",
                    'min'     => mb_strlen($value) >= (int) $arg ? null : "«{$label}» debe tener al menos {$arg} caracteres.",
                    'max'     => mb_strlen($value) <= (int) $arg ? null : "«{$label}» no puede superar {$arg} caracteres.",
                    'int'     => preg_match('/^-?\d+$/', $value) ? null : "«{$label}» debe ser un número entero.",
                    'between' => self::between($value, (string) $arg, $label),
                    'in'      => in_array($value, explode(',', (string) $arg), true) ? null : "«{$label}» tiene un valor no permitido.",
                    'same'    => $value === (string) ($data[(string) $arg] ?? '') ? null : "«{$label}» no coincide.",
                    default   => null,
                };
                if ($message !== null) {
                    $errors[$field] = $message;
                    break;
                }
            }
        }

        return $errors;
    }

    private static function between(string $value, string $arg, string $label): ?string
    {
        [$min, $max] = array_map('intval', explode(',', $arg));
        if (!preg_match('/^-?\d+$/', $value) || (int) $value < $min || (int) $value > $max) {
            return "«{$label}» debe estar entre {$min} y {$max}.";
        }

        return null;
    }
}
