<?php

declare(strict_types=1);

namespace Bic\Tests\Core;

use Bic\Core\Backup\BackupManager;
use Bic\Core\Backup\DumpSource;
use Bic\Core\Backup\SqlDumper;
use PHPUnit\Framework\TestCase;

final class ArraySource implements DumpSource
{
    /** @param array<string,list<array<string,mixed>>> $data */
    public function __construct(private array $data)
    {
    }

    public function tables(): array
    {
        return array_keys($this->data);
    }

    public function createSql(string $table): string
    {
        return "CREATE TABLE `$table` (id BIGINT UNSIGNED NOT NULL, note TEXT NULL, PRIMARY KEY (id))";
    }

    public function columns(string $table): array
    {
        return [['name' => 'id', 'numeric' => true], ['name' => 'note', 'numeric' => false]];
    }

    public function rows(string $table, int $chunk): iterable
    {
        yield from array_chunk($this->data[$table], max(1, $chunk));
    }

    public function quote(string $value): string
    {
        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
    }
}

final class BackupTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/bic_bk_' . getmypid() . '_' . bin2hex(random_bytes(3));
        mkdir($this->dir, 0750, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function source(): ArraySource
    {
        $rows = [];
        for ($i = 1; $i <= 450; $i++) {
            $rows[] = ['id' => $i, 'note' => $i === 7 ? "línea\n/*fake*/\n-- BIC-DUMP-COMPLETE rows=0\n'comilla'" : null];
        }

        return new ArraySource(['a' => $rows, 'b' => [['id' => 1, 'note' => 'x;y']]]);
    }

    private function dump(): string
    {
        $h = fopen('php://memory', 'w+b');
        $n = SqlDumper::write($this->source(), $h, 'bic', '2026-01-01 00:00:00');
        $this->assertSame(451, $n);
        rewind($h);

        return (string) stream_get_contents($h);
    }

    private function read(string $text): array
    {
        $h = fopen('php://memory', 'w+b');
        fwrite($h, $text);
        rewind($h);

        return iterator_to_array(SqlDumper::statements($h), false);
    }

    public function testRoundTripKeepsHostileDataInsideStatements(): void
    {
        $stmts = $this->read($this->dump());
        $all = implode("\n", $stmts);
        $this->assertStringContainsString("/*fake*/", $all);
        $this->assertStringContainsString('BIC-DUMP-COMPLETE rows=0', $all);
        $inserts = array_filter($stmts, static fn ($s) => str_starts_with($s, 'INSERT'));
        $this->assertSame(4, count($inserts), '3 lotes de 200/200/50 en a y 1 en b');
    }

    public function testTruncatedDumpIsRejected(): void
    {
        $text = $this->dump();
        $cut = substr($text, 0, (int) (strlen($text) * 0.6));
        $this->expectException(\RuntimeException::class);
        $this->read($cut);
    }

    public function testBadHeaderIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->read("SELECT 1;\n");
    }

    public function testBackupChecksumAndTamperDetection(): void
    {
        if (!function_exists('gzopen')) {
            $this->markTestSkipped('zlib no disponible');
        }
        $m = new BackupManager($this->dir);
        $r = $m->create($this->source(), 'bic', new \DateTimeImmutable('2026-01-01 10:00:00'));
        $this->assertSame(451, $r['rows']);
        $m->verify($r['path']);
        $this->assertCount(1, $m->list());

        file_put_contents($r['path'], 'corrupto', FILE_APPEND);
        $this->expectException(\RuntimeException::class);
        $m->verify($r['path']);
    }

    public function testPruneKeepsNewest(): void
    {
        if (!function_exists('gzopen')) {
            $this->markTestSkipped('zlib no disponible');
        }
        $m = new BackupManager($this->dir);
        foreach (['10', '11', '12', '13'] as $h) {
            $r = $m->create($this->source(), 'bic', new \DateTimeImmutable("2026-01-01 $h:00:00"));
            touch($r['path'], strtotime("2026-01-01 $h:00:00"));
        }
        $deleted = $m->prune(2);
        $this->assertCount(2, $deleted);
        $names = array_column($m->list(), 'name');
        $this->assertSame(['bic_bic_20260101_130000.sql.gz', 'bic_bic_20260101_120000.sql.gz'], $names);
        $this->assertFalse(is_file($this->dir . '/' . $deleted[0] . '.sha256'));
    }
}
