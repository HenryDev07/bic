<?php

declare(strict_types=1);

namespace Bic\Core\Export;

/**
 * Tabla neutral que alimenta la pantalla, el CSV, el Excel y el PDF con los mismos datos.
 * Tipos de columna: text, money (2 decimales), qty (3), pct (2), int.
 */
final class Table
{
    public const TYPES = ['text', 'money', 'qty', 'pct', 'int'];

    /**
     * @param list<array{key:string,label:string,type?:string}> $columns
     * @param list<array<string,mixed>> $rows
     * @param array<string,mixed>|null $totals fila de totales (mismas claves)
     */
    public function __construct(
        public readonly string $title,
        public readonly string $subtitle,
        public readonly array $columns,
        public readonly array $rows,
        public readonly ?array $totals = null,
    ) {
    }

    public function type(array $col): string
    {
        $t = $col['type'] ?? 'text';

        return in_array($t, self::TYPES, true) ? $t : 'text';
    }

    public static function isNumeric(string $type): bool
    {
        return $type !== 'text';
    }

    /** Texto legible de una celda (con separadores de miles) para pantalla y PDF. */
    public function display(array $col, mixed $value): string
    {
        $type = $this->type($col);
        if ($value === null || $value === '') {
            return '';
        }
        if (!self::isNumeric($type)) {
            return (string) $value;
        }
        $scale = match ($type) {
            'qty' => 3,
            'int' => 0,
            default => 2,
        };

        return \Bic\Core\Money\Decimal::from($value, $scale)->format();
    }

    /** @return list<array<string,mixed>> filas + totales si existen */
    public function allRows(): array
    {
        return $this->totals === null ? $this->rows : array_merge($this->rows, [$this->totals]);
    }
}
