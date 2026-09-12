<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Database\Migration;
use Razy\ORM\Contract;
use Razy\ORM\ContractCompiler;

/**
 * Contract + ContractCompiler (C1/C2 of architecture/ORM-CONTRACT-PACKS.md):
 * the declarative skeleton, its fail-loud validation, DDL generation through
 * the EXISTING Column grammar, snapshot drift reporting, and migration-source
 * generation. No live DB is involved anywhere — Table/Column compile to SQL
 * strings standalone (SchemaBuilder adds only prefix + execution).
 */
#[CoversClass(Contract::class)]
#[CoversClass(ContractCompiler::class)]
class OrmContractTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function usersDefinition(): array
    {
        return [
            'table' => 'users',
            'primary_key' => 'id',
            'timestamps' => true,
            'fields' => [
                'id' => 'type(int),auto',
                'name' => 'type(varchar,100)',
                'email' => 'type(varchar,255),nullable',
                'profile' => [
                    'column' => 'type(json),nullable',
                    'json' => ['city' => 'type(varchar)', 'bio' => 'type(text)'],
                ],
                'team_id' => 'type(int),nullable,reference(teams,id)',
            ],
            'relations' => [
                ['kind' => 'hasMany', 'target' => 'Post', 'fk' => 'author_id', 'as' => 'posts'],
                ['kind' => 'belongsToMany', 'target' => 'Role', 'pivot' => 'role_user', 'as' => 'roles'],
            ],
            'indexes' => [
                ['columns' => ['email'], 'name' => 'idx_email'],
            ],
        ];
    }

    public function testDefineExposesDeclaredSkeleton(): void
    {
        $contract = Contract::define(self::usersDefinition());

        $this->assertSame('users', $contract->getTable());
        $this->assertSame('id', $contract->getPrimaryKey());
        $this->assertTrue($contract->hasTimestamps());
        $this->assertSame(['city' => 'type(varchar)', 'bio' => 'type(text)'], $contract->getFields()['profile']['json']);
        $this->assertSame(['profile.city', 'profile.bio'], $contract->getJsonAddresses());
        $this->assertCount(2, $contract->getRelations());
    }

    public function testValidationRejectsBadDefinitions(): void
    {
        $cases = [
            'missing table' => [['fields' => ['id' => 'type(int)']]],
            'bad table name' => [['table' => 'Users-1', 'fields' => ['id' => 'type(int)']]],
            'empty fields' => [['table' => 't', 'fields' => []]],
            'pk not declared' => [['table' => 't', 'fields' => ['name' => 'type(text)']]],
            'bad field name' => [['table' => 't', 'fields' => ['id' => 'type(int)', 'A B' => 'type(text)']]],
            'field not string/array' => [['table' => 't', 'fields' => ['id' => 42]]],
            'unknown relation kind' => [
                ['table' => 't', 'fields' => ['id' => 'type(int)'], 'relations' => [['kind' => 'greedy', 'target' => 'X', 'as' => 'x']]],
            ],
            'belongsToMany without pivot' => [
                ['table' => 't', 'fields' => ['id' => 'type(int)'], 'relations' => [['kind' => 'belongsToMany', 'target' => 'X', 'as' => 'x']]],
            ],
            'hasManyThrough without through' => [
                ['table' => 't', 'fields' => ['id' => 'type(int)'], 'relations' => [['kind' => 'hasManyThrough', 'target' => 'X', 'as' => 'x']]],
            ],
            'index on undeclared field' => [
                ['table' => 't', 'fields' => ['id' => 'type(int)'], 'indexes' => [['columns' => ['ghost']]]],
            ],
        ];

        foreach ($cases as $label => [$definition]) {
            $thrown = null;

            try {
                Contract::define($definition);
            } catch (\InvalidArgumentException $e) {
                $thrown = $e->getMessage();
            }

            $this->assertIsString($thrown, "definition '{$label}' must not pass Contract validation");
        }
    }

    public function testCreateTableSqlGoesThroughTheColumnGrammar(): void
    {
        $sql = (new ContractCompiler())->createTableSql(Contract::define(self::usersDefinition()));

        $this->assertStringContainsStringIgnoringCase('CREATE TABLE', $sql);
        $this->assertStringContainsString('users', $sql);
        $this->assertStringContainsString('`email`', $sql);
        // timestamps=true adds the pair:
        $this->assertStringContainsString('`created_at`', $sql);
        $this->assertStringContainsString('`updated_at`', $sql);
        // Field-level reference(...) must emit real FK DDL (§6.1 "FK 1:1 for generation"):
        $this->assertStringContainsString('FOREIGN KEY(`team_id`) REFERENCES `teams` (`id`)', $sql);
        // JSON annotation must NOT leak phantom columns:
        $this->assertStringNotContainsString('`profile.city`', $sql);
        $this->assertStringNotContainsString('`profile_bio`', $sql);
    }

    public function testSnapshotDriftCycle(): void
    {
        $compiler = new ContractCompiler();
        $contract = Contract::define(self::usersDefinition());
        $snapshot = $compiler->snapshot($contract);

        $clean = $compiler->drift($contract, $snapshot);
        $this->assertTrue($clean['clean'], 'unmodified contract must report no drift');

        $definition = self::usersDefinition();
        $definition['fields']['nickname'] = 'type(varchar,50),nullable';
        // A nullability flip is the canonical VISIBLE drift: the grammar
        // normalizes positional length (320 vs 255 exports identically), so
        // length edits are invisible in exportConfig and must not be asserted.
        $definition['fields']['email'] = 'type(varchar,255)';
        unset($definition['fields']['profile']);
        $grown = Contract::define($definition);

        $drift = $compiler->drift($grown, $snapshot);
        $this->assertFalse($drift['clean']);
        $this->assertSame(['nickname'], $drift['added']);
        $this->assertSame(['profile'], $drift['removed']);
        $this->assertSame(['email'], $drift['changed']);

        $this->assertTrue($compiler->drift($grown, $compiler->snapshot($grown))['clean'], 'snapshot discipline: re-snapshot => clean');
    }

    public function testDriftOnUnparsablePreviousDegradesHonestly(): void
    {
        $drift = (new ContractCompiler())->drift(Contract::define(self::usersDefinition()), 'not a snapshot at all');

        $this->assertFalse($drift['clean']);
        $this->assertTrue($drift['shape_changed'], 'unintelligible input must say so, not fabricate column diffs');
        $this->assertSame([], $drift['added']);
    }

    public function testGeneratedMigrationSourceIsLoadableRealPhp(): void
    {
        $source = (new ContractCompiler())->toMigrationSource(Contract::define(self::usersDefinition()), "O'Brien's table");
        $file = \sys_get_temp_dir() . '/razy_contract_migration_' . \bin2hex(\random_bytes(4)) . '.php';
        \file_put_contents($file, $source);

        try {
            $migration = require $file;

            $this->assertInstanceOf(Migration::class, $migration);
            $this->assertSame("O'Brien's table", $migration->getDescription(), 'escaping round-trips through require');
        } finally {
            @\unlink($file);
        }

        $this->assertStringContainsString("\$table->addColumn('id=type(int),auto');", $source);
        $this->assertStringContainsString('groupIndexing([\'email\'], \'idx_email\')', $source);
        $this->assertStringContainsString("dropIfExists('users')", $source);
    }
}
