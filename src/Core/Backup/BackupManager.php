<?php

declare(strict_types=1);

namespace Bic\Core\Backup;

use RuntimeException;

/** Crea, verifica, lista y depura respaldos comprimidos (.sql.gz) con suma SHA-256. */
final class BackupManager
{
    public function __construct(private readonly string $dir)
    {
    }

    public function create(DumpSource $src, string $database, \DateTimeImmutable $nowUtc): array
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0750, true) && !is_dir($this->dir)) {
            throw new RuntimeException('No se pudo crear la carpeta de respaldos: ' . $this->dir);
        }
        if (!function_exists('gzopen')) {
            throw new RuntimeException('Falta la extensión zlib de PHP.');
        }
        $name = 'bic_' . preg_replace('/[^A-Za-z0-9_]/', '_', $database) . '_' . $nowUtc->format('Ymd_His') . '.sql.gz';
        $path = $this->dir . DIRECTORY_SEPARATOR . $name;
        $tmp = $path . '.part';
        $h = gzopen($tmp, 'wb6');
        if ($h === false) {
            throw new RuntimeException('No se pudo crear el archivo de respaldo.');
        }
        try {
            $rows = SqlDumper::write($src, $h, $database, $nowUtc->format('Y-m-d H:i:s'));
        } catch (\Throwable $e) {
            gzclose($h);
            @unlink($tmp);
            throw $e;
        }
        gzclose($h);
        rename($tmp, $path);
        $sum = hash_file('sha256', $path);
        file_put_contents($path . '.sha256', $sum . '  ' . $name . "\n");
        $this->verify($path); // un respaldo que no se puede leer de vuelta no es un respaldo

        return ['path' => $path, 'name' => $name, 'rows' => $rows, 'bytes' => (int) filesize($path), 'sha256' => $sum];
    }

    /** Comprueba la suma SHA-256 y que el contenido se lea completo y termine con la marca de «completo». @throws RuntimeException */
    public function verify(string $path): void
    {
        if (!is_file($path)) {
            throw new RuntimeException('No existe el archivo: ' . $path);
        }
        $sumFile = $path . '.sha256';
        if (is_file($sumFile)) {
            $expected = strtok((string) file_get_contents($sumFile), ' ');
            if (!hash_equals((string) $expected, hash_file('sha256', $path))) {
                throw new RuntimeException('La suma SHA-256 no coincide: el respaldo está dañado o fue modificado.');
            }
        }
        $h = gzopen($path, 'rb');
        if ($h === false) {
            throw new RuntimeException('No se pudo abrir el respaldo.');
        }
        try {
            foreach (SqlDumper::statements($h) as $_) {
                // recorrer todo valida la estructura completa
            }
        } finally {
            gzclose($h);
        }
    }

    /** @return list<array{name:string,bytes:int,modified:int}> más recientes primero */
    public function list(): array
    {
        $out = [];
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . 'bic_*.sql.gz') ?: [] as $f) {
            $out[] = ['name' => basename($f), 'bytes' => (int) filesize($f), 'modified' => (int) filemtime($f)];
        }
        usort($out, static fn (array $a, array $b): int => [$b['modified'], $b['name']] <=> [$a['modified'], $a['name']]);

        return $out;
    }

    /** Conserva los $keep más recientes y borra el resto (con su .sha256). @return list<string> borrados */
    public function prune(int $keep): array
    {
        $deleted = [];
        foreach (array_slice($this->list(), max(1, $keep)) as $f) {
            $p = $this->dir . DIRECTORY_SEPARATOR . $f['name'];
            @unlink($p);
            @unlink($p . '.sha256');
            $deleted[] = $f['name'];
        }

        return $deleted;
    }
}
