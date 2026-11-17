<?php

declare(strict_types=1);

namespace Bic\Core\Database;

/**
 * Divide un script SQL en sentencias respetando cadenas, identificadores
 * entrecomillados y comentarios (-- y /* *\/). No soporta DELIMITER ni
 * rutinas almacenadas: las migraciones de BIC no los usan.
 */
final class SqlSplitter
{
    /** @return list<string> */
    public static function split(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $quote = null;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $c = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($quote !== null) {
                $buffer .= $c;
                if ($c === '\\' && $quote !== '`') {
                    $buffer .= $next;
                    $i++;
                } elseif ($c === $quote) {
                    if ($next === $quote) {
                        $buffer .= $next;
                        $i++;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }

            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
                $buffer .= $c;
                continue;
            }

            if ($c === '-' && $next === '-' && (($sql[$i + 2] ?? "\n") === ' ' || ($sql[$i + 2] ?? "\n") === "\n" || ($sql[$i + 2] ?? '') === "\t")) {
                while ($i < $length && $sql[$i] !== "\n") {
                    $i++;
                }
                $buffer .= "\n";
                continue;
            }

            if ($c === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 1;
                continue;
            }

            if ($c === ';') {
                $statement = trim($buffer);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $c;
        }

        $tail = trim($buffer);
        if ($tail !== '') {
            $statements[] = $tail;
        }

        return $statements;
    }
}
