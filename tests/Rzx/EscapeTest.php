<?php

declare(strict_types=1);

namespace Razy\Tests\Rzx;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Rzx\Runtime\Escape;

/**
 * RZ-28b: the URL allowlist against a corpus of known bypass spellings.
 */
#[CoversClass(Escape::class)]
class EscapeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function urls(): iterable
    {
        yield 'https' => ['https://a.test/x', true];
        yield 'http upper' => ['HTTP://a.test', true];
        yield 'mailto' => ['mailto:x@y.test', true];
        yield 'relative' => ['a/b?c=d:e', true];
        yield 'root relative' => ['/a', true];
        yield 'protocol relative' => ['//cdn.test/a.js', true];
        yield 'fragment' => ['#top', true];
        yield 'query' => ['?a=b', true];
        yield 'empty' => ['', true];
        yield 'javascript' => ['javascript:alert(1)', false];
        yield 'javascript upper' => ['JaVaScRiPt:alert(1)', false];
        yield 'leading space' => ['  javascript:alert(1)', false];
        yield 'tab inside' => ["java\tscript:alert(1)", false];
        yield 'newline inside' => ["java\nscript:alert(1)", false];
        yield 'nul inside' => ["java\0script:alert(1)", false];
        yield 'control prefix' => ["\x01javascript:x", false];
        yield 'data' => ['data:text/html,<script>', false];
        yield 'vbscript' => ['vbscript:x', false];
        yield 'file' => ['file:///etc/passwd', false];
        yield 'blob' => ['blob:https://a.test/x', false];
        yield 'unknown scheme' => ['foo:bar', false];
    }

    #[DataProvider('urls')]
    public function testUrlAllowlist(string $url, bool $safe): void
    {
        $this->assertSame($safe, Escape::isSafeUrl($url));
        $this->assertSame($safe ? $url : '#', Escape::url($url));
    }

    public function testAttributeEscapesAndJsonEncodes(): void
    {
        $this->assertSame(' a="&lt;&quot;&apos;&amp;"', Escape::attribute('a', "<\"'&"));
        $this->assertSame(' HREF="#"', Escape::attribute('HREF', 'javascript:x'), 'URL check is case-insensitive');
        $this->assertSame(' d="{&quot;k&quot;:[1,2]}"', Escape::attribute('d', ['k' => [1, 2]]));
        $this->assertSame('', Escape::attribute('d', null));
        $this->assertSame(' d', Escape::attribute('d', true));
        $this->assertSame("\xEF\xBF\xBD", Escape::text("\xC3"), 'invalid UTF-8 is substituted, not passed through');
    }
}
