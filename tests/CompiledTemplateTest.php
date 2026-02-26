<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Template\CompiledTemplate;

/**
 * Tests for P1: CompiledTemplate — template variable tag pre-tokenization.
 */
#[CoversClass(CompiledTemplate::class)]
class CompiledTemplateTest extends TestCase
{
    protected function setUp(): void
    {
        CompiledTemplate::clearCache();
    }

    // ─── Basic Compilation ──────────────────────────────────────

    public function testCompileLiteralText(): void
    {
        $compiled = CompiledTemplate::compile('Hello World');

        $this->assertCount(1, $compiled->segments);
        $this->assertSame('Hello World', $compiled->segments[0]);
    }

    public function testCompileEmptyString(): void
    {
        $compiled = CompiledTemplate::compile('');

        $this->assertCount(1, $compiled->segments);
        $this->assertSame('', $compiled->segments[0]);
    }

    public function testCompileSingleVariable(): void
    {
        $compiled = CompiledTemplate::compile('Hello {$name}!');

        $this->assertCount(3, $compiled->segments);
        $this->assertSame('Hello ', $compiled->segments[0]);
        $this->assertIsArray($compiled->segments[1]);
        $this->assertSame(['$name'], $compiled->segments[1]['clips']);
        $this->assertSame('!', $compiled->segments[2]);
    }

    public function testCompileMultipleVariables(): void
    {
        $compiled = CompiledTemplate::compile('{$first} and {$second}');

        $this->assertCount(3, $compiled->segments);
        $this->assertIsArray($compiled->segments[0]);
        $this->assertSame(['$first'], $compiled->segments[0]['clips']);
        $this->assertSame(' and ', $compiled->segments[1]);
        $this->assertIsArray($compiled->segments[2]);
        $this->assertSame(['$second'], $compiled->segments[2]['clips']);
    }

    public function testCompileVariableWithDotPath(): void
    {
        $compiled = CompiledTemplate::compile('{$user.name}');

        $this->assertCount(1, $compiled->segments);
        $this->assertIsArray($compiled->segments[0]);
        $this->assertSame(['$user.name'], $compiled->segments[0]['clips']);
    }

    public function testCompileVariableWithPipeFallback(): void
    {
        $compiled = CompiledTemplate::compile('{$name|$fallback}');

        $this->assertCount(1, $compiled->segments);
        $this->assertIsArray($compiled->segments[0]);
        $this->assertSame(['$name', '$fallback'], $compiled->segments[0]['clips']);
    }

    public function testCompileOnlyLiteralNoVariables(): void
    {
        $compiled = CompiledTemplate::compile('<div class="container">No variables here</div>');

        $this->assertCount(1, $compiled->segments);
        $this->assertSame('<div class="container">No variables here</div>', $compiled->segments[0]);
    }

    // ─── Caching ─────────────────────────────────────────────────

    public function testCompileReturnsSameInstanceForSameContent(): void
    {
        $compiled1 = CompiledTemplate::compile('Hello {$name}');
        $compiled2 = CompiledTemplate::compile('Hello {$name}');

        $this->assertSame($compiled1, $compiled2);
    }

    public function testCompileReturnsDifferentInstanceForDifferentContent(): void
    {
        $compiled1 = CompiledTemplate::compile('Hello {$name}');
        $compiled2 = CompiledTemplate::compile('Goodbye {$name}');

        $this->assertNotSame($compiled1, $compiled2);
    }

    public function testClearCacheResetsMemory(): void
    {
        CompiledTemplate::compile('Test content');
        $this->assertSame(1, CompiledTemplate::getCacheSize());

        CompiledTemplate::clearCache();
        $this->assertSame(0, CompiledTemplate::getCacheSize());
    }

    public function testGetCacheSizeTracksEntries(): void
    {
        $this->assertSame(0, CompiledTemplate::getCacheSize());

        CompiledTemplate::compile('Content A');
        $this->assertSame(1, CompiledTemplate::getCacheSize());

        CompiledTemplate::compile('Content B');
        $this->assertSame(2, CompiledTemplate::getCacheSize());

        // Same content should not increase cache size
        CompiledTemplate::compile('Content A');
        $this->assertSame(2, CompiledTemplate::getCacheSize());
    }

    public function testIsCachedReturnsTrueForCachedContent(): void
    {
        $compiled = CompiledTemplate::compile('Hello {$world}');
        $this->assertTrue(CompiledTemplate::isCached($compiled->hash));
    }

    public function testIsCachedReturnsFalseForUncachedContent(): void
    {
        $this->assertFalse(CompiledTemplate::isCached(\md5('nonexistent')));
    }

    public function testFromCacheReturnsInstance(): void
    {
        $compiled = CompiledTemplate::compile('Test {$var}');
        $cached = CompiledTemplate::fromCache($compiled->hash);

        $this->assertSame($compiled, $cached);
    }

    public function testFromCacheReturnsNullForMissing(): void
    {
        $this->assertNull(CompiledTemplate::fromCache('nonexistent_hash'));
    }

    // ─── Hash and Metadata ──────────────────────────────────────

    public function testHashIsConsistent(): void
    {
        $content = 'Hello {$name}!';
        $compiled = CompiledTemplate::compile($content);

        $this->assertSame(\md5($content), $compiled->hash);
    }

    public function testCompiledAtIsTimestamp(): void
    {
        $before = \time();
        $compiled = CompiledTemplate::compile('Test');
        $after = \time();

        $this->assertGreaterThanOrEqual($before, $compiled->compiledAt);
        $this->assertLessThanOrEqual($after, $compiled->compiledAt);
    }

    // ─── Complex Patterns ───────────────────────────────────────

    public function testCompileVariableAtStartAndEnd(): void
    {
        $compiled = CompiledTemplate::compile('{$start}middle{$end}');

        $this->assertCount(3, $compiled->segments);
        $this->assertIsArray($compiled->segments[0]);
        $this->assertSame('middle', $compiled->segments[1]);
        $this->assertIsArray($compiled->segments[2]);
    }

    public function testCompileConsecutiveVariables(): void
    {
        $compiled = CompiledTemplate::compile('{$a}{$b}{$c}');

        $this->assertCount(3, $compiled->segments);
        $this->assertIsArray($compiled->segments[0]);
        $this->assertIsArray($compiled->segments[1]);
        $this->assertIsArray($compiled->segments[2]);
    }

    public function testCompileVariableWithModifier(): void
    {
        $compiled = CompiledTemplate::compile('{$name->upper}');

        $this->assertCount(1, $compiled->segments);
        $this->assertIsArray($compiled->segments[0]);
        // The full expression including modifier should be in clips
        $this->assertSame(['$name->upper'], $compiled->segments[0]['clips']);
    }

    public function testCompileNonVariableBraces(): void
    {
        // Braces that don't match the variable pattern should be kept as literal
        $compiled = CompiledTemplate::compile('CSS: .foo { color: red; }');

        $this->assertCount(1, $compiled->segments);
        $this->assertSame('CSS: .foo { color: red; }', $compiled->segments[0]);
    }

    public function testCompileMultiplePipeFallbacks(): void
    {
        $compiled = CompiledTemplate::compile('{$a|$b|$c}');

        $this->assertCount(1, $compiled->segments);
        $this->assertIsArray($compiled->segments[0]);
        $this->assertSame(['$a', '$b', '$c'], $compiled->segments[0]['clips']);
    }

    // ─── Clear after clear ──────────────────────────────────────

    public function testClearThenRecompileProducesNewInstance(): void
    {
        $first = CompiledTemplate::compile('Test');
        CompiledTemplate::clearCache();
        $second = CompiledTemplate::compile('Test');

        // Different object but same segments
        $this->assertNotSame($first, $second);
        $this->assertEquals($first->segments, $second->segments);
        $this->assertSame($first->hash, $second->hash);
    }
}
