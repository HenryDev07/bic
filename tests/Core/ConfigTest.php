<?php

declare(strict_types=1);

namespace Bic\Tests\Core;

use Bic\Core\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testDottedGetWithDefault(): void
    {
        $config = new Config(['database' => ['host' => 'localhost']]);
        $this->assertSame('localhost', $config->get('database.host'));
        $this->assertSame('x', $config->get('database.nope', 'x'));
        $this->assertNull($config->get('nada.nada'));
    }

    public function testSetCreatesNestedKeys(): void
    {
        $config = new Config();
        $config->set('a.b.c', 5);
        $this->assertSame(5, $config->get('a.b.c'));
    }

    public function testEnvironmentOverridesFiles(): void
    {
        $config = new Config(['database' => ['host' => 'localhost', 'password' => 'archivo']]);
        $config->applyEnv(['BIC_DB_HOST' => 'db.servidor.com', 'BIC_DB_PASSWORD' => '']);
        $this->assertSame('db.servidor.com', $config->get('database.host'));
        $this->assertSame('archivo', $config->get('database.password'), 'una variable vacía no pisa el valor');
    }

    public function testAppConfigContainsNoCredentials(): void
    {
        $app = require dirname(__DIR__, 2) . '/config/app.php';
        $this->assertSame('', $app['database']['user']);
        $this->assertSame('', $app['database']['password']);
    }
}
