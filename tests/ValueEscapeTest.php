<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Database;
use Razy\Database\Statement;

/**
 * Tests for Statement::getValueAsStatement() with special characters.
 *
 * Validates SQL-safe escaping via PDO::quote() for edge-case inputs
 * including single quotes, double quotes, backslashes, null bytes,
 * and multibyte (UTF-8) characters.
 *
 * @covers \Razy\Database\Statement
 */
#[CoversClass(Statement::class)]
class ValueEscapeTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = new Database('test_value_escape');
        $this->db->connectWithDriver('sqlite', ['path' => ':memory:']);
    }

    // ─── DataProvider: Comprehensive Special Characters ──────────

    /**
     * @return array<string, array{string, string}>
     */
    public static function specialCharactersProvider(): array
    {
        return [
            'single quote' => ["'", 'single quote'],
            'double quote' => ['"', 'double quote'],
            'backslash' => ['\\', 'backslash'],
            'null byte' => ["\0", 'null byte'],
            'tab' => ["\t", 'tab'],
            'newline' => ["\n", 'newline'],
            'carriage return' => ["\r", 'carriage return'],
            'percent (LIKE)' => ['%', 'percent'],
            'underscore (LIKE)' => ['_', 'underscore'],
            'semicolon' => [';', 'semicolon'],
            'dash dash comment' => ['--', 'dash dash'],
            'hash comment' => ['#', 'hash'],
            'slash star comment' => ['/*', 'slash star'],
            'CJK ideograph' => ['漢', 'CJK'],
            'combining diacritical' => ["e\u{0301}", 'combining accent'],
            'zero-width space' => ["\u{200B}", 'zero-width space'],
            'right-to-left mark' => ["\u{200F}", 'RTL mark'],
        ];
    }

    // ─── Null / Boolean / Numeric ────────────────────────────────

    public function testNullReturnsNullLiteral(): void
    {
        $this->assertSame('NULL', $this->quoteValue(null));
    }

    public function testTrueReturnsOne(): void
    {
        // getValueAsStatement returns (int)$value which is coerced to string
        $this->assertEquals('1', $this->quoteValue(true));
    }

    public function testFalseReturnsZero(): void
    {
        $this->assertEquals('0', $this->quoteValue(false));
    }

    public function testIntegerPassthrough(): void
    {
        $result = $this->quoteValue(42);
        $this->assertEquals(42, $result);
    }

    public function testFloatPassthrough(): void
    {
        $result = $this->quoteValue(3.14);
        $this->assertEquals(3.14, $result);
    }

    // ─── Normal Strings ──────────────────────────────────────────

    public function testPlainString(): void
    {
        $result = $this->quoteValue('hello');
        $this->assertIsString($result);
        $this->assertStringContainsString('hello', $result);
        // Must be wrapped in quotes
        $this->assertMatchesRegularExpression("/^'.+'$/", $result);
    }

    public function testEmptyString(): void
    {
        $result = $this->quoteValue('');
        $this->assertIsString($result);
        // PDO::quote('') returns "''" in SQLite
        $this->assertSame("''", $result);
    }

    // ─── Special Character Escaping ──────────────────────────────

    public function testSingleQuoteEscaping(): void
    {
        $result = $this->quoteValue("it's");
        $this->assertIsString($result);
        // The single quote must be escaped (SQLite doubles it: 'it''s')
        $this->assertStringNotContainsString("it's'", $result);
        $this->assertMatchesRegularExpression("/^'.+'$/s", $result);
    }

    public function testDoubleSingleQuotes(): void
    {
        $result = $this->quoteValue("it''s");
        $this->assertIsString($result);
        $this->assertMatchesRegularExpression("/^'.+'$/s", $result);
    }

    public function testDoubleQuoteEscaping(): void
    {
        $result = $this->quoteValue('say "hello"');
        $this->assertIsString($result);
        $this->assertStringContainsString('"hello"', $result);
    }

    public function testBackslashEscaping(): void
    {
        $result = $this->quoteValue('path\to\file');
        $this->assertIsString($result);
        $this->assertStringContainsString('\\', $result);
    }

    public function testNullByteEscaping(): void
    {
        $result = $this->quoteValue("before\0after");
        $this->assertIsString($result);
        // PDO::quote() may truncate at null byte (SQLite returns '')
        // The key safety property: it must still be a valid SQL literal
        $this->assertMatchesRegularExpression("/^'.*'$/s", $result);
    }

    public function testNewlineEscaping(): void
    {
        $result = $this->quoteValue("line1\nline2");
        $this->assertIsString($result);
        $this->assertMatchesRegularExpression("/^'.+'$/s", $result);
    }

    public function testCarriageReturnEscaping(): void
    {
        $result = $this->quoteValue("line1\rline2");
        $this->assertIsString($result);
        $this->assertMatchesRegularExpression("/^'.+'$/s", $result);
    }

    public function testTabEscaping(): void
    {
        $result = $this->quoteValue("col1\tcol2");
        $this->assertIsString($result);
        $this->assertMatchesRegularExpression("/^'.+'$/s", $result);
    }

    // ─── Multibyte / Unicode ─────────────────────────────────────

    public function testChineseCharacters(): void
    {
        $result = $this->quoteValue('你好世界');
        $this->assertIsString($result);
        $this->assertStringContainsString('你好世界', $result);
    }

    public function testJapaneseCharacters(): void
    {
        $result = $this->quoteValue('こんにちは');
        $this->assertIsString($result);
        $this->assertStringContainsString('こんにちは', $result);
    }

    public function testEmojiCharacters(): void
    {
        $result = $this->quoteValue('Hello 🌍🎉');
        $this->assertIsString($result);
        $this->assertStringContainsString('🌍🎉', $result);
    }

    public function testMixedMultibyteAndSpecialChars(): void
    {
        $result = $this->quoteValue("日本語 'test' \\path");
        $this->assertIsString($result);
        $this->assertMatchesRegularExpression("/^'.+'$/s", $result);
    }

    // ─── SQL Injection Prevention ────────────────────────────────

    public function testSQLInjectionSingleQuote(): void
    {
        $result = $this->quoteValue("'; DROP TABLE users; --");
        $this->assertIsString($result);
        // The result must be a single quoted literal, not breakable SQL
        $this->assertMatchesRegularExpression("/^'.+'$/s", $result);
        // In SQLite, single quotes are doubled — so the injected ' becomes ''
        // Verify the leading single quote is escaped (doubled)
        $this->assertStringContainsString("''", $result);
    }

    public function testSQLInjectionUnionSelect(): void
    {
        $result = $this->quoteValue("' UNION SELECT * FROM passwords --");
        $this->assertIsString($result);
        $this->assertMatchesRegularExpression("/^'.+'$/s", $result);
    }

    // ─── Array → JSON ────────────────────────────────────────────

    public function testArrayConvertedToJson(): void
    {
        $result = $this->quoteValue(['key' => 'value', 'num' => 42]);
        $this->assertIsString($result);
        // Should contain the JSON-encoded representation, quoted
        $this->assertStringContainsString('key', $result);
        $this->assertStringContainsString('value', $result);
    }

    public function testArrayWithSpecialCharsInValues(): void
    {
        $result = $this->quoteValue(['name' => "O'Brien", 'path' => 'C:\dir']);
        $this->assertIsString($result);
        $this->assertMatchesRegularExpression("/^'.+'$/s", $result);
    }

    // ─── Edge Cases ──────────────────────────────────────────────

    public function testVeryLongString(): void
    {
        $long = \str_repeat('a', 10000);
        $result = $this->quoteValue($long);
        $this->assertIsString($result);
        $this->assertGreaterThan(10000, \strlen($result));
    }

    public function testStringOfOnlySingleQuotes(): void
    {
        $result = $this->quoteValue("'''");
        $this->assertIsString($result);
        $this->assertMatchesRegularExpression("/^'.+'$/s", $result);
    }

    public function testStringOfOnlyBackslashes(): void
    {
        $result = $this->quoteValue('\\\\\\');
        $this->assertIsString($result);
        $this->assertMatchesRegularExpression("/^'.+'$/s", $result);
    }

    #[DataProvider('specialCharactersProvider')]
    public function testSpecialCharacterIsProperlyQuoted(string $char, string $description): void
    {
        $result = $this->quoteValue($char);
        $this->assertIsString($result, "Failed for: {$description}");
        // Every quoted result must be wrapped in outer single quotes
        // (null byte may yield empty-interior '' due to PDO truncation)
        $this->assertMatchesRegularExpression("/^'.*'$/s", $result, "Result not properly quoted for: {$description}");
    }

    // ─── Helper ──────────────────────────────────────────────────

    /**
     * Create a Statement with a single parameter assigned and return
     * the SQL literal representation.
     */
    private function quoteValue(mixed $value): string
    {
        $stmt = new Statement($this->db);
        $stmt->assign(['col' => $value]);

        return $stmt->getValueAsStatement('col');
    }
}
