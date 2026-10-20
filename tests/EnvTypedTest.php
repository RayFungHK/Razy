<?php

/**
 * This file is part of Razy.
 *
 * RZ-08: typed environment getters and one-shot required-key checks.
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Env;
use Razy\Exception\ConfigurationException;

#[CoversClass(Env::class)]
class EnvTypedTest extends TestCase
{
    private const KEYS = ['RZ08_STR', 'RZ08_INT', 'RZ08_BOOL', 'RZ08_LIST', 'RZ08_MISSING_A', 'RZ08_MISSING_B'];

    protected function setUp(): void
    {
        Env::reset();
        foreach (self::KEYS as $key) {
            \putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    protected function tearDown(): void
    {
        $this->setUp();
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function boolProvider(): array
    {
        return [
            'true' => ['true', true], 'TRUE' => ['TRUE', true], '1' => ['1', true],
            'yes' => ['yes', true], 'on' => ['on', true],
            'false' => ['false', false], '0' => ['0', false], 'no' => ['no', false],
            'off' => ['Off', false], 'empty' => ['', false],
        ];
    }

    public function testStringReturnsValueOrDefault(): void
    {
        Env::set('RZ08_STR', 'hello');
        $this->assertSame('hello', Env::string('RZ08_STR'));
        $this->assertSame('fallback', Env::string('RZ08_MISSING_A', 'fallback'));
    }

    public function testStringDoesNotCastLiterals(): void
    {
        Env::set('RZ08_STR', 'false');
        $this->assertSame('false', Env::string('RZ08_STR'));
    }

    public function testMissingRequiredStringThrowsConfigurationException(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('RZ08_MISSING_A');
        Env::string('RZ08_MISSING_A');
    }

    public function testInt(): void
    {
        Env::set('RZ08_INT', ' 8080 ');
        $this->assertSame(8080, Env::int('RZ08_INT'));
        Env::set('RZ08_INT', '-3');
        $this->assertSame(-3, Env::int('RZ08_INT'));
        $this->assertSame(5, Env::int('RZ08_MISSING_A', 5));
    }

    public function testIntRejectsValuesOutsidePhpRange(): void
    {
        Env::set('RZ08_INT', '99999999999999999999');
        $this->expectException(ConfigurationException::class);
        Env::int('RZ08_INT');
    }

    public function testIntRejectsNonInteger(): void
    {
        Env::set('RZ08_INT', '80a');
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('RZ08_INT');
        Env::int('RZ08_INT');
    }

    #[DataProvider('boolProvider')]
    public function testBool(string $raw, bool $expected): void
    {
        Env::set('RZ08_BOOL', $raw);
        $this->assertSame($expected, Env::bool('RZ08_BOOL'));
    }

    public function testBoolRejectsGarbageAndUsesDefault(): void
    {
        $this->assertTrue(Env::bool('RZ08_MISSING_A', true));
        Env::set('RZ08_BOOL', 'maybe');
        $this->expectException(ConfigurationException::class);
        Env::bool('RZ08_BOOL');
    }

    public function testList(): void
    {
        Env::set('RZ08_LIST', ' a.example, b.example ,,c ');
        $this->assertSame(['a.example', 'b.example', 'c'], Env::list('RZ08_LIST'));
        $this->assertSame([], Env::list('RZ08_MISSING_A'));
        Env::set('RZ08_LIST', 'x|y');
        $this->assertSame(['x', 'y'], Env::list('RZ08_LIST', [], '|'));
    }

    public function testRequireAllNamesEveryMissingKey(): void
    {
        Env::set('RZ08_STR', 'present');
        try {
            Env::requireAll(['RZ08_STR', 'RZ08_MISSING_A', 'RZ08_MISSING_B']);
            $this->fail('requireAll should throw');
        } catch (ConfigurationException $e) {
            $this->assertStringContainsString('RZ08_MISSING_A', $e->getMessage());
            $this->assertStringContainsString('RZ08_MISSING_B', $e->getMessage());
            $this->assertStringNotContainsString('RZ08_STR', $e->getMessage());
        }
    }

    public function testRequireAllTreatsEmptyAsMissing(): void
    {
        Env::set('RZ08_STR', '');
        $this->expectException(ConfigurationException::class);
        Env::requireAll(['RZ08_STR']);
    }

    public function testRequireAllPassesWhenEverythingIsSet(): void
    {
        Env::set('RZ08_STR', 'a');
        Env::set('RZ08_INT', '1');
        Env::requireAll(['RZ08_STR', 'RZ08_INT']);
        $this->addToAssertionCount(1);
    }
}
