<?php

declare(strict_types=1);

namespace Bic\Core\Backup;

/** Origen de datos de un respaldo (MariaDB en producción, un arreglo en las pruebas). */
interface DumpSource
{
    /** @return list<string> */
    public function tables(): array;

    public function createSql(string $table): string;

    /** @return list<array{name:string,numeric:bool}> */
    public function columns(string $table): array;

    /** @return iterable<list<array<string,mixed>>> bloques de filas */
    public function rows(string $table, int $chunk): iterable;

    public function quote(string $value): string;
}
