<?php

declare(strict_types=1);

namespace Bic\Core\Database;

/**
 * Verificación estática del esquema (no necesita servidor MySQL).
 * Detecta los errores que de otro modo aparecen al migrar: FK hacia tablas
 * inexistentes o definidas después, tipos de FK incompatibles, FLOAT/DOUBLE,
 * importes que no son DECIMAL, tablas sin PK, sin business_id, etc.
 * También valida que los INSERT de los seeds usen tablas y columnas reales.
 */
final class SchemaLinter
{
    /** Tablas globales: no pertenecen a un negocio. */
    private const NO_BUSINESS_ID = ['businesses', 'roles', 'permissions', 'role_permissions', 'user_roles', 'login_attempts'];

    /** Columnas *_id polimórficas o de referencia débil (sin FK por diseño). */
    private const WEAK_REFERENCES = ['entity_id', 'result_id', 'tax_id'];

    /**
     * @return array{errors:list<string>,warnings:list<string>,tables:int}
     */
    public function lint(string $migrationsDir, string $seedsDir): array
    {
        $errors = [];
        $warnings = [];
        /** @var array<string,array{cols:array<string,string>,pk:bool,fks:list<array>,idx:list<array>}> $tables */
        $tables = [];

        foreach (Migrator::listFiles($migrationsDir) as $version => $path) {
            $sql = Migrator::normalize((string) file_get_contents($path));
            foreach (SqlSplitter::split($sql) as $statement) {
                if (preg_match('/^CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?(\w+)`?\s*\((.*)\)\s*(ENGINE\s*=.*)$/is', $statement, $m)) {
                    $this->parseTable($version, $m[1], $m[2], $m[3], $tables, $errors, $warnings);
                } elseif (preg_match('/^CREATE\s+TABLE/i', $statement)) {
                    $errors[] = "{$version}: usa CREATE TABLE IF NOT EXISTS con ENGINE=InnoDB.";
                } elseif (preg_match('/^ALTER\s+TABLE/i', $statement)) {
                    $warnings[] = "{$version}: ALTER TABLE no es analizado por el linter (amplíalo al usarlo).";
                }
            }
        }

        $this->checkForeignKeys($tables, $errors);
        $this->checkSeeds($seedsDir, $tables, $errors);

        return ['errors' => $errors, 'warnings' => $warnings, 'tables' => count($tables)];
    }

    private function parseTable(string $version, string $name, string $body, string $options, array &$tables, array &$errors, array &$warnings): void
    {
        if (isset($tables[$name])) {
            $errors[] = "{$version}: la tabla {$name} está definida más de una vez.";
        }
        $table = ['cols' => [], 'pk' => false, 'fks' => [], 'idx' => []];

        if (!preg_match('/ENGINE\s*=\s*InnoDB/i', $options)) {
            $errors[] = "{$name}: debe usar ENGINE=InnoDB.";
        }
        if (!preg_match('/CHARSET\s*=\s*utf8mb4/i', $options)) {
            $errors[] = "{$name}: debe usar CHARSET=utf8mb4.";
        }

        foreach ($this->splitTop($body) as $item) {
            if (preg_match('/^PRIMARY\s+KEY\s*\(([^)]+)\)/i', $item)) {
                $table['pk'] = true;
            } elseif (preg_match('/^(?:UNIQUE\s+)?(?:KEY|INDEX)\s+`?\w+`?\s*\(([^)]+)\)/i', $item, $m)) {
                $table['idx'][] = $this->cols($m[1]);
            } elseif (preg_match('/^(?:CONSTRAINT\s+`?\w+`?\s+)?FOREIGN\s+KEY\s*\(([^)]+)\)\s*REFERENCES\s+`?(\w+)`?\s*\(([^)]+)\)/i', $item, $m)) {
                $table['fks'][] = ['cols' => $this->cols($m[1]), 'ref' => $m[2], 'refcols' => $this->cols($m[3])];
            } elseif (preg_match('/^(?:CONSTRAINT\s+`?\w+`?\s+)?CHECK\b/i', $item)) {
                continue;
            } elseif (preg_match('/^`?(\w+)`?\s+(\w+(?:\([^)]*\))?(?:\s+UNSIGNED)?)/i', $item, $m)) {
                $type = strtoupper(preg_replace('/\s+/', ' ', $m[2]));
                $table['cols'][$m[1]] = $type;
                if (preg_match('/^(FLOAT|DOUBLE|REAL)\b/', $type)) {
                    $errors[] = "{$name}.{$m[1]}: prohibido {$type}; usa DECIMAL.";
                }
                if (preg_match('/(amount|price|cost|total|balance|rate)$/', $m[1]) && !str_starts_with($type, 'DECIMAL')) {
                    $errors[] = "{$name}.{$m[1]}: los importes y tasas deben ser DECIMAL (es {$type}).";
                }
            } else {
                $errors[] = "{$name}: no se pudo interpretar la definición «{$item}».";
            }
        }

        if (!$table['pk']) {
            $errors[] = "{$name}: falta PRIMARY KEY.";
        }
        if (!in_array($name, self::NO_BUSINESS_ID, true) && !isset($table['cols']['business_id'])) {
            $errors[] = "{$name}: falta business_id (preparación multi-negocio).";
        }
        foreach ($table['idx'] as $cols) {
            foreach ($cols as $c) {
                if (!isset($table['cols'][$c])) {
                    $errors[] = "{$name}: el índice usa la columna inexistente {$c}.";
                }
            }
        }

        $fkCols = array_merge(...array_map(static fn (array $f): array => $f['cols'], $table['fks'] ?: [['cols' => []]]));
        foreach (array_keys($table['cols']) as $col) {
            if (str_ends_with($col, '_id') && $col !== 'id' && !in_array($col, $fkCols, true) && !in_array($col, self::WEAK_REFERENCES, true)) {
                $warnings[] = "{$name}.{$col}: columna *_id sin FOREIGN KEY.";
            }
        }

        $tables[$name] = $table;
    }

    private function checkForeignKeys(array $tables, array &$errors): void
    {
        $seen = [];
        foreach ($tables as $name => $table) {
            $seen[$name] = true;
            foreach ($table['fks'] as $fk) {
                $ref = $fk['ref'];
                if (!isset($tables[$ref])) {
                    $errors[] = "{$name}: FK hacia la tabla inexistente {$ref}.";
                    continue;
                }
                if (!isset($seen[$ref])) {
                    $errors[] = "{$name}: FK hacia {$ref}, que se crea después (orden de migraciones).";
                }
                if (count($fk['cols']) !== count($fk['refcols'])) {
                    $errors[] = "{$name}: FK con distinto número de columnas hacia {$ref}.";
                    continue;
                }
                foreach ($fk['cols'] as $i => $col) {
                    $refCol = $fk['refcols'][$i];
                    $a = $table['cols'][$col] ?? null;
                    $b = $tables[$ref]['cols'][$refCol] ?? null;
                    if ($a === null || $b === null) {
                        $errors[] = "{$name}.{$col} → {$ref}.{$refCol}: columna inexistente.";
                    } elseif ($a !== $b) {
                        $errors[] = "{$name}.{$col} ({$a}) no coincide con {$ref}.{$refCol} ({$b}).";
                    }
                }
            }
        }
    }

    private function checkSeeds(string $seedsDir, array $tables, array &$errors): void
    {
        $files = glob(rtrim($seedsDir, '/\\') . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $path) {
            foreach (SqlSplitter::split((string) file_get_contents($path)) as $statement) {
                if (!preg_match('/^INSERT\s+(?:IGNORE\s+)?INTO\s+`?(\w+)`?\s*\(([^)]*)\)/is', $statement, $m)) {
                    continue;
                }
                $file = basename($path);
                if (!isset($tables[$m[1]])) {
                    $errors[] = "seed {$file}: la tabla {$m[1]} no existe en las migraciones.";
                    continue;
                }
                foreach ($this->cols($m[2]) as $col) {
                    if (!isset($tables[$m[1]]['cols'][$col])) {
                        $errors[] = "seed {$file}: {$m[1]}.{$col} no existe.";
                    }
                }
            }
        }
    }

    /** @return list<string> */
    private function cols(string $list): array
    {
        return array_map(
            static fn (string $c): string => trim(preg_replace('/\(\d+\)/', '', trim($c)), " `"),
            explode(',', $list)
        );
    }

    /** @return list<string> */
    private function splitTop(string $body): array
    {
        $parts = [];
        $buffer = '';
        $depth = 0;
        $quote = null;
        for ($i = 0, $n = strlen($body); $i < $n; $i++) {
            $c = $body[$i];
            if ($quote !== null) {
                $buffer .= $c;
                if ($c === '\\') {
                    $buffer .= $body[++$i] ?? '';
                } elseif ($c === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($c === "'" || $c === '`' || $c === '"') {
                $quote = $c;
            } elseif ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
            } elseif ($c === ',' && $depth === 0) {
                $parts[] = trim($buffer);
                $buffer = '';
                continue;
            }
            $buffer .= $c;
        }
        if (trim($buffer) !== '') {
            $parts[] = trim($buffer);
        }

        return $parts;
    }
}
