<?php

/**
 * Unit tests for Razy\Database\Table\TableHelper and Razy\Database\Table\ColumnHelper.
 *
 * This file is part of Razy v0.5.
 */

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Database\Table;
use Razy\Database\Table\ColumnHelper;
use Razy\Database\Table\TableHelper;
use Razy\Exception\DatabaseException;

#[CoversClass(TableHelper::class)]
#[CoversClass(ColumnHelper::class)]
class TableHelperTest extends TestCase
{
    private ?Table $table = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->table = new Table('users');
    }

    // ==================== TABLE HELPER CLASS TESTS ====================

    public function testHelperConstruction(): void
    {
        $helper = new TableHelper($this->table);
        $this->assertInstanceOf(TableHelper::class, $helper);
        $this->assertSame($this->table, $helper->getTable());
    }

    public function testCreateHelperFromTable(): void
    {
        $helper = $this->table->createHelper();
        $this->assertInstanceOf(TableHelper::class, $helper);
    }

    public function testRenameTable(): void
    {
        $helper = new TableHelper($this->table);
        $result = $helper->rename('customers');

        $this->assertSame($helper, $result); // Fluent interface
        $sql = $helper->getSyntax();
        $this->assertStringContainsString('RENAME TO `customers`', $sql);
    }

    public function testChangeCharset(): void
    {
        $helper = new TableHelper($this->table);
        $helper->charset('utf8mb4');

        $sql = $helper->getSyntax();
        $this->assertStringContainsString('CHARACTER SET utf8mb4', $sql);
    }

    public function testChangeCollation(): void
    {
        $helper = new TableHelper($this->table);
        $helper->collation('utf8mb4_unicode_ci');

        $sql = $helper->getSyntax();
        $this->assertStringContainsString('COLLATE utf8mb4_unicode_ci', $sql);
    }

    public function testChangeEngine(): void
    {
        $helper = new TableHelper($this->table);
        $helper->engine('InnoDB');

        $sql = $helper->getSyntax();
        $this->assertStringContainsString('ENGINE = InnoDB', $sql);
    }

    public function testAddComment(): void
    {
        $helper = new TableHelper($this->table);
        $helper->comment('User accounts table');

        $sql = $helper->getSyntax();
        $this->assertStringContainsString("COMMENT = 'User accounts table'", $sql);
    }

    // ==================== COLUMN OPERATIONS ====================

    public function testAddColumn(): void
    {
        $helper = new TableHelper($this->table);
        $column = $helper->addColumn('email=type(text),nullable');

        $this->assertInstanceOf(\Razy\Database\Column::class, $column);
        $sql = $helper->getSyntax();
        $this->assertStringContainsString('ADD COLUMN', $sql);
        $this->assertStringContainsString('`email`', $sql);
    }

    public function testAddColumnFirst(): void
    {
        $helper = new TableHelper($this->table);
        $helper->addColumn('id=type(auto)', 'FIRST');

        $sql = $helper->getSyntax();
        $this->assertStringContainsString('FIRST', $sql);
    }

    public function testAddColumnAfter(): void
    {
        $helper = new TableHelper($this->table);
        $helper->addColumn('email=type(text)', 'AFTER username');

        $sql = $helper->getSyntax();
        $this->assertStringContainsString('AFTER `USERNAME`', $sql);
    }

    public function testModifyColumn(): void
    {
        $helper = new TableHelper($this->table);
        $column = $helper->modifyColumn('name', 'type(text),length(500)');

        $this->assertInstanceOf(\Razy\Database\Column::class, $column);
        $sql = $helper->getSyntax();
        $this->assertStringContainsString('MODIFY COLUMN', $sql);
    }

    public function testRenameColumn(): void
    {
        $helper = new TableHelper($this->table);
        $helper->renameColumn('old_name', 'new_name');

        $sql = $helper->getSyntax();
        $this->assertStringContainsString('CHANGE COLUMN `old_name`', $sql);
        $this->assertStringContainsString('`new_name`', $sql);
    }

    public function testDropColumn(): void
    {
        $helper = new TableHelper($this->table);
        $result = $helper->dropColumn('deprecated_field');

        $this->assertSame($helper, $result);
        $sql = $helper->getSyntax();
        $this->assertStringContainsString('DROP COLUMN `deprecated_field`', $sql);
    }

    public function testDropMultipleColumns(): void
    {
        $helper = new TableHelper($this->table);
        $helper->dropColumn('field1')
              ->dropColumn('field2');

        $sql = $helper->getSyntax();
        $this->assertStringContainsString('DROP COLUMN `field1`', $sql);
        $this->assertStringContainsString('DROP COLUMN `field2`', $sql);
    }

    // ==================== INDEX OPERATIONS ====================

    public function testAddIndex(): void
    {
        $helper = new TableHelper($this->table);
        $helper->addIndex('INDEX', 'email', 'idx_email');

        $sql = $helper->getSyntax();
        $this->assertStringContainsString('ADD INDEX `idx_email`', $sql);
        $this->assertStringContainsString('(`email`)', $sql);
    }

    public function testAddCompositeIndex(): void
    {
        $helper = new TableHelper($this->table);
        $helper->addIndex('INDEX', ['first_name', 'last_name'], 'idx_name');

        $sql = $helper->getSyntax();
        $this->assertStringContainsString('(`first_name`, `last_name`)', $sql);
    }

    public function testAddPrimaryKey(): void
    {
        $helper = new TableHelper($this->table);
        $helper->addPrimaryKey('id');

        $sql = $helper->getSyntax();
        $this->assertStringContainsString('ADD PRIMARY KEY (`id`)', $sql);
    }

    public function testAddUniqueIndex(): void
    {
        $helper = new TableHelper($this->table);
        $helper->addUniqueIndex('email', 'uniq_email');

        $sql = $helper->getSyntax();
        $this->assertStringContainsString('ADD UNIQUE `uniq_email`', $sql);
    }

    public function testAddFulltextIndex(): void
    {
        $helper = new TableHelper($this->table);
        $helper->addFulltextIndex('content', 'ft_content');

        $sql = $helper->getSyntax();
        $this->assertStringContainsString('ADD FULLTEXT `ft_content`', $sql);
    }

    public function testDropIndex(): void
    {
        $helper = new TableHelper($this->table);
        $helper->dropIndex('idx_old');

        $sql = $helper->getSyntax();
        $this->assertStringContainsString('DROP INDEX `idx_old`', $sql);
    }

    public function testDropPrimaryKey(): void
    {
        $helper = new TableHelper($this->table);
        $helper->dropPrimaryKey();

        $sql = $helper->getSyntax();
        $this->assertStringContainsString('DROP PRIMARY KEY', $sql);
    }

    // ==================== FOREIGN KEY OPERATIONS ====================

    public function testAddForeignKey(): void
    {
        $helper = new TableHelper($this->table);
        $helper->addForeignKey('user_id', 'users', 'id');

        $sql = $helper->getSyntax();
        $this->assertStringContainsString('ADD CONSTRAINT', $sql);
        $this->assertStringContainsString('FOREIGN KEY (`user_id`)', $sql);
        $this->assertStringContainsString('REFERENCES `users` (`id`)', $sql);
    }

    public function testAddForeignKeyWithCascade(): void
    {
        $helper = new TableHelper($this->table);
        $helper->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');

        $sql = $helper->getSyntax();
        $this->assertStringContainsString('ON DELETE CASCADE', $sql);
        $this->assertStringContainsString('ON UPDATE CASCADE', $sql);
    }

    public function testAddForeignKeyWithSetNull(): void
    {
        $helper = new TableHelper($this->table);
        $helper->addForeignKey('user_id', 'users', 'id', 'SET NULL', 'NO ACTION');

        $sql = $helper->getSyntax();
        $this->assertStringContainsString('ON DELETE SET NULL', $sql);
        $this->assertStringContainsString('ON UPDATE NO ACTION', $sql);
    }

    public function testDropForeignKey(): void
    {
        $helper = new TableHelper($this->table);
        $helper->dropForeignKey('fk_user_id');

        $sql = $helper->getSyntax();
        $this->assertStringContainsString('DROP FOREIGN KEY `fk_user_id`', $sql);
    }

    // ==================== UTILITY METHODS ====================

    public function testHasPendingChangesEmpty(): void
    {
        $helper = new TableHelper($this->table);
        $this->assertFalse($helper->hasPendingChanges());
    }

    public function testHasPendingChangesWithColumn(): void
    {
        $helper = new TableHelper($this->table);
        $helper->addColumn('new_col=type(int)');
        $this->assertTrue($helper->hasPendingChanges());
    }

    public function testReset(): void
    {
        $helper = new TableHelper($this->table);
        $helper->addColumn('col1=type(int)');
        $helper->dropColumn('col2');
        $helper->addIndex('INDEX', 'col1', 'idx_col1');

        $this->assertTrue($helper->hasPendingChanges());

        $helper->reset();
        $this->assertFalse($helper->hasPendingChanges());
    }

    public function testGetSyntaxEmpty(): void
    {
        $helper = new TableHelper($this->table);
        $sql = $helper->getSyntax();
        $this->assertEquals('', $sql);
    }

    public function testGetSyntaxArray(): void
    {
        $helper = new TableHelper($this->table);
        $helper->addColumn('col1=type(int)');
        $helper->dropColumn('col2');

        $statements = $helper->getSyntaxArray();
        $this->assertIsArray($statements);
        $this->assertGreaterThanOrEqual(2, \count($statements));
    }

    public function testToString(): void
    {
        $helper = new TableHelper($this->table);
        $helper->rename('new_table');

        $sql = (string) $helper;
        $this->assertStringContainsString('ALTER TABLE', $sql);
    }

    // ==================== COMPLEX SCENARIOS ====================

    public function testComplexAlter(): void
    {
        $helper = new TableHelper($this->table);
        $helper->addColumn('email=type(text),nullable');
        $helper->modifyColumn('username', 'type(text),length(500)');
        $helper->dropColumn('deprecated');
        $helper->addUniqueIndex('email', 'uniq_email');
        $helper->addForeignKey('role_id', 'roles', 'id', 'CASCADE');

        $sql = $helper->getSyntax();

        $this->assertStringContainsString('ALTER TABLE `users`', $sql);
        $this->assertStringContainsString('ADD COLUMN', $sql);
        $this->assertStringContainsString('MODIFY COLUMN', $sql);
        $this->assertStringContainsString('DROP COLUMN', $sql);
        $this->assertStringContainsString('ADD UNIQUE', $sql);
        $this->assertStringContainsString('FOREIGN KEY', $sql);
    }

    // ==================== COLUMN ALTER CLASS TESTS ====================

    public function testColumnHelperConstruction(): void
    {
        $columnHelper = new ColumnHelper($this->table, 'username');
        $this->assertInstanceOf(ColumnHelper::class, $columnHelper);
        $this->assertEquals('username', $columnHelper->getColumnName());
    }

    public function testColumnHelperFromTable(): void
    {
        $columnHelper = $this->table->columnHelper('email');
        $this->assertInstanceOf(ColumnHelper::class, $columnHelper);
    }

    public function testColumnHelperRename(): void
    {
        $columnHelper = new ColumnHelper($this->table, 'old_name');
        $columnHelper->rename('new_name')->varchar(255);

        $sql = $columnHelper->getSyntax();
        $this->assertStringContainsString('CHANGE COLUMN `old_name`', $sql);
        $this->assertStringContainsString('`new_name`', $sql);
    }

    public function testColumnHelperVarchar(): void
    {
        $columnHelper = new ColumnHelper($this->table, 'name');
        $columnHelper->varchar(100);

        $sql = $columnHelper->getSyntax();
        $this->assertStringContainsString('VARCHAR(100)', $sql);
    }

    public function testColumnHelperInt(): void
    {
        $columnHelper = new ColumnHelper($this->table, 'count');
        $columnHelper->int(11);

        $sql = $columnHelper->getSyntax();
        $this->assertStringContainsString('INT(11)', $sql);
    }

    public function testColumnHelperBigint(): void
    {
        $columnHelper = new ColumnHelper($this->table, 'big_number');
        $columnHelper->bigint();

        $sql = $columnHelper->getSyntax();
        $this->assertStringContainsString('BIGINT(20)', $sql);
    }

    public function testColumnHelperDecimal(): void
    {
        $columnHelper = new ColumnHelper($this->table, 'price');
        $columnHelper->decimal(10, 2);

        $sql = $columnHelper->getSyntax();
        $this->assertStringContainsString('DECIMAL(10,2)', $sql);
    }

    public function testColumnHelperText(): void
    {
        $columnHelper = new ColumnHelper($this->table, 'content');
        $columnHelper->text();

        $sql = $columnHelper->getSyntax();
        $this->assertStringContainsString('TEXT', $sql);
    }

    public function testColumnHelperLongtext(): void
    {
        $columnHelper = new ColumnHelper($this->table, 'body');
        $columnHelper->longtext();

        $sql = $columnHelper->getSyntax();
        $this->assertStringContainsString('LONGTEXT', $sql);
    }

    public function testColumnHelperDatetime(): void
    {
        $columnHelper = new ColumnHelper($this->table, 'created_at');
        $columnHelper->datetime();

        $sql = $columnHelper->getSyntax();
        $this->assertStringContainsString('DATETIME', $sql);
    }

    public function testColumnHelperTimestamp(): void
    {
        $columnHelper = new ColumnHelper($this->table, 'updated_at');
        $columnHelper->timestamp();

        $sql = $columnHelper->getSyntax();
        $this->assertStringContainsString('TIMESTAMP', $sql);
    }

    public function testColumnHelperJson(): void
    {
        $columnHelper = new ColumnHelper($this->table, 'metadata');
        $columnHelper->json();

        $sql = $columnHelper->getSyntax();
        $this->assertStringContainsString('JSON', $sql);
    }

    public function testColumnHelperEnum(): void
    {
        $columnHelper = new ColumnHelper($this->table, 'status');
        $columnHelper->enum(['active', 'inactive', 'pending']);

        $sql = $columnHelper->getSyntax();
        $this->assertStringContainsString("ENUM('active','inactive','pending')", $sql);
    }

    public function testColumnHelperNullable(): void
    {
        $columnHelper = new ColumnHelper($this->table, 'optional');
        $columnHelper->varchar(255)->nullable();

        $sql = $columnHelper->getSyntax();
        $this->assertStringContainsString('NULL', $sql);
    }

    public function testColumnHelperNotNull(): void
    {
        $columnHelper = new ColumnHelper($this->table, 'required');
        $columnHelper->varchar(255)->notNull();

        $sql = $columnHelper->getSyntax();
        $this->assertStringContainsString('NOT NULL', $sql);
    }

    public function testColumnHelperDefault(): void
    {
        $columnHelper = new ColumnHelper($this->table, 'status');
        $columnHelper->varchar(50)->default('active');

        $sql = $columnHelper->getSyntax();
        $this->assertStringContainsString("DEFAULT 'active'", $sql);
    }

    public function testColumnHelperDefaultNull(): void
    {
        $columnHelper = new ColumnHelper($this->table, 'optional');
        $columnHelper->varchar(255)->default(null);

        $sql = $columnHelper->getSyntax();
        $this->assertStringContainsString('DEFAULT NULL', $sql);
    }

    public function testColumnHelperDefaultCurrentTimestamp(): void
    {
        $columnHelper = new ColumnHelper($this->table, 'created_at');
        $columnHelper->timestamp()->defaultCurrentTimestamp();

        $sql = $columnHelper->getSyntax();
        $this->assertStringContainsString('DEFAULT CURRENT_TIMESTAMP', $sql);
    }

    public function testColumnHelperCharset(): void
    {
        $columnHelper = new ColumnHelper($this->table, 'name');
        $columnHelper->varchar(255)->charset('utf8mb4');

        $sql = $columnHelper->getSyntax();
        $this->assertStringContainsString('CHARACTER SET utf8mb4', $sql);
    }

    public function testColumnHelperCollation(): void
    {
        $columnHelper = new ColumnHelper($this->table, 'name');
        $columnHelper->varchar(255)->collation('utf8mb4_unicode_ci');

        $sql = $columnHelper->getSyntax();
        $this->assertStringContainsString('COLLATE utf8mb4_unicode_ci', $sql);
    }

    public function testColumnHelperComment(): void
    {
        $columnHelper = new ColumnHelper($this->table, 'field');
        $columnHelper->varchar(255)->comment('This is a comment');

        $sql = $columnHelper->getSyntax();
        $this->assertStringContainsString("COMMENT 'This is a comment'", $sql);
    }

    public function testColumnHelperAutoIncrement(): void
    {
        $columnHelper = new ColumnHelper($this->table, 'id');
        $columnHelper->int(11)->autoIncrement();

        $sql = $columnHelper->getSyntax();
        $this->assertStringContainsString('AUTO_INCREMENT', $sql);
    }

    public function testColumnHelperFirst(): void
    {
        $columnHelper = new ColumnHelper($this->table, 'new_first');
        $columnHelper->int(11)->first();

        $sql = $columnHelper->getSyntax();
        $this->assertStringContainsString('FIRST', $sql);
    }

    public function testColumnHelperAfter(): void
    {
        $columnHelper = new ColumnHelper($this->table, 'new_col');
        $columnHelper->varchar(255)->after('id');

        $sql = $columnHelper->getSyntax();
        $this->assertStringContainsString('AFTER `id`', $sql);
    }

    public function testColumnHelperComplex(): void
    {
        $columnHelper = new ColumnHelper($this->table, 'email');
        $columnHelper->varchar(320)
                    ->notNull()
                    ->default('')
                    ->charset('utf8mb4')
                    ->collation('utf8mb4_unicode_ci')
                    ->comment('User email address')
                    ->after('username');

        $sql = $columnHelper->getSyntax();

        $this->assertStringContainsString('VARCHAR(320)', $sql);
        $this->assertStringContainsString('NOT NULL', $sql);
        $this->assertStringContainsString("DEFAULT ''", $sql);
        $this->assertStringContainsString('CHARACTER SET utf8mb4', $sql);
        $this->assertStringContainsString('COLLATE utf8mb4_unicode_ci', $sql);
        $this->assertStringContainsString("COMMENT 'User email address'", $sql);
        $this->assertStringContainsString('AFTER `username`', $sql);
    }

    public function testColumnHelperToString(): void
    {
        $columnHelper = new ColumnHelper($this->table, 'name');
        $columnHelper->varchar(100);

        $sql = (string) $columnHelper;
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('MODIFY COLUMN', $sql);
    }

    // ==================== ERROR HANDLING ====================

    public function testAddColumnInvalidSyntax(): void
    {
        $this->expectException(DatabaseException::class);

        $helper = new TableHelper($this->table);
        $helper->addColumn('');
    }

    public function testAddColumnDuplicate(): void
    {
        $this->expectException(DatabaseException::class);

        $helper = new TableHelper($this->table);
        $helper->addColumn('email=type(text)');
        $helper->addColumn('email=type(text)');
    }

    public function testAddIndexInvalidType(): void
    {
        $this->expectException(DatabaseException::class);

        $helper = new TableHelper($this->table);
        $helper->addIndex('INVALID_TYPE', 'column');
    }

    public function testAddIndexEmptyColumns(): void
    {
        $this->expectException(DatabaseException::class);

        $helper = new TableHelper($this->table);
        $helper->addIndex('INDEX', []);
    }

    public function testAddForeignKeyMissingTable(): void
    {
        $this->expectException(DatabaseException::class);

        $helper = new TableHelper($this->table);
        $helper->addForeignKey('user_id', '');
    }
}
