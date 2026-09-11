<?php

declare(strict_types=1);

namespace Razy\Tests;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Razy\Template\Plugin\TModifier;
use stdClass;

/**
 * Semantic tests for the five modifiers shipped in the plugin ecosystem round
 * (src/plugins/Template/modifier.{truncate,date,number,json,strip_tags}.php).
 *
 * Direct factory → modify() calls mirror TemplateModifierEscapeTest: parameter
 * text uses the engine's ':arg' wire format, quoted where the argument isn't a
 * bare word or number.
 */
#[CoversNothing]
class TemplatePluginModifiersTest extends TestCase
{
    // ── truncate ──────────────────────────────────────────────

    public function testTruncateDefaultLimitIncludesSuffix(): void
    {
        $out = $this->modifier('truncate')->modify(\str_repeat('x', 60));

        $this->assertSame(50, \mb_strlen($out));
        $this->assertStringEndsWith('...', $out);
    }

    public function testTruncateLeavesShortTextAlone(): void
    {
        $this->assertSame('short text', $this->modifier('truncate')->modify('short text'));
    }

    public function testTruncateCountsUtf8CharactersNotBytes(): void
    {
        $out = $this->modifier('truncate')->modify('繁體中文內容測試資料超過長度', ':5');

        $this->assertSame(5, \mb_strlen($out));
        $this->assertStringEndsWith('...', $out);
    }

    public function testTruncateCustomSuffixCountsTowardLimit(): void
    {
        $out = $this->modifier('truncate')->modify(\str_repeat('字', 30), ":10:'…'");

        $this->assertSame(10, \mb_strlen($out));
        $this->assertStringEndsWith('…', $out);
    }

    public function testTruncateCutWordBacksOffToBoundary(): void
    {
        $m = $this->modifier('truncate');

        $this->assertSame('Hello won...', $m->modify('Hello wonderful world', ':12'), 'hard cut by default');
        $this->assertSame('Hello...', $m->modify('Hello wonderful world', ":12:'...':1"), 'word boundary wins');
    }

    public function testTruncateDegenerateSuffixFallsBackToHardCut(): void
    {
        // suffix '...' (3 chars) exceeds the 2-char budget → plain hard cut, no suffix
        $this->assertSame('ab', $this->modifier('truncate')->modify('abcdefghij', ":2:'...'"));
    }

    public function testTruncateRejectsArrays(): void
    {
        $this->assertSame('', $this->modifier('truncate')->modify(['a']));
    }

    // ── date ──────────────────────────────────────────────────

    public function testDateFormatsEpochSecondsInGivenTimezone(): void
    {
        $out = $this->modifier('date')->modify(1_700_000_000, ":\"Y-m-d H:i\":'UTC'");

        $this->assertSame('2023-11-14 22:13', $out);
    }

    public function testDateAcceptsEpochMilliseconds(): void
    {
        $out = $this->modifier('date')->modify(1_700_000_000_000, ":\"Y-m-d H:i\":'UTC'");

        $this->assertSame('2023-11-14 22:13', $out);
    }

    public function testDateDefaultFormatOnStringInput(): void
    {
        $this->assertSame('2026-01-15 08:30', $this->modifier('date')->modify('2026-01-15 08:30:00'));
    }

    public function testDateCustomFormatAndTimezoneConversion(): void
    {
        $out = $this->modifier('date')->modify(1_700_000_000, ":\"Y-m-d H:i\":'Asia/Tokyo'");

        $this->assertSame('2023-11-15 07:13', $out, 'UTC+9 crosses midnight');
    }

    public function testDateAcceptsDateTimeInterface(): void
    {
        $date = new DateTimeImmutable('2025-05-01 12:00:00', new DateTimeZone('UTC'));

        $this->assertSame('2025-05-01', $this->modifier('date')->modify($date, ":\"Y-m-d\":'UTC'"));
    }

    public function testDateUnparseableRendersOriginalText(): void
    {
        $this->assertSame('—', $this->modifier('date')->modify('—'));
        $this->assertSame('not a date', $this->modifier('date')->modify('not a date'));
    }

    public function testDateUnknownTimezoneDoesNotThrow(): void
    {
        $out = $this->modifier('date')->modify(1_700_000_000, ":\"Y-m-d H:i\":'Mars/Olympus'");

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $out);
    }

    // ── number ────────────────────────────────────────────────

    public function testNumberGroupsThousands(): void
    {
        $this->assertSame('1,234,567', $this->modifier('number')->modify(1234567));
    }

    public function testNumberFixedDecimals(): void
    {
        $this->assertSame('1,234,567.00', $this->modifier('number')->modify(1234567, ':2'));
    }

    public function testNumberEuropeanSeparators(): void
    {
        $out = $this->modifier('number')->modify(1234567.891, ":2:',':'.'");

        $this->assertSame('1.234.567,89', $out);
    }

    public function testNumberAcceptsNumericStrings(): void
    {
        $this->assertSame('12.34', $this->modifier('number')->modify('12.34', ':2'));
    }

    public function testNumberRejectsGarbageAndNull(): void
    {
        $m = $this->modifier('number');

        $this->assertSame('', $m->modify('twelve'));
        $this->assertSame('', $m->modify(null));
        $this->assertSame('', $m->modify(['1']));
    }

    // ── json ──────────────────────────────────────────────────

    public function testJsonIsScriptContextSafe(): void
    {
        $out = $this->modifier('json')->modify(['x' => '</script><script>alert(1)</script>']);

        // Exact hex-entity encoding proves every dangerous char class is neutralised.
        $this->assertSame('{"x":"\u003C\/script\u003E\u003Cscript\u003Ealert(1)\u003C\/script\u003E"}', $out);
        $this->assertStringNotContainsString('<', $out, 'JSON_HEX_TAG must neutralise angle brackets');
        $this->assertStringNotContainsString("'", $out, 'JSON_HEX_APOS must neutralise single quotes');
    }

    public function testJsonKeepsCjkReadable(): void
    {
        $this->assertStringContainsString('中文', $this->modifier('json')->modify(['k' => '中文']));
    }

    public function testJsonScalarAndFailurePaths(): void
    {
        $m = $this->modifier('json');

        $this->assertSame('5', $m->modify(5));
        $this->assertSame('"x"', $m->modify('x'), 'strings encode quoted');
        $this->assertSame('', $m->modify(NAN), 'NAN is not JSON-encodable → empty');
    }

    public function testJsonEncodesInfAsEmpty(): void
    {
        $this->assertSame('', $this->modifier('json')->modify(INF));
    }

    // ── strip_tags ────────────────────────────────────────────

    public function testStripTagsRemovesEverythingByDefault(): void
    {
        $this->assertSame(
            'bold and loud()',
            $this->modifier('strip_tags')->modify('<p>bold</p> <b>and</b> <script>loud()</script>'),
            'tags are removed; script text content survives as plain text (strip_tags semantics, documented)',
        );
    }

    public function testStripTagsRespectsAllowList(): void
    {
        $out = $this->modifier('strip_tags')->modify('<b>keep</b><i>this</i><u>drop</u>', ':"b i"');

        $this->assertSame('<b>keep</b><i>this</i>drop', $out);
    }

    public function testStripTagsRejectsArraysAndToStringLessObjects(): void
    {
        $m = $this->modifier('strip_tags');

        $this->assertSame('', $m->modify([]));
        $this->assertSame('', $m->modify(new stdClass()));
    }

    private function modifier(string $name): TModifier
    {
        $factory = require __DIR__ . '/../src/plugins/Template/modifier.' . $name . '.php';
        $this->assertInstanceOf(Closure::class, $factory);

        $modifier = $factory();
        $this->assertInstanceOf(TModifier::class, $modifier);
        $modifier->setName($name);

        return $modifier;
    }
}
