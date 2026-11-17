<?php

declare(strict_types=1);

namespace Bic\Tests\Core;

use Bic\Core\Database\SqlSplitter;
use PHPUnit\Framework\TestCase;

final class SqlSplitterTest extends TestCase
{
    public function testSplitsSimpleStatements(): void
    {
        $this->assertSame(['SELECT 1', 'SELECT 2'], SqlSplitter::split("SELECT 1;\nSELECT 2;"));
    }

    public function testSemicolonInsideStringDoesNotSplit(): void
    {
        $parts = SqlSplitter::split("INSERT INTO t (a) VALUES ('uno; dos'); SELECT 1");
        $this->assertCount(2, $parts);
        $this->assertSame("INSERT INTO t (a) VALUES ('uno; dos')", $parts[0]);
    }

    public function testEscapedAndDoubledQuotes(): void
    {
        $parts = SqlSplitter::split("SELECT 'it''s; ok', 'a\\'b;c'; SELECT 2");
        $this->assertCount(2, $parts);
    }

    public function testCommentsAreIgnored(): void
    {
        $sql = "-- comentario con 'comilla y ; punto y coma\nSELECT 1; /* otro; comentario */ SELECT 2";
        $this->assertSame(['SELECT 1', 'SELECT 2'], SqlSplitter::split($sql));
    }

    public function testBacktickIdentifiers(): void
    {
        $parts = SqlSplitter::split('SELECT `a;b` FROM t; SELECT 2');
        $this->assertCount(2, $parts);
    }

    public function testEmptyInput(): void
    {
        $this->assertSame([], SqlSplitter::split("  \n -- nada \n"));
    }
}
