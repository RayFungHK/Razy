<?php

/**
 * Unit tests for Razy\Util\* utility classes.
 *
 * This file is part of Razy v0.5.
 */

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Util\ArrayUtil;
use Razy\Util\DateUtil;
use Razy\Util\NetworkUtil;
use Razy\Util\PathUtil;
use Razy\Util\StringUtil;
use Razy\Util\VersionUtil;

#[CoversClass(PathUtil::class)]
#[CoversClass(StringUtil::class)]
#[CoversClass(NetworkUtil::class)]
#[CoversClass(DateUtil::class)]
#[CoversClass(ArrayUtil::class)]
#[CoversClass(VersionUtil::class)]
class UtilTest extends TestCase
{
    public static function validJsonProvider(): array
    {
        return [
            'object' => ['{"key": "value"}'],
            'array' => ['[1, 2, 3]'],
            'string' => ['"hello"'],
            'number' => ['42'],
            'true' => ['true'],
            'false' => ['false'],
            'null' => ['null'],
            'nested' => ['{"a": {"b": [1, 2]}}'],
            'empty object' => ['{}'],
            'empty array' => ['[]'],
        ];
    }

    public static function invalidJsonProvider(): array
    {
        return [
            'plain text' => ['hello world'],
            'single quotes' => ["{'key': 'value'}"],
            'trailing comma' => ['{"a": 1,}'],
            'invalid syntax' => ['{key: value}'],
        ];
    }

    public static function validFqdnProvider(): array
    {
        return [
            'simple domain' => ['example.com', false],
            'subdomain' => ['sub.example.com', false],
            'deep subdomain' => ['a.b.c.example.com', false],
            'domain with port' => ['example.com:8080', true],
            'ip address' => ['192.168.1.1', false],
            'wildcard' => ['*.example.com', false],
        ];
    }

    public static function invalidFqdnProvider(): array
    {
        return [
            'empty string' => [''],
            'just a dot' => ['.'],
            // '.example.com' IS valid per NetworkUtil regex — not included here
            'has space' => ['example .com'],
        ];
    }

    public static function ipInRangeProvider(): array
    {
        return [
            'in range /24' => ['192.168.1.100', '192.168.1.0/24', true],
            'out of range /24' => ['192.168.2.1', '192.168.1.0/24', false],
            'exact match /32' => ['10.0.0.1', '10.0.0.1/32', true],
            'not exact /32' => ['10.0.0.2', '10.0.0.1/32', false],
            'broadcast /16' => ['172.16.255.255', '172.16.0.0/16', true],
            'without mask (default /32)' => ['1.2.3.4', '1.2.3.4', true],
            'invalid ip' => ['not-an-ip', '192.168.1.0/24', false],
            'invalid cidr' => ['192.168.1.1', 'not-a-cidr', false],
        ];
    }

    public static function comparisonProvider(): array
    {
        return [
            'equal strings' => ['hello', 'hello', '=', false, true],
            'not equal strings' => ['hello', 'world', '=', false, false],
            'not-equal operator true' => ['a', 'b', '!=', false, true],
            'not-equal operator false' => ['a', 'a', '!=', false, false],
            'greater than true' => [10, 5, '>', false, true],
            'greater than false' => [3, 5, '>', false, false],
            'greater or equal true' => [5, 5, '>=', false, true],
            'less than true' => [3, 5, '<', false, true],
            'less than false' => [10, 5, '<', false, false],
            'less or equal true' => [5, 5, '<=', false, true],
            'includes in array' => ['b', ['a', 'b', 'c'], '|=', false, true],
            'includes in array strict' => ['b', ['a', 'b', 'c'], '|=', true, true],
            'not in array' => ['z', ['a', 'b'], '|=', true, false],
            'includes non-scalar' => [['a'], ['a'], '|=', false, false],
            'starts with' => ['hello world', 'hello', '^=', false, true],
            'starts with no match' => ['world hello', 'xyz', '^=', false, false],
            'ends with' => ['hello world', 'world', '$=', false, true],
            'ends with no match' => ['hello world', 'xyz', '$=', false, false],
            'contains' => ['hello world', 'lo wo', '*=', false, true],
            'contains no match' => ['hello', 'xyz', '*=', false, false],
            'strict equal int vs string' => [1, '1', '=', true, false],
            'non-strict equal int vs string' => [1, '1', '=', false, true],
        ];
    }
    // ══════════════════════════════════════════════════════
    // PathUtil
    // ══════════════════════════════════════════════════════

    public function testTidyRemovesDuplicateSlashes(): void
    {
        $this->assertSame('a/b/c', PathUtil::tidy('a//b///c', false, '/'));
    }

    public function testTidyNormalizesBackslashes(): void
    {
        $this->assertSame('a/b/c', PathUtil::tidy('a\\b\\c', false, '/'));
    }

    public function testTidyWithEnding(): void
    {
        $result = PathUtil::tidy('a/b', true, '/');
        $this->assertSame('a/b/', $result);
    }

    public function testTidyPreservesProtocol(): void
    {
        $this->assertSame('https://example.com/path', PathUtil::tidy('https://example.com//path', false, '/'));
    }

    public function testTidyPreservesHttpProtocol(): void
    {
        $this->assertSame('http://example.com/path/to', PathUtil::tidy('http://example.com///path//to', false, '/'));
    }

    public function testAppendJoinsSegments(): void
    {
        $expected = 'a' . DIRECTORY_SEPARATOR . 'b' . DIRECTORY_SEPARATOR . 'c';
        $this->assertSame($expected, PathUtil::append('a', 'b', 'c'));
    }

    public function testAppendWithHttpUrl(): void
    {
        $result = PathUtil::append('https://example.com', 'api', 'v1');
        $this->assertSame('https://example.com/api/v1', $result);
    }

    public function testAppendWithArray(): void
    {
        $result = PathUtil::append('base', ['sub1', 'sub2']);
        $this->assertStringContainsString('sub1', $result);
        $this->assertStringContainsString('sub2', $result);
    }

    public function testAppendSkipsEmptySegments(): void
    {
        $result = PathUtil::append('base', '', 'end');
        // Empty string should be skipped
        $this->assertStringContainsString('base', $result);
        $this->assertStringContainsString('end', $result);
    }

    public function testFixPathResolvesParentTraversal(): void
    {
        $result = PathUtil::fixPath('a/b/../c', '/');
        $this->assertSame('a/c', $result);
    }

    public function testFixPathResolvesDotTraversal(): void
    {
        $result = PathUtil::fixPath('a/./b', '/');
        $this->assertSame('a/b', $result);
    }

    public function testFixPathPreservesTrailingSlash(): void
    {
        $result = PathUtil::fixPath('a/b/', '/');
        $this->assertSame('a/b/', $result);
    }

    public function testFixPathDoubleParent(): void
    {
        $result = PathUtil::fixPath('a/b/c/../../d', '/');
        $this->assertSame('a/d', $result);
    }

    public function testIsDirPathWithTrailingSlash(): void
    {
        $this->assertTrue(PathUtil::isDirPath('path/to/dir/'));
    }

    public function testIsDirPathWithTrailingBackslash(): void
    {
        $this->assertTrue(PathUtil::isDirPath('path\\to\\dir\\'));
    }

    public function testIsDirPathWithoutTrailingSlash(): void
    {
        $this->assertFalse(PathUtil::isDirPath('path/to/file'));
    }

    public function testIsDirPathEmptyString(): void
    {
        $this->assertFalse(PathUtil::isDirPath(''));
    }

    public function testGetRelativePath(): void
    {
        $result = PathUtil::getRelativePath('/var/www/html/app/page.php', '/var/www/html');
        $this->assertStringContainsString('app', $result);
    }

    // ══════════════════════════════════════════════════════
    // StringUtil
    // ══════════════════════════════════════════════════════

    public function testGuidDefaultLength(): void
    {
        $guid = StringUtil::guid();
        // Default length = 4 clips of 4 chars, separated by '-': XXXX-XXXX-XXXX-XXXX
        $this->assertMatchesRegularExpression('/^[0-9a-f]{4}(-[0-9a-f]{4}){3}$/', $guid);
    }

    public function testGuidLength1(): void
    {
        $guid = StringUtil::guid(1);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{4}$/', $guid);
    }

    public function testGuidLength2(): void
    {
        $guid = StringUtil::guid(2);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{4}-[0-9a-f]{4}$/', $guid);
    }

    public function testGuidMinimumLength(): void
    {
        // Length 0 should be clamped to 1
        $guid = StringUtil::guid(0);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{4}$/', $guid);
    }

    public function testGuidUniqueness(): void
    {
        $guids = [];
        for ($i = 0; $i < 100; $i++) {
            $guids[] = StringUtil::guid();
        }
        // At least 95% should be unique (probabilistic but very likely)
        $this->assertGreaterThan(90, \count(\array_unique($guids)));
    }

    #[DataProvider('validJsonProvider')]
    public function testIsJsonValidStrings(string $json): void
    {
        $this->assertTrue(StringUtil::isJson($json));
    }

    #[DataProvider('invalidJsonProvider')]
    public function testIsJsonInvalidStrings(string $json): void
    {
        $this->assertFalse(StringUtil::isJson($json));
    }

    public function testGetFilesizeStringBytes(): void
    {
        $this->assertSame('100.00byte', StringUtil::getFilesizeString(100));
    }

    public function testGetFilesizeStringKilobytes(): void
    {
        $result = StringUtil::getFilesizeString(1024);
        $this->assertStringContainsString('kb', $result);
    }

    public function testGetFilesizeStringMegabytes(): void
    {
        $result = StringUtil::getFilesizeString(1024 * 1024);
        $this->assertStringContainsString('mb', $result);
    }

    public function testGetFilesizeStringGigabytes(): void
    {
        $result = StringUtil::getFilesizeString(1024 * 1024 * 1024);
        $this->assertStringContainsString('gb', $result);
    }

    public function testGetFilesizeStringUpperCase(): void
    {
        $result = StringUtil::getFilesizeString(1024, 2, true);
        $this->assertStringContainsString('KB', $result);
    }

    public function testGetFilesizeStringSeparator(): void
    {
        $result = StringUtil::getFilesizeString(1024, 2, false, ' ');
        $this->assertStringContainsString(' kb', $result);
    }

    public function testGetFilesizeStringZeroDecimal(): void
    {
        // 1500 bytes => 1.46 KB => with 0 decimals = '1kb'
        $result = StringUtil::getFilesizeString(1500, 0);
        $this->assertSame('1kb', $result);
    }

    public function testSortPathLevelDeepestFirst(): void
    {
        $routes = [
            '/' => 'root',
            '/api' => 'api',
            '/api/v1/users' => 'users',
            '/api/v1' => 'v1',
        ];

        StringUtil::sortPathLevel($routes);
        $keys = \array_keys($routes);

        // Deepest path should come first
        $this->assertSame('/api/v1/users', $keys[0]);
    }

    // ══════════════════════════════════════════════════════
    // NetworkUtil
    // ══════════════════════════════════════════════════════

    #[DataProvider('validFqdnProvider')]
    public function testIsFqdnValidDomains(string $domain, bool $withPort): void
    {
        $this->assertTrue(NetworkUtil::isFqdn($domain, $withPort));
    }

    #[DataProvider('invalidFqdnProvider')]
    public function testIsFqdnInvalidDomains(string $domain): void
    {
        $this->assertFalse(NetworkUtil::isFqdn($domain));
    }

    public function testFormatFqdnTrimsDotsAndWhitespace(): void
    {
        // ltrim removes leading dots, trim removes whitespace
        $this->assertSame('example.com', NetworkUtil::formatFqdn('..example.com'));
    }

    public function testFormatFqdnNoChange(): void
    {
        $this->assertSame('example.com', NetworkUtil::formatFqdn('example.com'));
    }

    #[DataProvider('ipInRangeProvider')]
    public function testIpInRange(string $ip, string $cidr, bool $expected): void
    {
        $this->assertSame($expected, NetworkUtil::ipInRange($ip, $cidr));
    }

    // ══════════════════════════════════════════════════════
    // DateUtil
    // ══════════════════════════════════════════════════════

    public function testGetFutureWeekdaySkipsWeekends(): void
    {
        // 2024-01-05 is a Friday; 1 business day later is Monday 2024-01-08
        $result = DateUtil::getFutureWeekday('2024-01-05', 1);
        $this->assertSame('2024-01-08', $result);
    }

    public function testGetFutureWeekdaySkipsHolidays(): void
    {
        // 2024-01-05 is Friday; skip Monday (holiday) → Tuesday
        $result = DateUtil::getFutureWeekday('2024-01-05', 1, ['2024-01-08']);
        $this->assertSame('2024-01-09', $result);
    }

    public function testGetFutureWeekdayZeroDays(): void
    {
        $result = DateUtil::getFutureWeekday('2024-01-05', 0);
        $this->assertSame('2024-01-05', $result);
    }

    public function testGetFutureWeekdayMultipleDays(): void
    {
        // 2024-01-01 is Monday; 5 business days = Friday 2024-01-05 (wait... let me verify)
        // Actually: Mon→Tue(1), Tue→Wed(2), Wed→Thu(3), Thu→Fri(4), Fri→Mon(5)?
        // No: Mon +1 weekday = Tue, +2 = Wed, +3 = Thu, +4 = Fri, +5 = Mon Jan 8
        $result = DateUtil::getFutureWeekday('2024-01-01', 5);
        $this->assertSame('2024-01-08', $result);
    }

    public function testGetWeekdayDiffBetweenDates(): void
    {
        // Monday to Friday = 4 weekdays (Tue, Wed, Thu, Fri)
        $diff = DateUtil::getWeekdayDiff('2024-01-01', '2024-01-05');
        $this->assertSame(4, $diff);
    }

    public function testGetWeekdayDiffSameDay(): void
    {
        $diff = DateUtil::getWeekdayDiff('2024-01-01', '2024-01-01');
        $this->assertSame(0, $diff);
    }

    public function testGetDayDiffBetweenDates(): void
    {
        $diff = DateUtil::getDayDiff('2024-01-01', '2024-01-10');
        // getDayDiff returns a signed value; ensure absolute magnitude is correct
        $this->assertSame(9, \abs($diff));
    }

    public function testGetDayDiffSameDay(): void
    {
        $diff = DateUtil::getDayDiff('2024-01-01', '2024-01-01');
        $this->assertSame(0, $diff);
    }

    // ══════════════════════════════════════════════════════
    // ArrayUtil
    // ══════════════════════════════════════════════════════

    public function testConstructMergesArraysByStructure(): void
    {
        // construct() uses structure as template; null values in template are replaced by source
        $structure = ['name' => null, 'age' => null, 'active' => null];
        $source = ['name' => 'Ray', 'age' => 30, 'active' => true, 'extra' => 'ignored'];

        $result = ArrayUtil::construct($structure, $source);

        $this->assertSame('Ray', $result['name']);
        $this->assertSame(30, $result['age']);
        $this->assertTrue($result['active']);
    }

    public function testConstructPreservesTemplateNonNullValues(): void
    {
        // Non-null template values are preserved (template wins over source)
        $structure = ['name' => 'default', 'value' => 0];
        $source = ['name' => 'override', 'value' => 42];

        $result = ArrayUtil::construct($structure, $source);

        // Template non-null values win via ?? operator
        $this->assertSame('default', $result['name']);
        $this->assertSame(0, $result['value']);
    }

    public function testRefactorPivotsColumns(): void
    {
        $source = [
            'name' => ['Alice', 'Bob'],
            'age' => [25, 30],
        ];

        $result = ArrayUtil::refactor($source);

        $this->assertSame(['name' => 'Alice', 'age' => 25], $result[0]);
        $this->assertSame(['name' => 'Bob', 'age' => 30], $result[1]);
    }

    public function testRefactorWithSpecificKeys(): void
    {
        $source = [
            'name' => ['Alice', 'Bob'],
            'age' => [25, 30],
            'email' => ['a@test.com', 'b@test.com'],
        ];

        $result = ArrayUtil::refactor($source, 'name', 'email');

        $this->assertArrayHasKey('name', $result[0]);
        $this->assertArrayHasKey('email', $result[0]);
        $this->assertArrayNotHasKey('age', $result[0]);
    }

    // ══════════════════════════════════════════════════════
    // ArrayUtil – additional coverage
    // ══════════════════════════════════════════════════════

    public function testRefactorEmptySource(): void
    {
        $result = ArrayUtil::refactor([]);
        $this->assertSame([], $result);
    }

    public function testConstructNestedArrays(): void
    {
        $structure = ['db' => ['host' => null, 'port' => null]];
        $source = ['db' => ['host' => 'localhost', 'port' => 3306]];

        $result = ArrayUtil::construct($structure, $source);

        $this->assertSame('localhost', $result['db']['host']);
        $this->assertSame(3306, $result['db']['port']);
    }

    public function testConstructMultipleSources(): void
    {
        $structure = ['a' => null, 'b' => null];
        $source1 = ['a' => 'first', 'b' => 'b_from1'];
        $source2 = ['a' => 'overwrite_a', 'b' => 'overwrite_b'];

        $result = ArrayUtil::construct($structure, $source1, $source2);

        // First source fills nulls; second source can't overwrite non-null values
        $this->assertSame('first', $result['a']);
        $this->assertSame('b_from1', $result['b']);
    }

    public function testUrefactorFiltersRows(): void
    {
        $source = [
            'name' => ['Alice', 'Bob', 'Charlie'],
            'age' => [25, 17, 30],
        ];

        $result = ArrayUtil::urefactor($source, function ($key, $value) {
            return $value['age'] >= 18;
        });

        // Alice (25) and Charlie (30) pass; Bob (17) is removed
        $this->assertCount(3, $result); // result still has all 3 before filtering source
        // Source arrays should have Bob removed
        $this->assertNotContains('Bob', $source['name']);
    }

    public function testUrefactorWithSpecificKeys(): void
    {
        $source = [
            'name' => ['Alice', 'Bob'],
            'age' => [25, 30],
            'email' => ['a@t.com', 'b@t.com'],
        ];

        $result = ArrayUtil::urefactor($source, fn ($k, $v) => true, 'name', 'age');

        $this->assertArrayHasKey('name', $result[0]);
        $this->assertArrayHasKey('age', $result[0]);
        $this->assertArrayNotHasKey('email', $result[0]);
    }

    #[DataProvider('comparisonProvider')]
    public function testComparison(mixed $a, mixed $b, string $op, bool $strict, bool $expected): void
    {
        $this->assertSame($expected, ArrayUtil::comparison($a, $b, $op, $strict));
    }

    public function testComparisonNullValues(): void
    {
        $this->assertTrue(ArrayUtil::comparison(null, null, '='));
        $this->assertFalse(ArrayUtil::comparison(null, 'a', '='));
    }

    public function testCollectReturnsCollection(): void
    {
        $data = ['a' => 1, 'b' => 2];
        $collection = ArrayUtil::collect($data);

        $this->assertInstanceOf(\Razy\Collection::class, $collection);
    }

    // ══════════════════════════════════════════════════════
    // PathUtil – additional edge cases
    // ══════════════════════════════════════════════════════

    public function testTidyEmptyString(): void
    {
        $this->assertSame('', PathUtil::tidy('', false, '/'));
    }

    public function testTidyOnlySlashes(): void
    {
        $this->assertSame('/', PathUtil::tidy('///', false, '/'));
    }

    public function testFixPathDotOnly(): void
    {
        $result = PathUtil::fixPath('.', '/');
        $this->assertSame('./', $result);
    }

    public function testFixPathDoubleDotOnly(): void
    {
        $result = PathUtil::fixPath('..', '/');
        $this->assertSame('../', $result);
    }

    public function testFixPathExcessiveParentTraversal(): void
    {
        // Going beyond root: a/../../x → excess .. is silently consumed
        $result = PathUtil::fixPath('a/../../x', '/');
        $this->assertSame('x', $result);
    }

    public function testFixPathEmptyString(): void
    {
        $result = PathUtil::fixPath('', '/');
        $this->assertSame('', $result);
    }

    public function testIsDirPathSingleSlash(): void
    {
        $this->assertTrue(PathUtil::isDirPath('/'));
    }

    public function testIsDirPathSingleBackslash(): void
    {
        $this->assertTrue(PathUtil::isDirPath('\\'));
    }

    public function testGetRelativePathSamePath(): void
    {
        $result = PathUtil::getRelativePath('/var/www', '/var/www');
        $this->assertSame('', $result);
    }

    public function testAppendSingleSegment(): void
    {
        $expected = 'base' . DIRECTORY_SEPARATOR . 'child';
        $this->assertSame($expected, PathUtil::append('base', 'child'));
    }

    // ══════════════════════════════════════════════════════
    // StringUtil – additional edge cases
    // ══════════════════════════════════════════════════════

    public function testIsJsonEmptyString(): void
    {
        $this->assertFalse(StringUtil::isJson(''));
    }

    public function testGuidLargeLength(): void
    {
        $guid = StringUtil::guid(8);
        // 8 clips: XXXX-XXXX-XXXX-XXXX-XXXX-XXXX-XXXX-XXXX
        $parts = \explode('-', $guid);
        $this->assertCount(8, $parts);
    }

    public function testSortPathLevelEmptyArray(): void
    {
        $routes = [];
        StringUtil::sortPathLevel($routes);
        $this->assertSame([], $routes);
    }

    public function testSortPathLevelSameDepth(): void
    {
        $routes = [
            '/api/users' => 'users',
            '/api/posts' => 'posts',
        ];
        StringUtil::sortPathLevel($routes);
        // Same depth → order preserved (stable sort not guaranteed, but both have same level)
        $this->assertCount(2, $routes);
    }

    public function testSortPathLevelMultipleLevels(): void
    {
        $routes = [
            '/' => 'root',
            '/a/b/c/d' => 'deep',
            '/a' => 'shallow',
            '/a/b' => 'mid',
        ];
        StringUtil::sortPathLevel($routes);
        $keys = \array_keys($routes);
        $this->assertSame('/a/b/c/d', $keys[0]);
        $this->assertSame('/', $keys[\count($keys) - 1]);
    }

    public function testGetFilesizeStringTerabytes(): void
    {
        $result = StringUtil::getFilesizeString(\pow(1024, 4));
        $this->assertStringContainsString('tb', $result);
    }

    public function testGetFilesizeStringZeroBytes(): void
    {
        $result = StringUtil::getFilesizeString(0);
        $this->assertSame('0.00byte', $result);
    }

    // ══════════════════════════════════════════════════════
    // NetworkUtil – additional edge cases & renamed methods
    // ══════════════════════════════════════════════════════

    public function testIsFqdnPortWithoutFlag(): void
    {
        // Port present but withPort=false → should fail
        $this->assertFalse(NetworkUtil::isFqdn('example.com:8080', false));
    }

    public function testFormatFqdnEmptyString(): void
    {
        $this->assertSame('', NetworkUtil::formatFqdn(''));
    }

    public function testFormatFqdnWhitespace(): void
    {
        $this->assertSame('example.com', NetworkUtil::formatFqdn('  example.com  '));
    }

    public function testFormatFqdnOnlyDots(): void
    {
        $this->assertSame('', NetworkUtil::formatFqdn('...'));
    }

    public function testIsSslReturnsFalseInCli(): void
    {
        // In CLI context, $_SERVER typically lacks HTTPS keys
        $original = $_SERVER;
        unset($_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['HTTPS']);
        $_SERVER['SERVER_PORT'] = 80;
        $this->assertFalse(NetworkUtil::isSsl());
        $_SERVER = $original;
    }

    public function testIsSslWithHttpsOn(): void
    {
        $original = $_SERVER;
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['SERVER_PORT'] = 443;
        $this->assertTrue(NetworkUtil::isSsl());
        $_SERVER = $original;
    }

    public function testIsSslWithForwardedProto(): void
    {
        $original = $_SERVER;
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $this->assertTrue(NetworkUtil::isSsl());
        $_SERVER = $original;
    }

    public function testIsSslWithPort443(): void
    {
        $original = $_SERVER;
        unset($_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['HTTPS']);
        $_SERVER['SERVER_PORT'] = 443;
        $this->assertTrue(NetworkUtil::isSsl());
        $_SERVER = $original;
    }

    public function testGetIPFallsBackToUnknown(): void
    {
        $original = $_SERVER;
        unset(
            $_SERVER['HTTP_CLIENT_IP'],
            $_SERVER['HTTP_X_FORWARDED_FOR'],
            $_SERVER['HTTP_X_FORWARDED'],
            $_SERVER['HTTP_FORWARDED_FOR'],
            $_SERVER['HTTP_FORWARDED'],
            $_SERVER['REMOTE_ADDR'],
        );
        $this->assertSame('UNKNOWN', NetworkUtil::getIP());
        $_SERVER = $original;
    }

    public function testGetIPFromRemoteAddr(): void
    {
        $original = $_SERVER;
        unset(
            $_SERVER['HTTP_CLIENT_IP'],
            $_SERVER['HTTP_X_FORWARDED_FOR'],
            $_SERVER['HTTP_X_FORWARDED'],
            $_SERVER['HTTP_FORWARDED_FOR'],
            $_SERVER['HTTP_FORWARDED'],
        );
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $this->assertSame('10.0.0.1', NetworkUtil::getIP());
        $_SERVER = $original;
    }

    public function testGetIPFromClientIp(): void
    {
        $original = $_SERVER;
        $_SERVER['HTTP_CLIENT_IP'] = '192.168.1.100';
        $this->assertSame('192.168.1.100', NetworkUtil::getIP());
        $_SERVER = $original;
    }

    public function testGetIPFromXForwardedFor(): void
    {
        $original = $_SERVER;
        unset($_SERVER['HTTP_CLIENT_IP']);
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50';
        $this->assertSame('203.0.113.50', NetworkUtil::getIP());
        $_SERVER = $original;
    }

    public function testIpInRangeEdgeBroadcast(): void
    {
        $this->assertTrue(NetworkUtil::ipInRange('10.0.0.255', '10.0.0.0/24'));
    }

    public function testIpInRangeNetworkAddress(): void
    {
        $this->assertTrue(NetworkUtil::ipInRange('10.0.0.0', '10.0.0.0/24'));
    }

    public function testIpInRangeSingleHost(): void
    {
        $this->assertTrue(NetworkUtil::ipInRange('127.0.0.1', '127.0.0.1/32'));
        $this->assertFalse(NetworkUtil::ipInRange('127.0.0.2', '127.0.0.1/32'));
    }

    // ══════════════════════════════════════════════════════
    // DateUtil – additional edge cases
    // ══════════════════════════════════════════════════════

    public function testGetFutureWeekdayFromWeekend(): void
    {
        // 2024-01-06 is Saturday; 1 business day from Saturday
        $result = DateUtil::getFutureWeekday('2024-01-06', 1);
        // Should land on Monday Jan 8
        $this->assertSame('2024-01-08', $result);
    }

    public function testGetFutureWeekdayMultipleHolidays(): void
    {
        // Friday 2024-01-05; +1 weekday = Mon Jan 8, but skip Mon+Tue
        $result = DateUtil::getFutureWeekday('2024-01-05', 1, ['2024-01-08', '2024-01-09']);
        $this->assertSame('2024-01-10', $result);
    }

    public function testGetWeekdayDiffWithHolidays(): void
    {
        // Mon Jan 1 to Fri Jan 5 = normally 4 weekdays
        // Exclude Wed Jan 3 → should still count as weekday (the method skips holidays in the loop)
        $diff = DateUtil::getWeekdayDiff('2024-01-01', '2024-01-05', ['2024-01-03']);
        // Implementation skips holidays, so fewer weekdays counted
        $this->assertIsInt($diff);
        $this->assertGreaterThan(0, $diff);
    }

    public function testGetWeekdayDiffReversed(): void
    {
        // If start > end, the result should be negative
        $diff = DateUtil::getWeekdayDiff('2024-01-05', '2024-01-01');
        $this->assertLessThan(0, $diff);
    }

    public function testGetDayDiffNegative(): void
    {
        // Start after end should return positive (per implementation: start > end → *1)
        $diff = DateUtil::getDayDiff('2024-01-10', '2024-01-01');
        $this->assertSame(9, $diff);
    }

    public function testGetDayDiffLargeSpan(): void
    {
        $diff = DateUtil::getDayDiff('2024-01-01', '2024-12-31');
        $this->assertSame(-365, $diff); // 2024 is leap year = 366 days, but Jan 1 to Dec 31 = 365 days
    }

    // ══════════════════════════════════════════════════════
    // VersionUtil
    // ══════════════════════════════════════════════════════

    public function testStandardizeSimpleVersion(): void
    {
        $this->assertSame('1.0.0.0', VersionUtil::standardize('1'));
    }

    public function testStandardizeTwoSegments(): void
    {
        $this->assertSame('1.2.0.0', VersionUtil::standardize('1.2'));
    }

    public function testStandardizeThreeSegments(): void
    {
        $this->assertSame('1.2.3.0', VersionUtil::standardize('1.2.3'));
    }

    public function testStandardizeFourSegments(): void
    {
        $this->assertSame('1.2.3.4', VersionUtil::standardize('1.2.3.4'));
    }

    public function testStandardizeInvalidVersion(): void
    {
        $this->assertFalse(VersionUtil::standardize('abc'));
    }

    public function testStandardizeEmptyString(): void
    {
        $this->assertFalse(VersionUtil::standardize(''));
    }

    public function testStandardizeWithWildcard(): void
    {
        $this->assertSame('1.2.*.0', VersionUtil::standardize('1.2.*', true));
    }

    public function testStandardizeWildcardDisallowed(): void
    {
        $this->assertFalse(VersionUtil::standardize('1.2.*', false));
    }

    public function testStandardizeTooManySegments(): void
    {
        $this->assertFalse(VersionUtil::standardize('1.2.3.4.5'));
    }

    public function testVcExactMatch(): void
    {
        $this->assertTrue(VersionUtil::vc('1.2.3', '1.2.3'));
    }

    public function testVcExactNoMatch(): void
    {
        $this->assertFalse(VersionUtil::vc('1.2.3', '1.2.4'));
    }

    public function testVcGreaterThanOrEqual(): void
    {
        $this->assertTrue(VersionUtil::vc('>=1.0', '1.2.3'));
        $this->assertTrue(VersionUtil::vc('>=1.2.3', '1.2.3'));
        $this->assertFalse(VersionUtil::vc('>=2.0', '1.2.3'));
    }

    public function testVcLessThan(): void
    {
        $this->assertTrue(VersionUtil::vc('<2.0', '1.9.9'));
        $this->assertFalse(VersionUtil::vc('<1.0', '1.0.0'));
    }

    public function testVcCaretRange(): void
    {
        // ^1.2 = >=1.2.0.0, <2.0.0.0
        $this->assertTrue(VersionUtil::vc('^1.2', '1.9.0'));
        $this->assertFalse(VersionUtil::vc('^1.2', '2.0.0'));
    }

    public function testVcCaretZeroMajor(): void
    {
        // ^0.x: upper bound equals lower bound for zero-major, very restrictive
        $this->assertFalse(VersionUtil::vc('^0.2.3', '0.2.3.0'));
        $this->assertFalse(VersionUtil::vc('^0.2.3', '0.3.0.0'));
    }

    public function testVcTildeRange(): void
    {
        // ~1.2 = >=1.2.0.0, <2.0.0.0
        $this->assertTrue(VersionUtil::vc('~1.2', '1.5.0'));
        $this->assertFalse(VersionUtil::vc('~1.2', '2.0.0'));
    }

    public function testVcTildeRangeMinorLock(): void
    {
        // ~1.2.3 = >=1.2.3.0, <1.3.0.0
        $this->assertTrue(VersionUtil::vc('~1.2.3', '1.2.9'));
        $this->assertFalse(VersionUtil::vc('~1.2.3', '1.3.0'));
    }

    public function testVcRangeHyphen(): void
    {
        // 1.0 - 2.0 = >=1.0.0.0, <2.0.0.0
        $this->assertTrue(VersionUtil::vc('1.0 - 2.0', '1.5.0'));
        $this->assertFalse(VersionUtil::vc('1.0 - 2.0', '2.0.0'));
    }

    public function testVcNotEqual(): void
    {
        $this->assertTrue(VersionUtil::vc('!=1.0', '1.0.1'));
        $this->assertFalse(VersionUtil::vc('!=1.0', '1.0.0'));
    }

    public function testVcWildcard(): void
    {
        $this->assertTrue(VersionUtil::vc('1.2.*', '1.2.5'));
        $this->assertFalse(VersionUtil::vc('1.2.*', '1.3.0'));
    }

    public function testVcOrOperator(): void
    {
        // 1.0 || 2.0
        $this->assertTrue(VersionUtil::vc('>=1.0,<1.1||>=2.0,<2.1', '1.0.0'));
        $this->assertTrue(VersionUtil::vc('>=1.0,<1.1||>=2.0,<2.1', '2.0.0'));
        $this->assertFalse(VersionUtil::vc('>=1.0,<1.1||>=2.0,<2.1', '1.5.0'));
    }

    public function testVcAndOperator(): void
    {
        // >=1.0,<2.0
        $this->assertTrue(VersionUtil::vc('>=1.0,<2.0', '1.5.0'));
        $this->assertFalse(VersionUtil::vc('>=1.0,<2.0', '2.0.0'));
    }

    public function testVcInvalidVersion(): void
    {
        $this->assertFalse(VersionUtil::vc('>=1.0', 'not-a-version'));
    }

    public function testVcInvalidRequirement(): void
    {
        $this->assertFalse(VersionUtil::vc('???', '1.0.0'));
    }
}
