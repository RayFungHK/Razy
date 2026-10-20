<?php

/**
 * This file is part of Razy.
 *
 * RZ-07: SchemaBuilder and MigrationManager against a real Postgres.
 * Runs when RAZY_TEST_PGSQL_HOST is set (CI's postgres-integration job has a
 * PG 16 service; a skip there is a failure).
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Razy\Tests;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Database;
use Razy\Database\MigrationManager;
use Razy\Database\Schema\PostgresGrammar;
use Razy\Database\SchemaBuilder;
use Razy\Database\Table;
use Razy\Database\Table\TableHelper;

#[CoversClass(SchemaBuilder::class)]
#[CoversClass(PostgresGrammar::class)]
class SchemaPostgresLiveTest extends TestCase
{
    private Database $db;

    private string $dir = '';

    protected function setUp(): void
    {
        $host = \getenv('RAZY_TEST_PGSQL_HOST');
        if (!$host) {
            $this->markTestSkipped('RAZY_TEST_PGSQL_HOST not set');
        }
        $this->db = new Database('rz07_' . \bin2hex(\random_bytes(3)));
        $ok = $this->db->connectWithDriver('pgsql', [
            'host' => $host,
            'port' => (int) (\getenv('RAZY_TEST_PGSQL_PORT') ?: 5432),
            'database' => \getenv('RAZY_TEST_PGSQL_DB') ?: 'razy_test',
            'username' => \getenv('RAZY_TEST_PGSQL_USER') ?: 'razy',
            'password' => \getenv('RAZY_TEST_PGSQL_PASSWORD') ?: 'razy',
        ]);
        $this->assertTrue($ok, 'Postgres connection');
        $this->dropAll();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->isConnected()) {
            $this->dropAll();
        }
        if ($this->dir !== '' && \is_dir($this->dir)) {
            \array_map('unlink', \glob($this->dir . '/*') ?: []);
            \rmdir($this->dir);
        }
    }

    public function testCreateInsertAndReadBack(): void
    {
        $schema = new SchemaBuilder($this->db);
        $schema->create('rz07_teams', static function (Table $t): void {
            $t->addColumn('team_id=type(auto)');
            $t->addColumn('name=type(text),length(64),key(unique)');
        });
        $schema->create('rz07_users', static function (Table $t): void {
            $t->addColumn('user_id=type(auto)');
            $t->addColumn('login=type(text),length(64),key(unique)');
            $t->addColumn('is_admin=type(bool)');
            $t->addColumn('settings=type(json)');
            $t->addColumn('balance=type(decimal),length(10,2)');
            $t->addColumn('bio=type(full_text),key(fulltext)');
            $t->addColumn('created_at=type(datetime),oncreate');
            $t->addColumn('team_id=type(int),key(index),reference(rz07_teams,team_id)');
        });
        $this->assertTrue($schema->hasTable('rz07_users'));

        $pdo = $this->db->getDBAdapter();
        $pdo->exec("INSERT INTO rz07_teams (name) VALUES ('core')");
        $pdo->exec("INSERT INTO rz07_users (login, is_admin, settings, balance, bio, team_id) VALUES ('ray', TRUE, '{\"a\":1}', 12.5, 'hello world', 1)");
        $row = $pdo->query('SELECT * FROM rz07_users')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(1, (int) $row['user_id'], 'identity column numbers rows');
        $this->assertTrue((bool) $row['is_admin']);
        $this->assertSame(['a' => 1], \json_decode($row['settings'], true));
        $this->assertSame('12.50', $row['balance']);
        $this->assertNotNull($row['created_at'], 'DEFAULT CURRENT_TIMESTAMP applied');

        $hits = $pdo->query("SELECT count(*) FROM rz07_users WHERE to_tsvector('simple', coalesce(bio, '')) @@ to_tsquery('simple', 'world')")->fetchColumn();
        $this->assertSame(1, (int) $hits);

        $this->expectException(PDOException::class);
        $pdo->exec("INSERT INTO rz07_users (login, team_id) VALUES ('ghost', 99)");
    }

    public function testAlterTableAppliesEveryOperation(): void
    {
        $schema = new SchemaBuilder($this->db);
        $schema->create('rz07_items', static function (Table $t): void {
            $t->addColumn('item_id=type(auto)');
            $t->addColumn('qty=type(int)');
            $t->addColumn('legacy=type(text)');
            $t->addColumn('mail=type(text)');
        });
        $schema->table('rz07_items', static function (TableHelper $h): void {
            $h->dropColumn('legacy');
            $h->addColumn('note=type(text),nullable');
            $h->modifyColumn('qty', 'qty=type(bigint),default(3)');
            $h->renameColumn('mail', 'email');
            $h->addIndex('INDEX', 'note', 'rz07_items_note_idx');
            $h->comment('stock');
        });

        $cols = $this->columns('rz07_items');
        $this->assertArrayNotHasKey('legacy', $cols);
        $this->assertArrayHasKey('note', $cols);
        $this->assertArrayHasKey('email', $cols);
        $this->assertSame('bigint', $cols['qty']['data_type']);
        $this->assertStringContainsString('3', (string) $cols['qty']['column_default']);
        $this->assertSame(1, (int) $this->db->getDBAdapter()->query("SELECT count(*) FROM pg_indexes WHERE indexname = 'rz07_items_note_idx'")->fetchColumn());

        // A column key(unique) is dropped by the column name, as on MySQL.
        $schema->table('rz07_items', static fn (TableHelper $h) => $h->addColumn('sku=type(text),length(32),key(unique)'));
        $pdo = $this->db->getDBAdapter();
        $pdo->exec("INSERT INTO rz07_items (sku) VALUES ('A')");
        try {
            $pdo->exec("INSERT INTO rz07_items (sku) VALUES ('A')");
            $this->fail('the unique key must refuse a duplicate');
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        }
        $schema->table('rz07_items', static fn (TableHelper $h) => $h->dropIndex('sku'));
        $pdo->exec("INSERT INTO rz07_items (sku) VALUES ('A')");
        $this->assertSame(2, (int) $pdo->query("SELECT count(*) FROM rz07_items WHERE sku = 'A'")->fetchColumn());

        $schema->table('rz07_items', static fn (TableHelper $h) => $h->rename('rz07_stock'));
        $this->assertTrue($schema->hasTable('rz07_stock'));
    }

    public function testMigrationManagerRunsAndRollsBackOnPostgres(): void
    {
        $this->dir = \sys_get_temp_dir() . '/razy_rz07_mig_' . \bin2hex(\random_bytes(3));
        \mkdir($this->dir);
        \file_put_contents($this->dir . '/2026_10_20_000001_CreateRz07Orders.php', <<<'PHP'
            <?php
            use Razy\Database\Migration;
            use Razy\Database\SchemaBuilder;
            use Razy\Database\Table;
            use Razy\Database\Table\TableHelper;

            return new class extends Migration {
                public function up(SchemaBuilder $schema): void
                {
                    $schema->create('rz07_orders', static function (Table $t): void {
                        $t->addColumn('order_id=type(auto)');
                        $t->addColumn('total=type(decimal),length(12,2)');
                    });
                    $schema->table('rz07_orders', static fn (TableHelper $h) => $h->addColumn('paid=type(bool)'));
                }

                public function down(SchemaBuilder $schema): void
                {
                    $schema->dropIfExists('rz07_orders');
                }
            };
            PHP);

        $manager = new MigrationManager($this->db, 'rz07/test');
        $manager->addPath($this->dir);
        $this->assertSame(['2026_10_20_000001_CreateRz07Orders'], $manager->migrate());
        $this->assertArrayHasKey('paid', $this->columns('rz07_orders'));
        $this->assertTrue($manager->isUpToDate());

        $this->assertSame(['2026_10_20_000001_CreateRz07Orders'], $manager->rollback());
        $this->assertFalse((new SchemaBuilder($this->db))->hasTable('rz07_orders'));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function columns(string $table): array
    {
        $stmt = $this->db->getDBAdapter()->prepare('SELECT column_name, data_type, column_default FROM information_schema.columns WHERE table_name = :t');
        $stmt->execute(['t' => $table]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[$row['column_name']] = $row;
        }

        return $out;
    }

    private function dropAll(): void
    {
        $pdo = $this->db->getDBAdapter();
        foreach (['rz07_users', 'rz07_teams', 'rz07_items', 'rz07_stock', 'rz07_orders'] as $t) {
            $pdo->exec("DROP TABLE IF EXISTS {$t} CASCADE");
        }
        if ($pdo->query("SELECT to_regclass('razy_migrations')")->fetchColumn()) {
            $pdo->exec("DELETE FROM razy_migrations WHERE scope = 'rz07/test'");
        }
    }
}
