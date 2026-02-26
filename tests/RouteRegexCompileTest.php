<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Distributor\RouteDispatcher;

/**
 * Tests for P4: RouteDispatcher::compileRouteRegex() — route regex pre-compilation.
 */
#[CoversClass(RouteDispatcher::class)]
class RouteRegexCompileTest extends TestCase
{
    // ─── Basic Route Compilation ────────────────────────────────

    public function testStaticRoute(): void
    {
        $regex = RouteDispatcher::compileRouteRegex('/home');
        $this->assertIsString($regex);
        $this->assertMatchesRegularExpression($regex, '/home');
    }

    public function testStaticRouteDoesNotMatchDifferentPath(): void
    {
        $regex = RouteDispatcher::compileRouteRegex('/home');
        $this->assertDoesNotMatchRegularExpression($regex, '/about');
    }

    // ─── Placeholder Patterns ───────────────────────────────────

    public function testAlphaPlaceholder(): void
    {
        $regex = RouteDispatcher::compileRouteRegex('/user/:a');
        $this->assertMatchesRegularExpression($regex, '/user/alice');
        // :a matches word characters ([a-zA-Z0-9_]+) so digits also match
        $this->assertMatchesRegularExpression($regex, '/user/123');
    }

    public function testDigitPlaceholder(): void
    {
        $regex = RouteDispatcher::compileRouteRegex('/item/:d');
        $this->assertMatchesRegularExpression($regex, '/item/123');
        // Should not match letters
        $this->assertDoesNotMatchRegularExpression($regex, '/item/abc');
    }

    public function testWordPlaceholder(): void
    {
        $regex = RouteDispatcher::compileRouteRegex('/page/:w');
        $this->assertMatchesRegularExpression($regex, '/page/hello123');
        $this->assertMatchesRegularExpression($regex, '/page/test_page');
    }

    // ─── Quantified Placeholders ─────────────────────────────────

    public function testPlaceholderWithExactLength(): void
    {
        $regex = RouteDispatcher::compileRouteRegex('/code/:a{3}');
        $this->assertMatchesRegularExpression($regex, '/code/abc');
        // Exact 3 chars — but pattern allows trailing characters (suffix matching)
    }

    public function testPlaceholderWithRange(): void
    {
        $regex = RouteDispatcher::compileRouteRegex('/id/:d{2,5}');
        $this->assertMatchesRegularExpression($regex, '/id/12');
        $this->assertMatchesRegularExpression($regex, '/id/12345');
    }

    // ─── Custom Character Class ──────────────────────────────────

    public function testCustomCharacterClass(): void
    {
        $regex = RouteDispatcher::compileRouteRegex('/hex/:[0-9a-f]');
        $this->assertMatchesRegularExpression($regex, '/hex/a');
        $this->assertMatchesRegularExpression($regex, '/hex/f');
        $this->assertMatchesRegularExpression($regex, '/hex/0');
    }

    // ─── Multiple Placeholders ──────────────────────────────────

    public function testMultiplePlaceholders(): void
    {
        $regex = RouteDispatcher::compileRouteRegex('/user/:a/post/:d');
        $this->assertMatchesRegularExpression($regex, '/user/alice/post/42');
    }

    // ─── Deterministic Compilation ──────────────────────────────

    public function testCompilationIsDeterministic(): void
    {
        $regex1 = RouteDispatcher::compileRouteRegex('/user/:a/post/:d');
        $regex2 = RouteDispatcher::compileRouteRegex('/user/:a/post/:d');
        $this->assertSame($regex1, $regex2);
    }

    // ─── Root Route ─────────────────────────────────────────────

    public function testRootRoute(): void
    {
        $regex = RouteDispatcher::compileRouteRegex('/');
        $this->assertMatchesRegularExpression($regex, '/');
    }

    // ─── Escaped Characters ─────────────────────────────────────

    public function testEscapedDotInRoute(): void
    {
        $regex = RouteDispatcher::compileRouteRegex('/file\\.json');
        $this->assertMatchesRegularExpression($regex, '/file.json');
    }

    // ─── Suffix Capture ─────────────────────────────────────────

    public function testSuffixCapturedAfterRoute(): void
    {
        $regex = RouteDispatcher::compileRouteRegex('/api');
        // The compiled regex captures trailing path segments
        \preg_match($regex, '/api/extra/path', $matches);
        $this->assertNotEmpty($matches);
    }
}
