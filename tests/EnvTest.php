<?php

/**
 * This file is part of Razy v0.5.
 *
 * Comprehensive tests for P14: .env Support (Env class).
 *
 * Tests the Env parser, variable loading, interpolation, escape sequences,
 * multiline values, casting, error handling, and the env() helper function.
 *
 * @package Razy
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Env;
use RuntimeException;

#[CoversClass(Env::class)]
class EnvTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        Env::reset();
        $this->tempDir = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'razy_env_test_' . \bin2hex(\random_bytes(4));
        \mkdir($this->tempDir, 0o700, true);
    }

    protected function tearDown(): void
    {
        Env::reset();

        // Clean up temp files
        if (\is_dir($this->tempDir)) {
            $files = \glob($this->tempDir . DIRECTORY_SEPARATOR . '*');
            foreach ($files as $file) {
                \unlink($file);
            }
            \rmdir($this->tempDir);
        }

        // Clean up test env vars we set
        foreach (['APP_NAME', 'APP_ENV', 'APP_DEBUG', 'APP_URL', 'DB_HOST', 'DB_PORT',
                   'DB_NAME', 'DB_USER', 'DB_PASS', 'SECRET', 'EMPTY_VAR', 'SPACED',
                   'SINGLE_Q', 'DOUBLE_Q', 'MULTI', 'REF_VAR', 'BASE_URL', 'API_URL',
                   'GREETING', 'FOO', 'BAR', 'BAZ', 'EXPORTED', 'MY_VAR', 'RESULT',
                   'ESCAPED', 'NULL_VAR', 'FALSE_VAR', 'TRUE_VAR', 'EMPTY_CAST',
                   'NESTED_A', 'NESTED_B', 'NESTED_C', 'TEST_KEY', 'EXISTING',
                   'NEW_VAR', 'REQUIRED_VAR', 'LINE1', 'MULTILINE',
                   'WITH_NEWLINE', 'WITH_TAB', 'WITH_QUOTE', 'WITH_BACKSLASH',
                   'WITH_DOLLAR', 'RAZY_ENV_TEST_SENTINEL'] as $var) {
            \putenv($var);
            unset($_ENV[$var], $_SERVER[$var]);
        }
    }

    public static function castingProvider(): array
    {
        return [
            'true' => ['true', true],
            'TRUE' => ['TRUE', true],
            'True' => ['True', true],
            '(true)' => ['(true)', true],
            'false' => ['false', false],
            'FALSE' => ['FALSE', false],
            '(false)' => ['(false)', false],
            'null' => ['null', null],
            'NULL' => ['NULL', null],
            '(null)' => ['(null)', null],
            'empty' => ['empty', ''],
            '(empty)' => ['(empty)', ''],
            'regular' => ['hello', 'hello'],
            'numeric' => ['42', '42'],
        ];
    }

    // ══════════════════════════════════════════════════════════════
    //  DataProvider — Variable Name Validation
    // ══════════════════════════════════════════════════════════════

    public static function validNameProvider(): array
    {
        return [
            'simple' => ['FOO'],
            'lowercase' => ['foo'],
            'mixed' => ['FooBar'],
            'underscore' => ['_PRIVATE'],
            'with_numbers' => ['VAR_123'],
            'all_under' => ['___'],
        ];
    }

    public static function invalidNameProvider(): array
    {
        return [
            'starts with number' => ['1FOO'],
            'has hyphen' => ['MY-VAR'],
            'has dot' => ['MY.VAR'],
            'has space' => ['MY VAR'],
            'has special' => ['MY@VAR'],
        ];
    }

    // ══════════════════════════════════════════════════════════════
    //  Parse — Basic Key=Value
    // ══════════════════════════════════════════════════════════════

    public function testParseSimpleKeyValue(): void
    {
        $result = Env::parse("APP_NAME=Razy\nAPP_ENV=production");

        $this->assertSame([
            'APP_NAME' => 'Razy',
            'APP_ENV' => 'production',
        ], $result);
    }

    public function testParseEmptyValue(): void
    {
        $result = Env::parse('EMPTY_VAR=');

        $this->assertSame(['EMPTY_VAR' => ''], $result);
    }

    public function testParseSkipsEmptyLines(): void
    {
        $content = "FOO=bar\n\n\nBAZ=qux\n";
        $result = Env::parse($content);

        $this->assertSame(['FOO' => 'bar', 'BAZ' => 'qux'], $result);
    }

    public function testParseSkipsComments(): void
    {
        $content = "# Database settings\nDB_HOST=localhost\n# Port\nDB_PORT=3306";
        $result = Env::parse($content);

        $this->assertSame(['DB_HOST' => 'localhost', 'DB_PORT' => '3306'], $result);
    }

    public function testParseStripsInlineComments(): void
    {
        $result = Env::parse('APP_ENV=production # the environment');

        $this->assertSame(['APP_ENV' => 'production'], $result);
    }

    public function testParseTrimsKeyWhitespace(): void
    {
        $result = Env::parse('  APP_NAME  = Razy');

        $this->assertSame(['APP_NAME' => 'Razy'], $result);
    }

    public function testParseHandlesWindowsLineEndings(): void
    {
        $result = Env::parse("FOO=bar\r\nBAZ=qux\r\n");

        $this->assertSame(['FOO' => 'bar', 'BAZ' => 'qux'], $result);
    }

    public function testParseHandlesOldMacLineEndings(): void
    {
        $result = Env::parse("FOO=bar\rBAZ=qux\r");

        $this->assertSame(['FOO' => 'bar', 'BAZ' => 'qux'], $result);
    }

    // ══════════════════════════════════════════════════════════════
    //  Parse — Quoted Values
    // ══════════════════════════════════════════════════════════════

    public function testParseSingleQuotedLiteral(): void
    {
        $result = Env::parse("SECRET='my secret value'");

        $this->assertSame(['SECRET' => 'my secret value'], $result);
    }

    public function testParseSingleQuotedPreservesSpecialChars(): void
    {
        $result = Env::parse("SECRET='hello \$HOME \\n world'");

        // Single quotes: no interpolation, no escape processing
        $this->assertSame(['SECRET' => 'hello $HOME \n world'], $result);
    }

    public function testParseDoubleQuotedBasic(): void
    {
        $result = Env::parse('APP_NAME="My Application"');

        $this->assertSame(['APP_NAME' => 'My Application'], $result);
    }

    public function testParseDoubleQuotedWithHashIsNotComment(): void
    {
        $result = Env::parse('APP_NAME="value # not a comment"');

        $this->assertSame(['APP_NAME' => 'value # not a comment'], $result);
    }

    public function testParseSingleQuotedWithHashIsNotComment(): void
    {
        $result = Env::parse("APP_NAME='value # not a comment'");

        $this->assertSame(['APP_NAME' => 'value # not a comment'], $result);
    }

    // ══════════════════════════════════════════════════════════════
    //  Parse — Escape Sequences (Double-Quoted)
    // ══════════════════════════════════════════════════════════════

    public function testParseDoubleQuotedEscapeNewline(): void
    {
        $result = Env::parse('GREETING="hello\nworld"');

        $this->assertSame(['GREETING' => "hello\nworld"], $result);
    }

    public function testParseDoubleQuotedEscapeTab(): void
    {
        $result = Env::parse('WITH_TAB="col1\tcol2"');

        $this->assertSame(['WITH_TAB' => "col1\tcol2"], $result);
    }

    public function testParseDoubleQuotedEscapeQuote(): void
    {
        $result = Env::parse('WITH_QUOTE="say \"hello\""');

        $this->assertSame(['WITH_QUOTE' => 'say "hello"'], $result);
    }

    public function testParseDoubleQuotedEscapeBackslash(): void
    {
        $result = Env::parse('WITH_BACKSLASH="C:\\\Users\\\test"');

        $this->assertSame(['WITH_BACKSLASH' => 'C:\Users\test'], $result);
    }

    public function testParseDoubleQuotedEscapeDollar(): void
    {
        $result = Env::parse('WITH_DOLLAR="price is \$5"');

        $this->assertSame(['WITH_DOLLAR' => 'price is $5'], $result);
    }

    public function testParseDoubleQuotedEscapeReturn(): void
    {
        $result = Env::parse('RESULT="line\r"');

        $this->assertSame(['RESULT' => "line\r"], $result);
    }

    // ══════════════════════════════════════════════════════════════
    //  Parse — Variable Interpolation
    // ══════════════════════════════════════════════════════════════

    public function testParseInterpolateDollarBrace(): void
    {
        $content = "BASE_URL=http://localhost\nAPI_URL=\"\${BASE_URL}/api\"";
        $result = Env::parse($content);

        $this->assertSame('http://localhost/api', $result['API_URL']);
    }

    public function testParseInterpolateDollarSign(): void
    {
        $content = "BASE_URL=http://localhost\nAPI_URL=\"\$BASE_URL/api\"";
        $result = Env::parse($content);

        $this->assertSame('http://localhost/api', $result['API_URL']);
    }

    public function testParseInterpolateUnquotedValue(): void
    {
        $content = "FOO=hello\nBAR=\$FOO-world";
        $result = Env::parse($content);

        $this->assertSame('hello-world', $result['BAR']);
    }

    public function testParseInterpolateChained(): void
    {
        $content = "FOO=alpha\nBAR=\${FOO}-beta\nBAZ=\${BAR}-gamma";
        $result = Env::parse($content);

        $this->assertSame('alpha-beta-gamma', $result['BAZ']);
    }

    public function testParseInterpolateUndefinedVarEmpty(): void
    {
        $result = Env::parse('FOO="${DOES_NOT_EXIST}_suffix"');

        $this->assertSame('_suffix', $result['FOO']);
    }

    public function testParseSingleQuotedNoInterpolation(): void
    {
        $content = "BASE=http://localhost\nREF='\${BASE}/api'";
        $result = Env::parse($content);

        // Single-quoted: literal, no interpolation
        $this->assertSame('${BASE}/api', $result['REF']);
    }

    // ══════════════════════════════════════════════════════════════
    //  Parse — Multiline Double-Quoted Values
    // ══════════════════════════════════════════════════════════════

    public function testParseMultilineDoubleQuoted(): void
    {
        $content = "MULTILINE=\"line one\nline two\nline three\"";
        $result = Env::parse($content);

        $this->assertSame("line one\nline two\nline three", $result['MULTILINE']);
    }

    public function testParseMultilineWithFollowingVar(): void
    {
        $content = "MULTI=\"hello\nworld\"\nFOO=bar";
        $result = Env::parse($content);

        $this->assertSame("hello\nworld", $result['MULTI']);
        $this->assertSame('bar', $result['FOO']);
    }

    // ══════════════════════════════════════════════════════════════
    //  Parse — Export Prefix
    // ══════════════════════════════════════════════════════════════

    public function testParseExportPrefix(): void
    {
        $result = Env::parse("export EXPORTED=yes\nexport FOO=bar");

        $this->assertSame(['EXPORTED' => 'yes', 'FOO' => 'bar'], $result);
    }

    public function testParseMixedExportAndRegular(): void
    {
        $result = Env::parse("APP_NAME=Razy\nexport APP_ENV=local");

        $this->assertSame(['APP_NAME' => 'Razy', 'APP_ENV' => 'local'], $result);
    }

    // ══════════════════════════════════════════════════════════════
    //  Parse — Error Handling
    // ══════════════════════════════════════════════════════════════

    public function testParseMissingEqualsThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("missing '='");

        Env::parse('NO_EQUALS_HERE');
    }

    public function testParseInvalidVarNameThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid environment variable name');

        Env::parse('123INVALID=value');
    }

    public function testParseInvalidVarNameHyphenThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Env::parse('MY-VAR=value');
    }

    public function testParseUnterminatedSingleQuoteThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unterminated single-quoted');

        Env::parse("FOO='unterminated");
    }

    public function testParseUnterminatedDoubleQuoteThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unterminated double-quoted');

        Env::parse('FOO="unterminated');
    }

    // ══════════════════════════════════════════════════════════════
    //  Load — File Reading
    // ══════════════════════════════════════════════════════════════

    public function testLoadSetsEnvironmentVariables(): void
    {
        $path = $this->writeEnvFile("APP_NAME=Razy\nAPP_ENV=testing");

        Env::load($path);

        $this->assertSame('Razy', Env::get('APP_NAME'));
        $this->assertSame('testing', Env::get('APP_ENV'));
        $this->assertTrue(Env::isInitialized());
    }

    public function testLoadPopulatesEnvSuperglobal(): void
    {
        $path = $this->writeEnvFile('MY_VAR=hello');

        Env::load($path);

        $this->assertSame('hello', $_ENV['MY_VAR']);
    }

    public function testLoadPopulatesPutenv(): void
    {
        $path = $this->writeEnvFile('MY_VAR=world');

        Env::load($path);

        $this->assertSame('world', \getenv('MY_VAR'));
    }

    public function testLoadMissingFileThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not found or not readable');

        Env::load('/nonexistent/path/.env');
    }

    public function testLoadDoesNotOverwriteByDefault(): void
    {
        // Set an existing value
        \putenv('EXISTING=original');
        $_ENV['EXISTING'] = 'original';

        $path = $this->writeEnvFile('EXISTING=new_value');

        Env::load($path);

        // Should keep the original
        $this->assertSame('original', \getenv('EXISTING'));
    }

    public function testLoadOverwriteWhenEnabled(): void
    {
        \putenv('EXISTING=original');
        $_ENV['EXISTING'] = 'original';

        $path = $this->writeEnvFile('EXISTING=new_value');

        Env::load($path, overwrite: true);

        $this->assertSame('new_value', Env::get('EXISTING', cast: false));
    }

    // ══════════════════════════════════════════════════════════════
    //  LoadIfExists
    // ══════════════════════════════════════════════════════════════

    public function testLoadIfExistsReturnsTrueWhenFound(): void
    {
        $path = $this->writeEnvFile('FOO=bar');

        $this->assertTrue(Env::loadIfExists($path));
        $this->assertSame('bar', Env::get('FOO'));
    }

    public function testLoadIfExistsReturnsFalseWhenMissing(): void
    {
        $this->assertFalse(Env::loadIfExists('/nonexistent/.env'));
        $this->assertFalse(Env::isInitialized());
    }

    // ══════════════════════════════════════════════════════════════
    //  Get — Retrieval & Casting
    // ══════════════════════════════════════════════════════════════

    public function testGetReturnsDefaultWhenNotSet(): void
    {
        $this->assertSame('fallback', Env::get('UNDEFINED_VAR', 'fallback'));
    }

    public function testGetReturnsNullDefaultWhenNotSet(): void
    {
        $this->assertNull(Env::get('UNDEFINED_VAR'));
    }

    #[DataProvider('castingProvider')]
    public function testGetCastsSpecialValues(string $raw, mixed $expected): void
    {
        $path = $this->writeEnvFile("TEST_KEY={$raw}");
        Env::load($path);

        $result = Env::get('TEST_KEY');
        $this->assertSame($expected, $result);
    }

    public function testGetNoCastReturnsRawString(): void
    {
        $path = $this->writeEnvFile('TRUE_VAR=true');
        Env::load($path);

        $this->assertSame('true', Env::get('TRUE_VAR', cast: false));
    }

    // ══════════════════════════════════════════════════════════════
    //  Has
    // ══════════════════════════════════════════════════════════════

    public function testHasReturnsTrueForLoadedVar(): void
    {
        $path = $this->writeEnvFile('FOO=bar');
        Env::load($path);

        $this->assertTrue(Env::has('FOO'));
    }

    public function testHasReturnsFalseForUndefined(): void
    {
        $this->assertFalse(Env::has('TOTALLY_NONEXISTENT_VAR_XYZ'));
    }

    public function testHasReturnsTrueForPutenvVar(): void
    {
        \putenv('RAZY_ENV_TEST_SENTINEL=1');

        $this->assertTrue(Env::has('RAZY_ENV_TEST_SENTINEL'));
    }

    // ══════════════════════════════════════════════════════════════
    //  Set
    // ══════════════════════════════════════════════════════════════

    public function testSetCreatesVariable(): void
    {
        Env::set('NEW_VAR', 'new_value');

        $this->assertSame('new_value', Env::get('NEW_VAR', cast: false));
        $this->assertSame('new_value', $_ENV['NEW_VAR']);
        $this->assertSame('new_value', \getenv('NEW_VAR'));
    }

    public function testSetOverwritesExisting(): void
    {
        Env::set('NEW_VAR', 'first');
        Env::set('NEW_VAR', 'second');

        $this->assertSame('second', Env::get('NEW_VAR', cast: false));
    }

    // ══════════════════════════════════════════════════════════════
    //  All
    // ══════════════════════════════════════════════════════════════

    public function testAllReturnsLoadedVars(): void
    {
        $path = $this->writeEnvFile("FOO=bar\nBAZ=qux");
        Env::load($path);

        $all = Env::all();
        $this->assertArrayHasKey('FOO', $all);
        $this->assertArrayHasKey('BAZ', $all);
        $this->assertSame('bar', $all['FOO']);
        $this->assertSame('qux', $all['BAZ']);
    }

    public function testAllEmptyBeforeLoad(): void
    {
        $this->assertSame([], Env::all());
    }

    // ══════════════════════════════════════════════════════════════
    //  GetRequired
    // ══════════════════════════════════════════════════════════════

    public function testGetRequiredReturnsValue(): void
    {
        $path = $this->writeEnvFile('REQUIRED_VAR=important');
        Env::load($path);

        $this->assertSame('important', Env::getRequired('REQUIRED_VAR'));
    }

    public function testGetRequiredThrowsWhenMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Required environment variable 'MISSING_KEY' is not defined");

        Env::getRequired('MISSING_KEY');
    }

    public function testGetRequiredCasts(): void
    {
        $path = $this->writeEnvFile('APP_DEBUG=true');
        Env::load($path);

        $this->assertTrue(Env::getRequired('APP_DEBUG'));
    }

    public function testGetRequiredNoCast(): void
    {
        $path = $this->writeEnvFile('APP_DEBUG=true');
        Env::load($path);

        $this->assertSame('true', Env::getRequired('APP_DEBUG', cast: false));
    }

    // ══════════════════════════════════════════════════════════════
    //  Reset
    // ══════════════════════════════════════════════════════════════

    public function testResetClearsState(): void
    {
        $path = $this->writeEnvFile('FOO=bar');
        Env::load($path);

        $this->assertTrue(Env::isInitialized());
        $this->assertNotEmpty(Env::all());

        Env::reset();

        $this->assertFalse(Env::isInitialized());
        $this->assertSame([], Env::all());
    }

    // ══════════════════════════════════════════════════════════════
    //  Integration — Full .env File
    // ══════════════════════════════════════════════════════════════

    public function testFullEnvFile(): void
    {
        $content = <<<'ENV'
            # Application
            APP_NAME=Razy
            APP_ENV=production
            APP_DEBUG=false
            APP_URL=https://example.com

            # Database
            DB_HOST=localhost
            DB_PORT=3306
            DB_NAME=razy_db
            DB_USER=root
            DB_PASS='s3cr3t p@ss!'

            # API
            API_URL="${APP_URL}/api/v1"

            # Feature flags
            export EMPTY_VAR=
            ENV;

        $path = $this->writeEnvFile($content);
        Env::load($path);

        $this->assertSame('Razy', Env::get('APP_NAME'));
        $this->assertSame('production', Env::get('APP_ENV'));
        $this->assertFalse(Env::get('APP_DEBUG')); // cast
        $this->assertSame('https://example.com', Env::get('APP_URL'));
        $this->assertSame('localhost', Env::get('DB_HOST'));
        $this->assertSame('3306', Env::get('DB_PORT', cast: false));
        $this->assertSame('razy_db', Env::get('DB_NAME'));
        $this->assertSame('root', Env::get('DB_USER'));
        $this->assertSame('s3cr3t p@ss!', Env::get('DB_PASS'));
        $this->assertSame('https://example.com/api/v1', Env::get('API_URL'));
        $this->assertSame('', Env::get('EMPTY_VAR', 'default', false));
    }

    // ══════════════════════════════════════════════════════════════
    //  env() Helper Function
    // ══════════════════════════════════════════════════════════════

    public function testEnvHelperReturnsValue(): void
    {
        $path = $this->writeEnvFile('APP_NAME=Razy');
        Env::load($path);

        $this->assertSame('Razy', \Razy\env('APP_NAME'));
    }

    public function testEnvHelperReturnsDefault(): void
    {
        $this->assertSame('fallback', \Razy\env('UNDEFINED_XYZ', 'fallback'));
    }

    public function testEnvHelperCastsValues(): void
    {
        $path = $this->writeEnvFile("DEBUG=true\nVERBOSE=false\nNULL_THING=null");
        Env::load($path);

        $this->assertTrue(\Razy\env('DEBUG'));
        $this->assertFalse(\Razy\env('VERBOSE'));
        $this->assertNull(\Razy\env('NULL_THING'));
    }

    // ══════════════════════════════════════════════════════════════
    //  Edge Cases
    // ══════════════════════════════════════════════════════════════

    public function testValueWithEqualsSign(): void
    {
        $result = Env::parse('DB_URL=mysql://user:pass@host/db?opt=val');

        $this->assertSame('mysql://user:pass@host/db?opt=val', $result['DB_URL']);
    }

    public function testEmptyFile(): void
    {
        $result = Env::parse('');

        $this->assertSame([], $result);
    }

    public function testOnlyComments(): void
    {
        $result = Env::parse("# comment 1\n# comment 2\n");

        $this->assertSame([], $result);
    }

    public function testUnderscoreInName(): void
    {
        $result = Env::parse('MY_LONG_VAR_NAME=value');

        $this->assertSame(['MY_LONG_VAR_NAME' => 'value'], $result);
    }

    public function testNumericValue(): void
    {
        $result = Env::parse('PORT=8080');

        $this->assertSame(['PORT' => '8080'], $result);
    }

    public function testDoubleQuotedEmptyString(): void
    {
        $result = Env::parse('EMPTY=""');

        $this->assertSame(['EMPTY' => ''], $result);
    }

    public function testSingleQuotedEmptyString(): void
    {
        $result = Env::parse("EMPTY=''");

        $this->assertSame(['EMPTY' => ''], $result);
    }

    public function testValueWithSpaces(): void
    {
        $result = Env::parse('SPACED="  hello  world  "');

        $this->assertSame(['SPACED' => '  hello  world  '], $result);
    }

    public function testParseReturnsLastValueForDuplicateKey(): void
    {
        $result = Env::parse("FOO=first\nFOO=second");

        $this->assertSame(['FOO' => 'second'], $result);
    }

    #[DataProvider('validNameProvider')]
    public function testValidVariableNames(string $name): void
    {
        $result = Env::parse("{$name}=value");

        $this->assertArrayHasKey($name, $result);
    }

    #[DataProvider('invalidNameProvider')]
    public function testInvalidVariableNamesThrow(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        Env::parse("{$name}=value");
    }

    private function writeEnvFile(string $content, string $name = '.env'): string
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . $name;
        \file_put_contents($path, $content);

        return $path;
    }
}
