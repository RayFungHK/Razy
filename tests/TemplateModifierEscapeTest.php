<?php

declare(strict_types=1);

namespace Razy\Tests;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Template\Plugin\TModifier;
use stdClass;

/**
 * Tests for the shipped `escape` template modifier plugin
 * (src/plugins/Template/modifier.escape.php).
 *
 * Closes the template output-safety gap (Golden Rule RZ-004): the engine does not
 * auto-escape, so {$value->escape} must be a first-class built-in.
 */
#[CoversClass(TModifier::class)]
class TemplateModifierEscapeTest extends TestCase
{
    private TModifier $modifier;

    protected function setUp(): void
    {
        $factory = require __DIR__ . '/../src/plugins/Template/modifier.escape.php';
        $this->assertInstanceOf(Closure::class, $factory);

        $this->modifier = $factory();
        $this->assertInstanceOf(TModifier::class, $this->modifier);
        $this->modifier->setName('escape');
    }

    public function testEscapesDoubleAndSingleQuotes(): void
    {
        $out = $this->modifier->modify('He said "hi" & O\'Brien');

        $this->assertStringNotContainsString('"', $out);
        $this->assertStringNotContainsString("'", $out);
        $this->assertStringContainsString('&quot;', $out);
        $this->assertStringContainsString('&#039;', $out);
        $this->assertStringContainsString('&amp;', $out);
    }

    public function testEscapesScriptPayload(): void
    {
        $out = $this->modifier->modify('<script>alert(1)</script>');

        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', $out);
    }

    public function testPlainStringPassesThroughUnchanged(): void
    {
        $this->assertSame('plain text 123', $this->modifier->modify('plain text 123'));
    }

    public function testNullAndEmptyRenderEmpty(): void
    {
        $this->assertSame('', $this->modifier->modify(null));
        $this->assertSame('', $this->modifier->modify(''));
    }

    public function testNumbersAreStringifiedSafely(): void
    {
        $this->assertSame('42', $this->modifier->modify(42));
        $this->assertSame('1.5', $this->modifier->modify(1.5));
        $this->assertSame('1', $this->modifier->modify(true));
    }

    public function testInvalidUtf8IsSubstitutedNotEmpty(): void
    {
        // Without ENT_SUBSTITUTE, malformed UTF-8 makes htmlspecialchars() return ''.
        $out = $this->modifier->modify("bad\xB1\xADbyte");

        $this->assertNotSame('', $out);
        $this->assertStringContainsString("\u{FFFD}", $out);
    }

    public function testArrayAndNonStringableObjectRenderEmpty(): void
    {
        $this->assertSame('', $this->modifier->modify(['a', 'b']));

        $opaque = new stdClass();
        $this->assertSame('', $this->modifier->modify($opaque));
    }

    public function testObjectWithToStringIsEscaped(): void
    {
        $stringable = new class() {
            public function __toString(): string
            {
                return '<b>bold</b>';
            }
        };

        $this->assertSame('&lt;b&gt;bold&lt;/b&gt;', $this->modifier->modify($stringable));
    }

    public function testChainingViaModifyParamText(): void
    {
        // modify() parses the raw `:arg` text; escape takes no args, so an empty
        // param text must behave identically (as produced by `{$v->escape}`).
        $this->assertSame('&lt;x&gt;', $this->modifier->modify('<x>', ''));
    }
}
