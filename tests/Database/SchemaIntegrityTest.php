<?php

declare(strict_types=1);

namespace Bic\Tests\Database;

use Bic\Core\Database\Migrator;
use Bic\Core\Database\SchemaBuilder;
use Bic\Core\Database\SchemaLinter;
use PHPUnit\Framework\TestCase;

final class SchemaIntegrityTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testMigrationsPassLinter(): void
    {
        $result = (new SchemaLinter())->lint($this->root . '/database/migrations', $this->root . '/database/seeds');
        $this->assertSame([], $result['errors'], implode("\n", $result['errors']));
        $this->assertGreaterThan(0, $result['tables']);
    }

    public function testSchemaSqlIsInSyncWithMigrations(): void
    {
        $builder = new SchemaBuilder($this->root . '/database/migrations');
        $this->assertTrue(
            $builder->inSync($this->root . '/database/schema.sql'),
            'schema.sql desincronizado: ejecuta php bin/schema.php build'
        );
    }

    public function testMigrationVersionsAreSequential(): void
    {
        $versions = array_keys(Migrator::listFiles($this->root . '/database/migrations'));
        foreach ($versions as $i => $version) {
            $this->assertSame(sprintf('%04d', $i + 1), substr($version, 0, 4), 'numeración sin saltos ni duplicados');
        }
    }

    public function testChecksumIgnoresLineEndings(): void
    {
        $this->assertSame(Migrator::checksum("a\nb\n"), Migrator::checksum("a\r\nb\r\n"));
    }

    public function testLinterRejectsBadSchema(): void
    {
        $dir = sys_get_temp_dir() . '/bic_lint_' . bin2hex(random_bytes(4));
        mkdir($dir . '/m', 0777, true);
        mkdir($dir . '/s', 0777, true);
        file_put_contents($dir . '/m/0001_bad.sql', <<<'SQL'
CREATE TABLE IF NOT EXISTS parents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS kids (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  parent_id INT NOT NULL,
  total_amount DOUBLE NOT NULL,
  PRIMARY KEY (id),
  FOREIGN KEY (parent_id) REFERENCES parents (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL);
        $result = (new SchemaLinter())->lint($dir . '/m', $dir . '/s');
        $text = implode("\n", $result['errors']);
        $this->assertStringContainsString('DOUBLE', $text);
        $this->assertStringContainsString('no coincide', $text);

        unlink($dir . '/m/0001_bad.sql');
        rmdir($dir . '/m');
        rmdir($dir . '/s');
        rmdir($dir);
    }
}
