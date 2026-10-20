<?php

/**
 * This file is part of Razy.
 *
 * RZ-08: Database::fromUrl() / parseUrl() — one DATABASE_URL instead of a
 * hand-built PDO per caller.
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Database;
use Razy\Exception\ConfigurationException;
use Razy\Exception\ConnectionException;

#[CoversClass(Database::class)]
class DatabaseFromUrlTest extends TestCase
{
    protected function tearDown(): void
    {
        Database::resetInstances();
    }

    /**
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    public static function urlProvider(): array
    {
        return [
            'postgresql' => [
                'postgresql://app:s%40cret@db.internal:5434/appdb?sslmode=require',
                'pgsql',
                ['host' => 'db.internal', 'port' => 5434, 'username' => 'app', 'password' => 's@cret', 'database' => 'appdb', 'sslmode' => 'require'],
            ],
            'postgres default port' => [
                'postgres://u@localhost/d',
                'pgsql',
                ['host' => 'localhost', 'username' => 'u', 'password' => '', 'database' => 'd'],
            ],
            'mysql charset' => [
                'mysql://root:pw@127.0.0.1:3307/shop?charset=utf8mb4',
                'mysql',
                ['host' => '127.0.0.1', 'port' => 3307, 'username' => 'root', 'password' => 'pw', 'database' => 'shop', 'charset' => 'utf8mb4'],
            ],
            'ipv6 host' => [
                'pgsql://u:p@[::1]:5432/d',
                'pgsql',
                ['host' => '::1', 'port' => 5432, 'username' => 'u', 'password' => 'p', 'database' => 'd'],
            ],
            'sqlite memory' => ['sqlite::memory:', 'sqlite', ['database' => ':memory:']],
            'sqlite absolute path' => ['sqlite:///var/data/app.db', 'sqlite', ['database' => '/var/data/app.db']],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badUrlProvider(): array
    {
        return [
            'empty' => [''],
            'no scheme' => ['localhost/db'],
            'unknown scheme' => ['oracle://u:p@h/d'],
            'no database' => ['postgresql://u:p@h:5432'],
            'unknown option' => ['postgresql://u:p@h/d?evil=1'],
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('urlProvider')]
    public function testParseUrl(string $url, string $driver, array $config): void
    {
        [$parsedDriver, $parsedConfig] = Database::parseUrl($url);
        $this->assertSame($driver, $parsedDriver);
        $this->assertSame($config, $parsedConfig);
    }

    #[DataProvider('badUrlProvider')]
    public function testParseUrlRejects(string $url): void
    {
        $this->expectException(ConfigurationException::class);
        Database::parseUrl($url);
    }

    public function testParseUrlErrorNeverLeaksPassword(): void
    {
        try {
            Database::parseUrl('oracle://user:hunter2@h/d');
            $this->fail('should throw');
        } catch (ConfigurationException $e) {
            $this->assertStringNotContainsString('hunter2', $e->getMessage());
        }
    }

    public function testFromUrlConnectsAndRegistersSharedInstance(): void
    {
        $db = Database::fromUrl('sqlite::memory:', 'rz08');
        $this->assertTrue($db->isConnected());
        $this->assertSame('sqlite', $db->getDriverType());
        $this->assertSame($db, Database::getSharedInstance('rz08'));
    }

    public function testFromUrlFailureThrowsWithoutPassword(): void
    {
        try {
            Database::fromUrl('postgresql://u:hunter2@127.0.0.1:1/none?connect_timeout=1', 'rz08-fail');
            $this->fail('should throw');
        } catch (ConnectionException $e) {
            $this->assertStringNotContainsString('hunter2', $e->getMessage());
            $this->assertStringContainsString('127.0.0.1', $e->getMessage());
        }
        $this->assertNull(Database::getSharedInstance('rz08-fail'));
    }

    public function testPostgresDsnCarriesUrlOptions(): void
    {
        [, $config] = Database::parseUrl('postgresql://u:p@db:5434/app?sslmode=verify-full&connect_timeout=3&application_name=worker');
        $this->assertSame(
            'pgsql:host=db;port=5434;dbname=app;sslmode=verify-full;connect_timeout=3;application_name=worker',
            Database\Driver\PostgreSQL::buildDsn($config),
        );
        $this->assertSame('pgsql:host=localhost;port=5432;dbname=', Database\Driver\PostgreSQL::buildDsn([]));
    }

    public function testParseUrlRejectsDsnSeparatorInjection(): void
    {
        $this->expectException(ConfigurationException::class);
        Database::parseUrl('postgresql://u:p@h/app%3Bsslmode%3Ddisable');
    }

    public function testParseUrlRejectsSeparatorInOptionValue(): void
    {
        $this->expectException(ConfigurationException::class);
        Database::parseUrl('postgresql://u:p@h/app?application_name=a%3Bsslmode%3Ddisable');
    }
}
