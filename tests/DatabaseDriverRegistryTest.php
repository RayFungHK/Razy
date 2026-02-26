<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Database;
use Razy\Database\Driver;
use Razy\Database\Driver\MySQL;
use Razy\Database\Driver\PostgreSQL;
use Razy\Database\Driver\SQLite;
use Razy\Exception\ConnectionException;

/**
 * Tests for Database driver registry (Phase 4.5) and legacy fallback removal (Phase 4.2).
 */
#[CoversClass(Database::class)]
class DatabaseDriverRegistryTest extends TestCase
{
    // ?�?�?� CreateDriver with built-in types ?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�

    public function testCreateDriverMySQL(): void
    {
        $driver = Database::createDriver('mysql');
        $this->assertInstanceOf(MySQL::class, $driver);
    }

    public function testCreateDriverMariaDB(): void
    {
        $driver = Database::createDriver('mariadb');
        $this->assertInstanceOf(MySQL::class, $driver);
    }

    public function testCreateDriverPgsql(): void
    {
        $driver = Database::createDriver('pgsql');
        $this->assertInstanceOf(PostgreSQL::class, $driver);
    }

    public function testCreateDriverPostgresAlias(): void
    {
        $driver = Database::createDriver('postgres');
        $this->assertInstanceOf(PostgreSQL::class, $driver);
    }

    public function testCreateDriverPostgreSQLAlias(): void
    {
        $driver = Database::createDriver('postgresql');
        $this->assertInstanceOf(PostgreSQL::class, $driver);
    }

    public function testCreateDriverSQLite(): void
    {
        $driver = Database::createDriver('sqlite');
        $this->assertInstanceOf(SQLite::class, $driver);
    }

    public function testCreateDriverSQLite3Alias(): void
    {
        $driver = Database::createDriver('sqlite3');
        $this->assertInstanceOf(SQLite::class, $driver);
    }

    public function testCreateDriverIsCaseInsensitive(): void
    {
        $driver = Database::createDriver('MySQL');
        $this->assertInstanceOf(MySQL::class, $driver);

        $driver2 = Database::createDriver('PGSQL');
        $this->assertInstanceOf(PostgreSQL::class, $driver2);
    }

    public function testCreateDriverUnsupportedThrows(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Unsupported database driver');
        Database::createDriver('oracle');
    }

    // ?�?�?� RegisterDriver ?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�

    public function testRegisterCustomDriver(): void
    {
        // Register a custom driver using SQLite as a stand-in
        Database::registerDriver('custom_test', SQLite::class);

        $driver = Database::createDriver('custom_test');
        $this->assertInstanceOf(SQLite::class, $driver);
    }

    public function testRegisterDriverIsCaseInsensitive(): void
    {
        Database::registerDriver('CUSTOM_UPPER', SQLite::class);

        $driver = Database::createDriver('custom_upper');
        $this->assertInstanceOf(SQLite::class, $driver);
    }

    public function testRegisterDriverOverridesExisting(): void
    {
        // Override the mysql driver to use SQLite (for testing purposes)
        $originalDriver = Database::createDriver('mysql');
        $this->assertInstanceOf(MySQL::class, $originalDriver);

        Database::registerDriver('mysql', SQLite::class);
        $overriddenDriver = Database::createDriver('mysql');
        $this->assertInstanceOf(SQLite::class, $overriddenDriver);

        // Restore original
        Database::registerDriver('mysql', MySQL::class);
    }

    public function testRegisterInvalidDriverThrows(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('must extend');
        Database::registerDriver('bad', \stdClass::class);
    }

    // ?�?�?� Legacy fallback removal (Phase 4.2) ?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�

    public function testSetTimezoneWithoutDriverThrows(): void
    {
        $db = new Database('test_tz');
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('no database driver connected');
        $db->setTimezone('+08:00');
    }

    public function testGetCollationWithoutDriverThrows(): void
    {
        $db = new Database('test_collation');
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('no database driver connected');
        $db->getCollation('utf8');
    }

    public function testGetCharsetWithoutDriverThrows(): void
    {
        $db = new Database('test_charset');
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('no database driver connected');
        $db->getCharset();
    }

    public function testIsTableExistsWithoutDriverReturnsFalse(): void
    {
        $db = new Database('test_table');
        // When no driver connected, should return false gracefully
        $this->assertFalse($db->isTableExists('some_table'));
    }

    // ?�?�?� Database constructor ?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�

    public function testConstructorGeneratesNameWhenEmpty(): void
    {
        $db = new Database();
        $name = $db->getName();
        $this->assertStringStartsWith('Database_', $name);
        $this->assertSame(17, strlen($name)); // Database_ + 8 hex chars
    }

    public function testConstructorTrimsName(): void
    {
        $db = new Database('  my_db  ');
        $this->assertSame('my_db', $db->getName());
    }

    // ?�?�?� Driver constants ?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�

    public function testDriverConstants(): void
    {
        $this->assertSame('mysql', Database::DRIVER_MYSQL);
        $this->assertSame('pgsql', Database::DRIVER_PGSQL);
        $this->assertSame('sqlite', Database::DRIVER_SQLITE);
    }

    // ?�?�?� ResetInstances ?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�

    public function testResetInstancesClearsRegistry(): void
    {
        // GetInstance creates and caches
        $db1 = Database::getInstance('reset_test');
        $this->assertNotNull($db1);

        Database::resetInstances();

        // After reset, same name should create a new instance
        $db2 = Database::getInstance('reset_test');
        $this->assertNotSame(spl_object_id($db1), spl_object_id($db2));
    }
}
