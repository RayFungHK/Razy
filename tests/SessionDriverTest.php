<?php

/**
 * This file is part of Razy v0.5.
 *
 * Comprehensive tests for P13: Session Drivers (File & Database).
 *
 * Tests the FileDriver (filesystem persistence, atomic writes, GC) and
 * DatabaseDriver (PDO-backed persistence, upsert, GC) — both implementing
 * SessionDriverInterface. Includes integration tests with the Session class.
 *
 * @package Razy
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Tests;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Contract\SessionDriverInterface;
use Razy\Session\Driver\DatabaseDriver;
use Razy\Session\Driver\FileDriver;
use Razy\Session\Session;
use Razy\Session\SessionConfig;

#[CoversClass(FileDriver::class)]
#[CoversClass(DatabaseDriver::class)]
class SessionDriverTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'razy_session_test_' . \bin2hex(\random_bytes(4));
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
        if (\is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  DataProvider — Both Drivers Share Expected Behavior
    // ══════════════════════════════════════════════════════════════

    public static function sessionDataProvider(): array
    {
        return [
            'simple key-value' => [['key' => 'value']],
            'integers' => [['count' => 42, 'zero' => 0, 'negative' => -1]],
            'floats' => [['pi' => 3.14159, 'neg' => -0.5]],
            'booleans' => [['yes' => true, 'no' => false]],
            'null values' => [['nothing' => null]],
            'nested arrays' => [['user' => ['name' => 'Test', 'prefs' => ['lang' => 'en']]]],
            'empty array' => [[]],
            'unicode' => [['name' => '日本語テスト', 'emoji' => '🎉']],
            'special chars' => [['html' => '<script>alert("xss")</script>', 'quotes' => "it's \"tricky\""]],
        ];
    }

    // ══════════════════════════════════════════════════════════════
    //  FileDriver — Interface Contract
    // ══════════════════════════════════════════════════════════════

    public function testFileDriverImplementsInterface(): void
    {
        $driver = new FileDriver($this->tempDir);
        $this->assertInstanceOf(SessionDriverInterface::class, $driver);
    }

    // ══════════════════════════════════════════════════════════════
    //  FileDriver — Open
    // ══════════════════════════════════════════════════════════════

    public function testOpenCreatesDirectory(): void
    {
        $this->assertDirectoryDoesNotExist($this->tempDir);

        $driver = new FileDriver($this->tempDir);
        $result = $driver->open();

        $this->assertTrue($result);
        $this->assertDirectoryExists($this->tempDir);
    }

    public function testOpenReturnsTrueWhenDirectoryExists(): void
    {
        \mkdir($this->tempDir, 0o700, true);

        $driver = new FileDriver($this->tempDir);
        $result = $driver->open();

        $this->assertTrue($result);
    }

    public function testOpenCanBeCalledMultipleTimes(): void
    {
        $driver = new FileDriver($this->tempDir);
        $this->assertTrue($driver->open());
        $this->assertTrue($driver->open());
    }

    // ══════════════════════════════════════════════════════════════
    //  FileDriver — Read / Write
    // ══════════════════════════════════════════════════════════════

    public function testReadNonExistentReturnsEmptyArray(): void
    {
        $driver = new FileDriver($this->tempDir);
        $driver->open();

        $data = $driver->read('nonexistent_id');

        $this->assertSame([], $data);
    }

    public function testWriteAndReadRoundTrip(): void
    {
        $driver = new FileDriver($this->tempDir);
        $driver->open();

        $sessionData = ['user_id' => 42, 'name' => 'Alice', 'roles' => ['admin', 'user']];

        $this->assertTrue($driver->write('sess123', $sessionData));

        $result = $driver->read('sess123');

        $this->assertSame($sessionData, $result);
    }

    public function testWriteCreatesFile(): void
    {
        $driver = new FileDriver($this->tempDir);
        $driver->open();

        $driver->write('abc', ['key' => 'value']);

        $this->assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . 'sess_abc');
    }

    public function testWriteOverwritesExistingData(): void
    {
        $driver = new FileDriver($this->tempDir);
        $driver->open();

        $driver->write('id1', ['version' => 1]);
        $driver->write('id1', ['version' => 2, 'extra' => true]);

        $result = $driver->read('id1');

        $this->assertSame(['version' => 2, 'extra' => true], $result);
    }

    public function testWriteEmptyArray(): void
    {
        $driver = new FileDriver($this->tempDir);
        $driver->open();

        $driver->write('empty', []);

        $this->assertSame([], $driver->read('empty'));
    }

    public function testWriteComplexNestedData(): void
    {
        $driver = new FileDriver($this->tempDir);
        $driver->open();

        $complex = [
            'user' => ['name' => 'Bob', 'age' => 30],
            'cart' => [
                ['id' => 1, 'qty' => 3, 'price' => 9.99],
                ['id' => 2, 'qty' => 1, 'price' => 24.50],
            ],
            'flash' => ['message' => 'Saved!'],
            'null_value' => null,
            'bool_value' => false,
        ];

        $driver->write('complex', $complex);

        $this->assertSame($complex, $driver->read('complex'));
    }

    public function testMultipleSessionsCoexist(): void
    {
        $driver = new FileDriver($this->tempDir);
        $driver->open();

        $driver->write('user_1', ['name' => 'Alice']);
        $driver->write('user_2', ['name' => 'Bob']);
        $driver->write('user_3', ['name' => 'Charlie']);

        $this->assertSame(['name' => 'Alice'], $driver->read('user_1'));
        $this->assertSame(['name' => 'Bob'], $driver->read('user_2'));
        $this->assertSame(['name' => 'Charlie'], $driver->read('user_3'));
    }

    // ══════════════════════════════════════════════════════════════
    //  FileDriver — Destroy
    // ══════════════════════════════════════════════════════════════

    public function testDestroyRemovesFile(): void
    {
        $driver = new FileDriver($this->tempDir);
        $driver->open();

        $driver->write('to_delete', ['data' => true]);
        $this->assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . 'sess_to_delete');

        $result = $driver->destroy('to_delete');

        $this->assertTrue($result);
        $this->assertFileDoesNotExist($this->tempDir . DIRECTORY_SEPARATOR . 'sess_to_delete');
    }

    public function testDestroyNonExistentReturnsTrue(): void
    {
        $driver = new FileDriver($this->tempDir);
        $driver->open();

        $this->assertTrue($driver->destroy('does_not_exist'));
    }

    public function testDestroyedSessionReturnsEmptyOnRead(): void
    {
        $driver = new FileDriver($this->tempDir);
        $driver->open();

        $driver->write('temp', ['key' => 'val']);
        $driver->destroy('temp');

        $this->assertSame([], $driver->read('temp'));
    }

    // ══════════════════════════════════════════════════════════════
    //  FileDriver — Garbage Collection
    // ══════════════════════════════════════════════════════════════

    public function testGcRemovesExpiredFiles(): void
    {
        $driver = new FileDriver($this->tempDir);
        $driver->open();

        // Create sessions
        $driver->write('old', ['expired' => true]);
        $driver->write('fresh', ['active' => true]);

        // Make the 'old' session file look expired (mtime = 2 hours ago)
        $oldFile = $this->tempDir . DIRECTORY_SEPARATOR . 'sess_old';
        \touch($oldFile, \time() - 7200);

        $deleted = $driver->gc(3600); // max lifetime = 1 hour

        $this->assertSame(1, $deleted);
        $this->assertFileDoesNotExist($oldFile);
        $this->assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . 'sess_fresh');
    }

    public function testGcPreservesFreshFiles(): void
    {
        $driver = new FileDriver($this->tempDir);
        $driver->open();

        $driver->write('fresh1', ['a' => 1]);
        $driver->write('fresh2', ['b' => 2]);

        $deleted = $driver->gc(3600);

        $this->assertSame(0, $deleted);
    }

    public function testGcReturnsDeletedCount(): void
    {
        $driver = new FileDriver($this->tempDir);
        $driver->open();

        for ($i = 0; $i < 5; $i++) {
            $driver->write("expired_{$i}", ['i' => $i]);
            \touch($this->tempDir . DIRECTORY_SEPARATOR . "sess_expired_{$i}", \time() - 7200);
        }

        $driver->write('fresh', ['alive' => true]);

        $deleted = $driver->gc(3600);

        $this->assertSame(5, $deleted);
        $this->assertSame(5, $driver->getLastGcCount());
    }

    public function testGcIgnoresNonSessionFiles(): void
    {
        $driver = new FileDriver($this->tempDir);
        $driver->open();

        // Create a non-session file
        \file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'other_file.txt', 'data');
        \touch($this->tempDir . DIRECTORY_SEPARATOR . 'other_file.txt', \time() - 7200);

        $deleted = $driver->gc(3600);

        $this->assertSame(0, $deleted);
        $this->assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . 'other_file.txt');
    }

    // ══════════════════════════════════════════════════════════════
    //  FileDriver — Close & Accessors
    // ══════════════════════════════════════════════════════════════

    public function testCloseReturnsTrue(): void
    {
        $driver = new FileDriver($this->tempDir);
        $driver->open();

        $this->assertTrue($driver->close());
    }

    public function testGetSavePath(): void
    {
        $driver = new FileDriver('/tmp/sessions');

        $this->assertSame('/tmp/sessions', $driver->getSavePath());
    }

    public function testGetPrefix(): void
    {
        $driver = new FileDriver('/tmp', 'my_sess_');

        $this->assertSame('my_sess_', $driver->getPrefix());
    }

    public function testDefaultPrefix(): void
    {
        $driver = new FileDriver('/tmp');

        $this->assertSame('sess_', $driver->getPrefix());
    }

    public function testCustomPrefix(): void
    {
        $driver = new FileDriver($this->tempDir, 'custom_');
        $driver->open();

        $driver->write('id1', ['data' => true]);

        $this->assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . 'custom_id1');
    }

    public function testTrailingSlashStripped(): void
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR;
        $driver = new FileDriver($path);

        $this->assertSame($this->tempDir, $driver->getSavePath());
    }

    // ══════════════════════════════════════════════════════════════
    //  FileDriver — Corrupt Data Handling
    // ══════════════════════════════════════════════════════════════

    public function testReadCorruptFileReturnsEmptyArray(): void
    {
        $driver = new FileDriver($this->tempDir);
        $driver->open();

        // Write garbage data directly to a session file
        \file_put_contents(
            $this->tempDir . DIRECTORY_SEPARATOR . 'sess_corrupt',
            'not_valid_serialized_data{{{',
        );

        $this->assertSame([], $driver->read('corrupt'));
    }

    public function testReadEmptyFileReturnsEmptyArray(): void
    {
        $driver = new FileDriver($this->tempDir);
        $driver->open();

        \file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'sess_empty', '');

        $this->assertSame([], $driver->read('empty'));
    }

    // ══════════════════════════════════════════════════════════════
    //  FileDriver — Session Integration
    // ══════════════════════════════════════════════════════════════

    public function testFileDriverWithSessionLifecycle(): void
    {
        $driver = new FileDriver($this->tempDir);
        $session = new Session($driver, new SessionConfig(gcDivisor: 0));

        $session->start();
        $session->set('user', 'Alice');
        $session->set('role', 'admin');
        $session->save();

        $sessionId = $session->getId();

        // Simulate new request: new Session instance, same driver
        $driver2 = new FileDriver($this->tempDir);
        $session2 = new Session($driver2, new SessionConfig(gcDivisor: 0));
        $session2->setId($sessionId);
        $session2->start();

        $this->assertSame('Alice', $session2->get('user'));
        $this->assertSame('admin', $session2->get('role'));
    }

    public function testFileDriverSessionDestroy(): void
    {
        $driver = new FileDriver($this->tempDir);
        $session = new Session($driver, new SessionConfig(gcDivisor: 0));

        $session->start();
        $session->set('key', 'value');
        $session->save();

        $sessionId = $session->getId();

        // Destroy the session
        $session->start();
        $session->destroy();

        // Verify file is gone
        $this->assertFileDoesNotExist($this->tempDir . DIRECTORY_SEPARATOR . 'sess_' . $sessionId);

        // Verify data is gone when re-reading
        $driver2 = new FileDriver($this->tempDir);
        $this->assertSame([], $driver2->read($sessionId));
    }

    public function testDatabaseDriverImplementsInterface(): void
    {
        $pdo = $this->createPdoAndTable();
        $driver = new DatabaseDriver($pdo);

        $this->assertInstanceOf(SessionDriverInterface::class, $driver);
    }

    // ══════════════════════════════════════════════════════════════
    //  DatabaseDriver — Open / Close
    // ══════════════════════════════════════════════════════════════

    public function testDbOpenReturnsTrue(): void
    {
        $pdo = $this->createPdoAndTable();
        $driver = new DatabaseDriver($pdo);

        $this->assertTrue($driver->open());
    }

    public function testDbCloseReturnsTrue(): void
    {
        $pdo = $this->createPdoAndTable();
        $driver = new DatabaseDriver($pdo);

        $this->assertTrue($driver->close());
    }

    // ══════════════════════════════════════════════════════════════
    //  DatabaseDriver — Read / Write
    // ══════════════════════════════════════════════════════════════

    public function testDbReadNonExistentReturnsEmptyArray(): void
    {
        $pdo = $this->createPdoAndTable();
        $driver = new DatabaseDriver($pdo);

        $this->assertSame([], $driver->read('no_such_id'));
    }

    public function testDbWriteAndReadRoundTrip(): void
    {
        $pdo = $this->createPdoAndTable();
        $driver = new DatabaseDriver($pdo);

        $data = ['user_id' => 99, 'theme' => 'dark', 'perms' => ['read', 'write']];

        $this->assertTrue($driver->write('s1', $data));
        $this->assertSame($data, $driver->read('s1'));
    }

    public function testDbWriteCreatesRow(): void
    {
        $pdo = $this->createPdoAndTable();
        $driver = new DatabaseDriver($pdo);

        $driver->write('rowcheck', ['k' => 'v']);

        $count = (int) $pdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testDbWriteUpsertsExistingRow(): void
    {
        $pdo = $this->createPdoAndTable();
        $driver = new DatabaseDriver($pdo);

        $driver->write('id1', ['v' => 1]);
        $driver->write('id1', ['v' => 2, 'added' => true]);

        $result = $driver->read('id1');
        $this->assertSame(['v' => 2, 'added' => true], $result);

        // Should still be only one row
        $count = (int) $pdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testDbWriteUpdatesLastActivity(): void
    {
        $pdo = $this->createPdoAndTable();
        $driver = new DatabaseDriver($pdo);

        $driver->write('timed', ['x' => 1]);

        $row = $pdo->query("SELECT last_activity FROM sessions WHERE id = 'timed'")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotNull($row);
        $this->assertGreaterThan(0, $row['last_activity']);
        $this->assertLessThanOrEqual(\time(), $row['last_activity']);
    }

    public function testDbWriteEmptyArray(): void
    {
        $pdo = $this->createPdoAndTable();
        $driver = new DatabaseDriver($pdo);

        $driver->write('empty_data', []);
        $this->assertSame([], $driver->read('empty_data'));
    }

    public function testDbWriteComplexNestedData(): void
    {
        $pdo = $this->createPdoAndTable();
        $driver = new DatabaseDriver($pdo);

        $complex = [
            'user' => ['name' => 'Alice', 'meta' => ['login_count' => 5]],
            'cart' => [['id' => 1, 'price' => 10.50]],
            'flags' => [true, false, null],
        ];

        $driver->write('nested', $complex);
        $this->assertSame($complex, $driver->read('nested'));
    }

    public function testDbMultipleSessionsCoexist(): void
    {
        $pdo = $this->createPdoAndTable();
        $driver = new DatabaseDriver($pdo);

        $driver->write('a', ['name' => 'A']);
        $driver->write('b', ['name' => 'B']);
        $driver->write('c', ['name' => 'C']);

        $this->assertSame(['name' => 'A'], $driver->read('a'));
        $this->assertSame(['name' => 'B'], $driver->read('b'));
        $this->assertSame(['name' => 'C'], $driver->read('c'));

        $count = (int) $pdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn();
        $this->assertSame(3, $count);
    }

    // ══════════════════════════════════════════════════════════════
    //  DatabaseDriver — Destroy
    // ══════════════════════════════════════════════════════════════

    public function testDbDestroyRemovesRow(): void
    {
        $pdo = $this->createPdoAndTable();
        $driver = new DatabaseDriver($pdo);

        $driver->write('rm_me', ['data' => true]);
        $this->assertTrue($driver->destroy('rm_me'));

        $count = (int) $pdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testDbDestroyNonExistentReturnsTrue(): void
    {
        $pdo = $this->createPdoAndTable();
        $driver = new DatabaseDriver($pdo);

        $this->assertTrue($driver->destroy('ghost'));
    }

    public function testDbDestroyedSessionReturnsEmptyOnRead(): void
    {
        $pdo = $this->createPdoAndTable();
        $driver = new DatabaseDriver($pdo);

        $driver->write('temp', ['active' => true]);
        $driver->destroy('temp');

        $this->assertSame([], $driver->read('temp'));
    }

    // ══════════════════════════════════════════════════════════════
    //  DatabaseDriver — Garbage Collection
    // ══════════════════════════════════════════════════════════════

    public function testDbGcRemovesExpiredSessions(): void
    {
        $pdo = $this->createPdoAndTable();
        $driver = new DatabaseDriver($pdo);

        // Insert an "old" session with last_activity 2 hours ago
        $oldTime = \time() - 7200;
        $pdo->prepare('INSERT INTO sessions (id, data, last_activity) VALUES (:id, :data, :time)')
            ->execute(['id' => 'old', 'data' => \serialize(['expired' => true]), 'time' => $oldTime]);

        // Insert a "fresh" session
        $driver->write('fresh', ['active' => true]);

        $deleted = $driver->gc(3600); // max 1 hour

        $this->assertSame(1, $deleted);
        $this->assertSame([], $driver->read('old'));
        $this->assertSame(['active' => true], $driver->read('fresh'));
    }

    public function testDbGcPreservesFreshSessions(): void
    {
        $pdo = $this->createPdoAndTable();
        $driver = new DatabaseDriver($pdo);

        $driver->write('s1', ['a' => 1]);
        $driver->write('s2', ['b' => 2]);

        $deleted = $driver->gc(3600);

        $this->assertSame(0, $deleted);
    }

    public function testDbGcReturnsDeletedCount(): void
    {
        $pdo = $this->createPdoAndTable();
        $driver = new DatabaseDriver($pdo);

        $oldTime = \time() - 7200;
        for ($i = 0; $i < 4; $i++) {
            $pdo->prepare('INSERT INTO sessions (id, data, last_activity) VALUES (:id, :data, :time)')
                ->execute(['id' => "expired_{$i}", 'data' => \serialize([]), 'time' => $oldTime]);
        }

        $driver->write('alive', ['ok' => true]);

        $deleted = $driver->gc(3600);

        $this->assertSame(4, $deleted);
        $this->assertSame(4, $driver->getLastGcCount());
    }

    // ══════════════════════════════════════════════════════════════
    //  DatabaseDriver — Accessors
    // ══════════════════════════════════════════════════════════════

    public function testDbGetTable(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $driver = new DatabaseDriver($pdo, 'custom_sessions');

        $this->assertSame('custom_sessions', $driver->getTable());
    }

    public function testDbDefaultTable(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $driver = new DatabaseDriver($pdo);

        $this->assertSame('sessions', $driver->getTable());
    }

    public function testDbGetPdo(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $driver = new DatabaseDriver($pdo);

        $this->assertSame($pdo, $driver->getPdo());
    }

    // ══════════════════════════════════════════════════════════════
    //  DatabaseDriver — createTable
    // ══════════════════════════════════════════════════════════════

    public function testCreateTableCreatesSchema(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $driver = new DatabaseDriver($pdo);

        $this->assertTrue($driver->createTable());

        // Verify by writing + reading
        $driver->write('test_id', ['created' => true]);
        $this->assertSame(['created' => true], $driver->read('test_id'));
    }

    public function testCreateTableIdempotent(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $driver = new DatabaseDriver($pdo);

        $this->assertTrue($driver->createTable());
        $this->assertTrue($driver->createTable()); // second call should not fail
    }

    public function testCreateTableWithCustomName(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $driver = new DatabaseDriver($pdo, 'app_sessions');

        $this->assertTrue($driver->createTable());

        $driver->write('x', ['ok' => true]);
        $this->assertSame(['ok' => true], $driver->read('x'));
    }

    // ══════════════════════════════════════════════════════════════
    //  DatabaseDriver — Session Integration
    // ══════════════════════════════════════════════════════════════

    public function testDatabaseDriverWithSessionLifecycle(): void
    {
        $pdo = $this->createPdoAndTable();
        $driver = new DatabaseDriver($pdo);
        $session = new Session($driver, new SessionConfig(gcDivisor: 0));

        $session->start();
        $session->set('username', 'Bob');
        $session->set('theme', 'dark');
        $session->save();

        $sessionId = $session->getId();

        // Simulate next request
        $driver2 = new DatabaseDriver($pdo);
        $session2 = new Session($driver2, new SessionConfig(gcDivisor: 0));
        $session2->setId($sessionId);
        $session2->start();

        $this->assertSame('Bob', $session2->get('username'));
        $this->assertSame('dark', $session2->get('theme'));
    }

    public function testDatabaseDriverSessionDestroy(): void
    {
        $pdo = $this->createPdoAndTable();
        $driver = new DatabaseDriver($pdo);
        $session = new Session($driver, new SessionConfig(gcDivisor: 0));

        $session->start();
        $session->set('token', 'abc123');
        $session->save();

        $sessionId = $session->getId();

        // Destroy
        $session->start();
        $session->destroy();

        // Verify row is gone
        $count = (int) $pdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testDatabaseDriverSessionRegenerate(): void
    {
        $pdo = $this->createPdoAndTable();
        $driver = new DatabaseDriver($pdo);
        $session = new Session($driver, new SessionConfig(gcDivisor: 0));

        $session->start();
        $session->set('data', 'preserved');
        $oldId = $session->getId();
        $session->regenerate();
        $newId = $session->getId();
        $session->save();

        $this->assertNotSame($oldId, $newId);

        // Re-read with new ID
        $driver2 = new DatabaseDriver($pdo);
        $session2 = new Session($driver2, new SessionConfig(gcDivisor: 0));
        $session2->setId($newId);
        $session2->start();

        $this->assertSame('preserved', $session2->get('data'));
    }

    // ══════════════════════════════════════════════════════════════
    //  DatabaseDriver — Corrupt Data
    // ══════════════════════════════════════════════════════════════

    public function testDbReadCorruptDataReturnsEmptyArray(): void
    {
        $pdo = $this->createPdoAndTable();
        $driver = new DatabaseDriver($pdo);

        // Manually insert corrupt data
        $pdo->prepare('INSERT INTO sessions (id, data, last_activity) VALUES (:id, :data, :time)')
            ->execute(['id' => 'corrupt', 'data' => 'not_serialized{', 'time' => \time()]);

        $this->assertSame([], $driver->read('corrupt'));
    }

    public function testDbReadEmptyDataStringReturnsEmptyArray(): void
    {
        $pdo = $this->createPdoAndTable();
        $driver = new DatabaseDriver($pdo);

        $pdo->prepare('INSERT INTO sessions (id, data, last_activity) VALUES (:id, :data, :time)')
            ->execute(['id' => 'emptystr', 'data' => '', 'time' => \time()]);

        $this->assertSame([], $driver->read('emptystr'));
    }

    #[DataProvider('sessionDataProvider')]
    public function testFileDriverDataIntegrity(array $data): void
    {
        $driver = new FileDriver($this->tempDir);
        $driver->open();

        $driver->write('integrity', $data);
        $this->assertSame($data, $driver->read('integrity'));
    }

    #[DataProvider('sessionDataProvider')]
    public function testDatabaseDriverDataIntegrity(array $data): void
    {
        $pdo = $this->createPdoAndTable();
        $driver = new DatabaseDriver($pdo);

        $driver->write('integrity', $data);
        $this->assertSame($data, $driver->read('integrity'));
    }

    // ══════════════════════════════════════════════════════════════
    //  Edge Cases — Large Data
    // ══════════════════════════════════════════════════════════════

    public function testFileDriverLargeSessionData(): void
    {
        $driver = new FileDriver($this->tempDir);
        $driver->open();

        $largeData = [];
        for ($i = 0; $i < 1000; $i++) {
            $largeData["key_{$i}"] = \str_repeat("value_{$i}_", 10);
        }

        $driver->write('large', $largeData);
        $this->assertSame($largeData, $driver->read('large'));
    }

    public function testDatabaseDriverLargeSessionData(): void
    {
        $pdo = $this->createPdoAndTable();
        $driver = new DatabaseDriver($pdo);

        $largeData = [];
        for ($i = 0; $i < 1000; $i++) {
            $largeData["key_{$i}"] = \str_repeat("value_{$i}_", 10);
        }

        $driver->write('large', $largeData);
        $this->assertSame($largeData, $driver->read('large'));
    }

    private function removeDirectory(string $dir): void
    {
        $files = \scandir($dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (\is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                \unlink($path);
            }
        }
        \rmdir($dir);
    }

    // ══════════════════════════════════════════════════════════════
    //  DatabaseDriver — Interface Contract
    // ══════════════════════════════════════════════════════════════

    private function createPdoAndTable(string $table = 'sessions'): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE {$table} (
            id VARCHAR(128) NOT NULL PRIMARY KEY,
            data TEXT NOT NULL DEFAULT '',
            last_activity INTEGER NOT NULL DEFAULT 0
        )");

        return $pdo;
    }
}
