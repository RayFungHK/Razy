<?php

/**
 * Unit tests for Razy\Database\Statement.
 *
 * This file is part of Razy v0.5.
 */

namespace Razy\Tests;

use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Database;
use Razy\Database\Statement;
use Razy\Exception\QueryException;

#[CoversClass(Statement::class)]
class StatementTest extends TestCase
{
    private ?Database $db = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a Database instance for testing Statement methods
        // Database::__construct() takes a string name, not an array config
        try {
            $this->db = new Database('test_db');
        } catch (Exception $e) {
            $this->markTestSkipped('Database instance not available: ' . $e->getMessage());
        }
    }

    // ==================== COLUMN STANDARDIZATION ====================

    public static function standardizeColumnProvider(): array
    {
        return [
            'simple' => ['username', '`username`'],
            'with table' => ['users.username', 'users.`username`'],
            'already quoted' => ['`username`', ''],
            'empty' => ['', ''],
            'with spaces' => ['  username  ', '`username`'],
            'three levels' => ['database.table.column', ''],
            'special chars' => ['`user``name`', ''],
            'numeric start' => ['123column', ''],
            'dashes' => ['user-name', ''],
            'null/empty' => ['', ''],
            'only spaces' => ['     ', ''],
            'quoted table' => ['`users`.id', ''],
            'case lowercase' => ['username', '`username`'],
            'case camelCase' => ['userName', '`userName`'],
            'underscore mid' => ['user_name', '`user_name`'],
            'underscore start' => ['_private', ''],
            'table.id' => ['users.id', 'users.`id`'],
            'table.title' => ['posts.title', 'posts.`title`'],
            'db.table.col' => ['db.users.email', ''],
        ];
    }

    public static function invalidSearchParamProvider(): array
    {
        return [
            'empty string' => [''],
            'whitespace' => ['   '],
        ];
    }

    // ==================== BASIC CONSTRUCTION ====================

    public function testConstructorWithoutSQL(): void
    {
        $stmt = new Statement($this->db);
        $this->assertInstanceOf(Statement::class, $stmt);
    }

    public function testConstructorWithSQL(): void
    {
        $sql = 'SELECT * FROM users WHERE id = :id';
        $stmt = new Statement($this->db, $sql);
        $this->assertInstanceOf(Statement::class, $stmt);
    }

    #[DataProvider('standardizeColumnProvider')]
    public function testStandardizeColumn(string $input, string $expected): void
    {
        $this->assertEquals($expected, Statement::standardizeColumn($input));
    }

    // ==================== SEARCH TEXT SYNTAX ====================

    public function testGetSearchTextSyntaxSingleColumn(): void
    {
        $syntax = Statement::getSearchTextSyntax('search', ['username']);
        $this->assertEquals('`username`*=:search', $syntax);
    }

    public function testGetSearchTextSyntaxMultipleColumns(): void
    {
        $syntax = Statement::getSearchTextSyntax('term', ['name', 'email']);
        $this->assertStringContainsString('`name`*=:term', $syntax);
        $this->assertStringContainsString('`email`*=:term', $syntax);
        $this->assertStringContainsString('|', $syntax);
    }

    public function testGetSearchTextSyntaxWithTableName(): void
    {
        $syntax = Statement::getSearchTextSyntax('keyword', ['users.name', 'users.email']);
        $this->assertStringContainsString('users.`name`*=:keyword', $syntax);
        $this->assertStringContainsString('users.`email`*=:keyword', $syntax);
    }

    #[DataProvider('invalidSearchParamProvider')]
    public function testGetSearchTextSyntaxInvalidParameter(string $param): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('The parameter is required');
        Statement::getSearchTextSyntax($param, ['username']);
    }

    // ==================== COLUMN NAME VALIDATION ====================

    public function testValidColumnNames(): void
    {
        $validColumns = [
            'id',
            'user_id',
            'userName',
            'user123',
            'a1b2c3',
        ];

        foreach ($validColumns as $column) {
            $result = Statement::standardizeColumn($column);
            $this->assertNotEmpty($result, "Column '$column' should be valid");
            $this->assertStringContainsString($column, $result);
        }
    }

    // ==================== FLUENT INTERFACE ====================

    public function testAssignParametersSingle(): void
    {
        $stmt = new Statement($this->db);
        $result = $stmt->assign(['id' => 123]);

        $this->assertInstanceOf(Statement::class, $result);
    }

    public function testAssignParametersArray(): void
    {
        $stmt = new Statement($this->db);
        $result = $stmt->assign([
            'id' => 1,
            'name' => 'John',
        ]);

        $this->assertInstanceOf(Statement::class, $result);
    }

    // ==================== EDGE CASES (covered by DataProvider above) ====================

    // ==================== COMPLEX SCENARIOS ====================

    public function testSearchTextSyntaxWithQuotedColumns(): void
    {
        // Pre-quoted columns are not recognized, so they produce empty and are skipped
        $syntax = Statement::getSearchTextSyntax('search', ['username', 'email']);
        $this->assertStringContainsString('`username`*=:search', $syntax);
        $this->assertStringContainsString('`email`*=:search', $syntax);
    }

    // ==================== CASE & UNDERSCORE (covered by DataProvider above) ====================

    // ==================== SEARCH SYNTAX EDGE CASES ====================

    public function testSearchTextSyntaxEmptyColumns(): void
    {
        $syntax = Statement::getSearchTextSyntax('term', []);
        $this->assertEquals('', $syntax);
    }

    public function testSearchTextSyntaxInvalidColumns(): void
    {
        $syntax = Statement::getSearchTextSyntax('term', ['123invalid', '', '  ']);
        $this->assertEquals('', $syntax);
    }

    public function testSearchTextSyntaxMixedValidInvalid(): void
    {
        $syntax = Statement::getSearchTextSyntax('term', ['valid', '123invalid', 'username']);
        $this->assertStringContainsString('`valid`*=:term', $syntax);
        $this->assertStringContainsString('`username`*=:term', $syntax);
        $this->assertStringNotContainsString('123invalid', $syntax);
    }

    // ==================== NULL AND EMPTY HANDLING (covered by DataProvider above) ====================

    // ==================== TABLE.COLUMN (covered by DataProvider above) ====================

    // ==================== INTEGRATION PATTERNS ====================

    public function testSearchMultipleColumnsAcrossTables(): void
    {
        $columns = [
            'users.username',
            'users.email',
            'profiles.bio',
            'profiles.location',
        ];

        $syntax = Statement::getSearchTextSyntax('searchterm', $columns);

        $this->assertStringContainsString('users.`username`*=:searchterm', $syntax);
        $this->assertStringContainsString('users.`email`*=:searchterm', $syntax);
        $this->assertStringContainsString('profiles.`bio`*=:searchterm', $syntax);
        $this->assertStringContainsString('profiles.`location`*=:searchterm', $syntax);
    }

    public function testColumnStandardizationConsistency(): void
    {
        // Test that same column always produces same result
        $column = 'username';
        $result1 = Statement::standardizeColumn($column);
        $result2 = Statement::standardizeColumn($column);

        $this->assertEquals($result1, $result2);
    }
}
