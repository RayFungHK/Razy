<?php

declare(strict_types=1);

namespace Razy\Tests;

use Closure;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Razy\Template\Entity;
use Razy\Template\Plugin\TFunction;

/**
 * Tests for the shipped `paginate` template function plugin.
 *
 * Exercises the REAL TFunction::parse() wire format ('{@paginate page=$p ...}')
 * with an Entity stub that only resolves variables — proving the parameter
 * declaration, literal/quoted handling and the markup contract together.
 * Note the engine's named-parameter grammar only accepts $vars, numbers,
 * true/false and QUOTED strings — base urls must arrive quoted.
 */
#[CoversNothing]
class TemplateFunctionPaginateTest extends TestCase
{
    private TFunction $plugin;

    protected function setUp(): void
    {
        $factory = require __DIR__ . '/../src/plugins/Template/function.paginate.php';
        $this->assertInstanceOf(Closure::class, $factory);

        $this->plugin = $factory();
        $this->assertInstanceOf(TFunction::class, $this->plugin);
        $this->plugin->setName('paginate');
    }

    public function testSinglePageNeedsNoNavigation(): void
    {
        $this->assertSame('', $this->render('page=1 pages=1'));
    }

    public function testWindowEllipsisAndActiveState(): void
    {
        $out = $this->render('page=$p pages=$n base=$base', ['p' => 5, 'n' => 10, 'base' => '/shop']);

        $this->assertStringContainsString('<nav aria-label="pagination" class="pagination">', $out);
        $this->assertStringContainsString('<span class="page-item" aria-current="page">5</span>', $out);
        $this->assertSame(2, \substr_count($out, '…'), 'gaps before and after the window');
        $this->assertStringContainsString('href="/shop?page=4"', $out);
        $this->assertStringContainsString('href="/shop?page=7"', $out);
        $this->assertStringNotContainsString('page=2"', $out, 'outside window+gap (window 2 around 5 shows 3..7)');
    }

    public function testBaseWithExistingQueryUsesAmpersand(): void
    {
        // NB: base uses '/s?c' not '/s?c=1' — the engine's named-parameter splitter
        // (TFunction::parse L110) splits on the FIRST '=', so a value containing '='
        // is truncated. Pre-existing engine behaviour, out of scope for this plugin.
        $out = $this->render("page=2 pages=3 base='/s?c'");

        // '&amp;' is the HTML-correct encoding of the URL separator inside an attribute.
        $this->assertStringContainsString('href="/s?c&amp;page=3"', $out);
    }

    public function testEmptyBaseRendersSpansOnly(): void
    {
        $out = $this->render('page=2 pages=5');

        $this->assertStringNotContainsString('<a ', $out, 'no base → zero links');
        $this->assertStringContainsString('<span class="page-item">3</span>', $out);
    }

    public function testBaseAndLabelsAreEscapedAtEmit(): void
    {
        $out = $this->render("page=1 pages=3 base='/x\"<script>'");

        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringContainsString('&lt;script&gt;', $out);
        $this->assertStringContainsString('&quot;', $out);
    }

    public function testPrevNextArrows(): void
    {
        $out = $this->render("page=3 pages=5 base='/shop'");

        $this->assertStringContainsString('href="/shop?page=2">‹</a>', $out);
        $this->assertStringContainsString('href="/shop?page=4">›</a>', $out);
    }

    public function testPageBeyondRangeClampsToLast(): void
    {
        $out = $this->render("page=99 pages=10 base='/shop'");

        $this->assertStringContainsString('aria-current="page">10</span>', $out);
        $this->assertStringNotContainsString('›', $out, 'no next link on last page');
    }

    private function render(string $syntax, array $vars = []): string
    {
        // Minimal Entity stand-in: the plugin only needs literal/variable resolution.
        $entity = new class($vars) extends Entity {
            public function __construct(private readonly array $razyVars)
            {
            }

            public function parseValue(string $content): mixed
            {
                $content = \trim($content);

                if (($content[0] ?? '') === "'" || ($content[0] ?? '') === '"') {
                    return \trim($content, "'\""); // quoted literal
                }
                if (\str_starts_with($content, '$')) {
                    return $this->razyVars[\substr($content, 1)] ?? null;
                }

                return $content; // numeric/plain literal
            }
        };

        return (string) $this->plugin->parse($entity, ' ' . $syntax);
    }
}
