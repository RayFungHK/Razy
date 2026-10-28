<?php

/**
 * This file is part of Razy.
 *
 * RZ-39: the bootstrap makes LC_CTYPE UTF-8 aware without calling setlocale()
 * when it already is, so a threaded SAPI (FrankenPHP / ZTS) never sees the
 * request-end locale reset. Candidates come from RAZY_LOCALE_CTYPE or
 * config.inc.php 'locale_ctype'; 'off' leaves the locale alone. The race itself
 * is checked under FrankenPHP threads by tools/frankenphp-locale-race.sh (CI).
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Tests;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Util\Locale;

#[CoversClass(Locale::class)]
class LocaleTest extends TestCase
{
    /** @var list<array{int, list<string>}> */
    private array $calls = [];

    protected function setUp(): void
    {
        Locale::reset();
        $this->calls = [];
    }

    protected function tearDown(): void
    {
        Locale::reset();
    }

    /**
     * @return array<string, array{string|false, array<string, mixed>}>
     */
    public static function offValues(): array
    {
        return [
            'env off' => ['off', []],
            'env none' => ['NONE', []],
            'config off' => [false, ['locale_ctype' => 'off']],
            'config false' => [false, ['locale_ctype' => 'false']],
        ];
    }

    public function testAlreadyUtf8IsNotTouched(): void
    {
        $this->assertSame('C.UTF-8', Locale::ensureUtf8Ctype(false, [], $this->fake('C.UTF-8')));
        $this->assertSame([], $this->changes(), 'no setlocale() change, so PHP has nothing to reset');
        $this->assertSame([[\LC_CTYPE, ['0']]], $this->calls);
    }

    public function testAnyUtf8LocaleSatisfiesTheDefaults(): void
    {
        $this->assertSame('en_US.utf8', Locale::ensureUtf8Ctype(false, [], $this->fake('en_US.utf8')));
        $this->assertSame([], $this->changes());
    }

    public function testCLocaleIsSetOnceWithTheDefaultCandidates(): void
    {
        $this->assertSame('C.UTF-8', Locale::ensureUtf8Ctype(false, [], $this->fake('C')));
        $this->assertSame([Locale::DEFAULT_CANDIDATES], $this->changes());
    }

    public function testOncePerProcess(): void
    {
        $set = $this->fake('C');
        Locale::ensureUtf8Ctype(false, [], $set);
        $this->calls = [];
        $this->assertSame('C.UTF-8', Locale::ensureUtf8Ctype(false, [], $set));
        $this->assertSame([], $this->calls, 'a second bootstrap in the same process does not call setlocale() at all');
    }

    public function testFallsBackThroughTheCandidates(): void
    {
        $this->assertSame('en_US.UTF-8', Locale::ensureUtf8Ctype(false, [], $this->fake('C', ['en_US.UTF-8'])));
    }

    public function testNoCandidateAvailableIsFalseAndNotCached(): void
    {
        $this->assertFalse(Locale::ensureUtf8Ctype(false, [], $this->fake('C', [])));
        $this->calls = [];
        Locale::ensureUtf8Ctype(false, [], $this->fake('C', []));
        $this->assertNotSame([], $this->calls, 'a failed attempt is retried next time');
    }

    public function testEnvCandidatesWinOverConfig(): void
    {
        $result = Locale::ensureUtf8Ctype('zh_HK.UTF-8', ['locale_ctype' => 'en_US.UTF-8'], $this->fake('C', ['zh_HK.UTF-8', 'en_US.UTF-8']));
        $this->assertSame('zh_HK.UTF-8', $result);
        $this->assertSame([['zh_HK.UTF-8']], $this->changes());
    }

    public function testConfigCandidatesAsListOrString(): void
    {
        $this->assertSame(['en_US.UTF-8', 'C.UTF-8'], Locale::candidates(false, ['locale_ctype' => ['en_US.UTF-8', 'C.UTF-8']]));
        $this->assertSame(['en_US.UTF-8', ''], Locale::candidates(false, ['locale_ctype' => ' en_US.UTF-8 , env ']));
        $this->assertSame(Locale::DEFAULT_CANDIDATES, Locale::candidates(false, ['locale_ctype' => []]));
        $this->assertSame(Locale::DEFAULT_CANDIDATES, Locale::candidates('  ', []));
        $this->assertSame(Locale::DEFAULT_CANDIDATES, Locale::candidates(false, ['locale_ctype' => 42]));
    }

    public function testConfiguredListOnlyAcceptsItsOwnNames(): void
    {
        // C.UTF-8 is UTF-8 but not what the operator asked for.
        $this->assertSame('zh_HK.UTF-8', Locale::ensureUtf8Ctype('zh_HK.UTF-8', [], $this->fake('C.UTF-8', ['zh_HK.UTF-8'])));
        $this->assertSame([['zh_HK.UTF-8']], $this->changes());
        Locale::reset();
        $this->calls = [];
        // The same name in another codeset spelling counts as already set.
        $this->assertSame('zh_HK.utf8', Locale::ensureUtf8Ctype('zh_HK.UTF-8', [], $this->fake('zh_HK.utf8')));
        $this->assertSame([], $this->changes());
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('offValues')]
    public function testOffNeverCallsSetlocale(string|false $env, array $config): void
    {
        $this->assertFalse(Locale::ensureUtf8Ctype($env, $config, $this->fake('C')));
        $this->assertSame([], $this->calls);
    }

    public function testIsTarget(): void
    {
        $this->assertTrue(Locale::isTarget('C.UTF-8', Locale::DEFAULT_CANDIDATES));
        $this->assertTrue(Locale::isTarget('C.utf8', Locale::DEFAULT_CANDIDATES));
        $this->assertFalse(Locale::isTarget('C', Locale::DEFAULT_CANDIDATES));
        $this->assertFalse(Locale::isTarget('POSIX', Locale::DEFAULT_CANDIDATES));
        $this->assertFalse(Locale::isTarget(false, Locale::DEFAULT_CANDIDATES));
        $this->assertFalse(Locale::isTarget('', ['']));
    }

    public function testRealSetlocaleLeavesAUtf8ProcessAlone(): void
    {
        $before = \setlocale(\LC_CTYPE, '0');
        if (false === $before || 1 !== \preg_match('/utf-?8/i', $before)) {
            $this->markTestSkipped("LC_CTYPE starts as {$before} here");
        }
        $this->assertSame($before, Locale::ensureUtf8Ctype(false));
        $this->assertSame($before, \setlocale(\LC_CTYPE, '0'));
    }

    public function testBootstrapUsesLocaleOnly(): void
    {
        $bootstrap = (string) \file_get_contents(\dirname(__DIR__) . '/src/system/bootstrap.inc.php');
        $code = (string) \preg_replace('#^\s*//.*$#m', '', $bootstrap);
        $this->assertStringNotContainsString('setlocale(', $code, 'no per-request setlocale() in the bootstrap');
        $this->assertStringContainsString('Util\Locale::ensureUtf8Ctype(\getenv(Util\Locale::ENV), $razyConfig);', $bootstrap);
    }

    /**
     * A setlocale() stand-in: '0' answers $current; a change answers the first
     * candidate in $available (or false). Every call is recorded.
     *
     * @param list<string> $available
     */
    private function fake(string|false $current, array $available = ['C.UTF-8']): Closure
    {
        return function (int $category, string ...$names) use (&$current, $available) {
            $this->calls[] = [$category, $names];
            if (['0'] === $names) {
                return $current;
            }
            foreach ($names as $name) {
                if (\in_array($name, $available, true)) {
                    $current = $name;

                    return $name;
                }
            }

            return false;
        };
    }

    /** @return list<list<string>> the names of each changing call */
    private function changes(): array
    {
        $out = [];
        foreach ($this->calls as [, $names]) {
            if (['0'] !== $names) {
                $out[] = $names;
            }
        }

        return $out;
    }
}
