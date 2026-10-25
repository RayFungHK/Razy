<?php

declare(strict_types=1);

namespace Razy\Tests\Rzx;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Rzx\Compile\CodeGen;
use Razy\Rzx\Compile\ComponentCall;
use Razy\Rzx\Compile\ExprGen;
use Razy\Rzx\Runtime\Escape;
use Razy\Rzx\Runtime\RenderError;
use Razy\Rzx\Runtime\Runtime;
use Razy\Rzx\SafeHtml;

/**
 * RZ-28b: what compiled `.rzx` renders. Escaping is by context and on by
 * default; there is no way to print a string unescaped.
 */
#[CoversClass(CodeGen::class)]
#[CoversClass(ComponentCall::class)]
#[CoversClass(ExprGen::class)]
#[CoversClass(Runtime::class)]
#[CoversClass(Escape::class)]
class RenderTest extends TestCase
{
    use RzxHarness;

    private const PROPS = "<props>\nreturn ['v' => Prop::any(), 'w' => Prop::any()];\n</props>\n";

    /**
     * @return iterable<string, array{string, mixed, string}>
     */
    public static function xss(): iterable
    {
        $script = '<script>alert(1)</script>';
        yield 'text' => ['<p>{v}</p>', $script, '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>'];
        yield 'text quotes' => ['<p>{v}</p>', '"\'&', '<p>&quot;&apos;&amp;</p>'];
        yield 'attribute breakout' => ['<p title={v}></p>', '" onmouseover="x', '<p title="&quot; onmouseover=&quot;x"></p>'];
        yield 'single-quote breakout' => ['<p title={v}></p>', "' onclick='x", '<p title="&apos; onclick=&apos;x"></p>'];
        yield 'javascript url' => ['<a href={v}>x</a>', 'javascript:alert(1)', '<a href="#">x</a>'];
        yield 'obfuscated scheme' => ['<a href={v}>x</a>', " jav\tascript:alert(1)", '<a href="#">x</a>'];
        yield 'entity in scheme stays text' => ['<a href={v}>x</a>', 'javascript&colon;alert(1)', '<a href="javascript&amp;colon;alert(1)">x</a>'];
        yield 'data url' => ['<img src={v} />', 'data:text/html;base64,PHNjcmlwdD4=', '<img src="#">'];
        yield 'vbscript' => ['<a href={v}>x</a>', 'VBScript:msgbox', '<a href="#">x</a>'];
        yield 'https url kept' => ['<a href={v}>x</a>', 'https://example.test/?a=1&b=2', '<a href="https://example.test/?a=1&amp;b=2">x</a>'];
        yield 'relative url kept' => ['<a href={v}>x</a>', '/path?q=a:b', '<a href="/path?q=a:b">x</a>'];
        yield 'mailto kept' => ['<a href={v}>x</a>', 'mailto:a@b.test', '<a href="mailto:a@b.test">x</a>'];
        yield 'json attribute' => ['<x-list items={v}></x-list>', ['</x-list>', "'\"&"], '<x-list items="[&quot;\u003C\/x-list\u003E&quot;,&quot;\u0027\u0022\u0026&quot;]"></x-list>'];
        yield 'json script' => ['<script type="application/json">{v}</script>', ['a' => '</script><script>alert(1)</script>'], '<script type="application/json">{"a":"\u003C\/script\u003E\u003Cscript\u003Ealert(1)\u003C\/script\u003E"}</script>'];
        yield 'json() in script' => ['<script type="application/json">{json(v)}</script>', '<!--', '<script type="application/json">"\u003C!--"</script>'];
        yield 'json() in text' => ['<p>{json(v)}</p>', ['<b>'], '<p>[&quot;\u003Cb\u003E&quot;]</p>'];
        yield 'template syntax is data' => ['<p>{v}</p>', '{$secret} {@if 1}x{/if}', '<p>{$secret} {@if 1}x{/if}</p>'];
        yield 'safehtml as text is refused' => ['<p>{v ?? "x"}</p>', 'plain', '<p>plain</p>'];
    }

    #[DataProvider('xss')]
    public function testEscapingByContext(string $markup, mixed $value, string $expected): void
    {
        $this->assertSame($expected, $this->render(self::PROPS . $markup, ['v' => $value]));
    }

    public function testUnsafeUrlLogsAWarning(): void
    {
        $this->render(self::PROPS . '<a href={v}>x</a>', ['v' => 'javascript:x']);
        $this->assertCount(1, $this->warnings);
        $this->assertStringContainsString('Unsafe URL', $this->warnings[0]);
    }

    public function testAttributeValueShapes(): void
    {
        $html = $this->render(self::PROPS . '<input a={null} b={false} c={true} d={0} e={""} f={v} hidden />', ['v' => 2.5]);
        $this->assertSame('<input c d="0" e="" f="2.5" hidden>', $html);
    }

    public function testHtmlNeedsSafeHtml(): void
    {
        $src = "<props>\nreturn ['note' => Prop::html()->optional()];\n</props>\n<div>{html(note)}</div>";
        $this->assertSame('<div><b>ok</b></div>', $this->render($src, ['note' => new SafeHtml('<b>ok</b>')]));

        $this->expectException(RenderError::class);
        $this->expectExceptionMessage('Invalid prop: T.note must be a SafeHtml value, got string');
        $this->runtime()->render('T', ['note' => '<b>x</b>']);
    }

    public function testSafeHtmlPrintedAsTextIsRefused(): void
    {
        $this->expectException(RenderError::class);
        $this->expectExceptionMessageMatches('/SafeHtml value can only be printed with \{html/');
        $this->render(self::PROPS . '<p>{v}</p>', ['v' => new SafeHtml('<b>')]);
    }

    public function testPrintingAListIsAnErrorAtItsLine(): void
    {
        try {
            $this->render(self::PROPS . "<div>\n  <p>\n    {v}\n  </p>\n</div>", ['v' => [1, 2]]);
            $this->fail('printed an array');
        } catch (RenderError $e) {
            $this->assertStringContainsString('Cannot print array; use join() or json()', $e->getMessage());
            $this->assertStringEndsWith('T.rzx:6', $e->rzxLocation);
        }
    }

    public function testOperators(): void
    {
        $src = self::PROPS . <<<'RZX'
            <p>{1 + 2 - v}|{'a' ~ 2 ~ null ~ true}|{v == 1.0 ? 'eq' : 'ne'}|{w == '1' ? 'loose' : 'strict'}|{v < 2 && !w}|{w ?? 'dflt'}|{count([1, 2, 3])}|{-v}|{upper('x') ~ join(['a', 'b'], '-')}</p>
            RZX;
        $this->assertSame('<p>2|a2|eq|strict||dflt|3|-1|Xa-b</p>', $this->render($src, ['v' => 1, 'w' => null]));
    }

    public function testPathsReadArraysAndPublicPropertiesOnly(): void
    {
        $object = new class() {
            public string $name = 'pub';

            private string $secret = 'priv';

            public function __get(string $n): string
            {
                return 'magic';
            }

            public function secret(): string
            {
                return $this->secret;
            }
        };
        $src = self::PROPS . '<p>{v.name}|{v.secret}|{v.missing}|{w[1].k}|{w[9]}</p>';
        $this->assertSame('<p>pub|||b|</p>', $this->render($src, ['v' => $object, 'w' => [['k' => 'a'], ['k' => 'b']]]));
    }

    public function testControlFlow(): void
    {
        $src = self::PROPS . <<<'RZX'
            <ul>
              <if test={v == 1}><li>one</li></if>
              <elseif test={v == 2}><li>two</li></elseif>
              <else><li>other</li></else>
              <for each={w} as="row" index="i" key="id"><li>{i}:{row.id}</li><empty><li>none</li></empty></for>
            </ul>
            RZX;
        $this->assertSame('<ul><li>two</li><li>0:a</li><li>1:b</li></ul>', $this->render($src, ['v' => 2, 'w' => [['id' => 'a'], ['id' => 'b']]]));
        $this->assertSame('<ul><li>other</li><li>none</li></ul>', $this->runtime()->render('T', ['v' => 5, 'w' => []]));
    }

    public function testTruthinessFollowsTheGrammarNotPhp(): void
    {
        $src = self::PROPS . '<p><if test={v}>yes</if><else>no</else></p>';
        $rt = $this->runtime();
        $this->component('T', $src);
        $this->assertSame('<p>yes</p>', $rt->render('T', ['v' => '0']), "'0' is truthy");
        $this->assertSame('<p>no</p>', $rt->render('T', ['v' => []]));
        $this->assertSame('<p>no</p>', $rt->render('T', ['v' => '']));
    }

    public function testDuplicateLoopKeyIsADevelopmentError(): void
    {
        $src = self::PROPS . "<ul>\n<for each={v} as=\"r\" key=\"id\"><li>{r.id}</li></for>\n</ul>";
        $this->expectException(RenderError::class);
        $this->expectExceptionMessage("duplicate key 'a'");
        $this->render($src, ['v' => [['id' => 'a'], ['id' => 'a']]]);
    }

    public function testDuplicateLoopKeyIsIgnoredWhenChecksAreOff(): void
    {
        $this->component('T', self::PROPS . '<ul><for each={v} as="r" key="id"><li>{r.id}</li></for></ul>');
        $html = $this->runtime(null, false)->render('T', ['v' => [['id' => 'a'], ['id' => 'a']]]);
        $this->assertSame('<ul><li>a</li><li>a</li></ul>', $html);
    }

    public function testComponentsSlotsAndFills(): void
    {
        $this->component('Kit.Card', <<<'RZX'
            <props>
            return ['title' => Prop::string()->required(), 'tone' => Prop::enum('plain', 'warn')->default('plain')];
            </props>
            <section data-tone={tone}>
              <h2>{title}</h2>
              <Slot name="actions" accepts="button" max="2"><button>default action</button></Slot>
              <Slot />
            </section>
            RZX);
        $this->component('Page', <<<'RZX'
            <props>
            return ['user' => Prop::shape(['name' => Prop::string()])->required(), 'admin' => Prop::bool()->default(false)];
            </props>
            <main>
              <Kit.Card title={'Hello ' ~ user.name}>
                <if test={admin}>
                  <Fill slot="actions"><button>Edit {user.name}</button></Fill>
                </if>
                <p>Body for {user.name}</p>
              </Kit.Card>
            </main>
            RZX);
        $rt = $this->runtime();
        $this->assertSame(
            '<main><section data-tone="plain"><h2>Hello &lt;i&gt;</h2><button>Edit &lt;i&gt;</button><p>Body for &lt;i&gt;</p></section></main>',
            $rt->render('Page', ['user' => ['name' => '<i>'], 'admin' => true]),
        );
        $this->assertSame(
            '<main><section data-tone="plain"><h2>Hello a</h2><button>default action</button><p>Body for a</p></section></main>',
            $rt->render('Page', ['user' => ['name' => 'a']]),
        );
    }

    public function testDynamicSlotsInALoop(): void
    {
        $this->component('Kit.Tabs', <<<'RZX'
            <props>
            return ['tabs' => Prop::list(Prop::shape(['id' => Prop::string()]))->required()];
            </props>
            <div><for each={tabs} as="t" key="id"><section id={t.id}><Slot name={'tab:' ~ t.id} /></section></for></div>
            RZX);
        $this->component('Page', <<<'RZX'
            <props>
            return ['ids' => Prop::list(Prop::string())->required()];
            </props>
            <Kit.Tabs tabs={[{id: 'a'}, {id: 'b'}]}>
              <for each={ids} as="id"><Fill slot={'tab:' ~ id}><p>{id}</p></Fill></for>
            </Kit.Tabs>
            RZX);
        $this->assertSame('<div><section id="a"><p>a</p></section><section id="b"><p>b</p></section></div>', $this->runtime()->render('Page', ['ids' => ['a', 'b']]));
    }

    public function testPropDefaultsAndRuntimeChecks(): void
    {
        $this->component('T', "<props>\nreturn ['n' => Prop::int()->min(1)->max(3)->default(2), 'kind' => Prop::enum('a', 'b')->required()];\n</props>\n<p>{n}{kind}</p>");
        $rt = $this->runtime();
        $this->assertSame('<p>2a</p>', $rt->render('T', ['kind' => 'a']));
        $this->assertSame('<p>2a</p>', $rt->render('T', ['kind' => 'a', 'n' => null]), 'null takes the default');

        try {
            $rt->render('T', ['kind' => 'c']);
            $this->fail('enum not checked');
        } catch (RenderError $e) {
            $this->assertStringContainsString("T.kind must be one of 'a', 'b', got string", $e->getMessage());
        }

        $this->expectExceptionMessage('T.n must be at most 3');
        $rt->render('T', ['kind' => 'a', 'n' => 9]);
    }

    public function testHooksBecomeOneStyleAttribute(): void
    {
        $html = $this->render(self::PROPS . "<x-card hooks={{ '--card-gap': '8px', '--card-w': v, '--bad': 'url(x)' }}></x-card>", ['v' => 'calc(100% - 2px)']);
        $this->assertSame('<x-card style="--card-gap:8px;--card-w:calc(100% - 2px)"></x-card>', $html);
        $this->assertStringContainsString('--bad dropped', $this->warnings[0]);
    }

    public function testLayoutDoctypeAnchorsAndPushes(): void
    {
        $this->component('T', <<<'RZX'
            <props>
            return ['title' => Prop::string()->required(), 'on' => Prop::bool()->default(false)];
            </props>
            <html lang="en">
              <head><title>{title}</title><Anchor name="head.styles" /></head>
              <body><Slot /><Anchor name="body.scripts" /></body>
            </html>
            <if test={on}>
              <push to="head.styles" key="theme" order={-1}><link rel="stylesheet" href="/t.css" /></push>
            </if>
            <push to="body.scripts"><script type="module" src={'/' ~ title ~ '.js'} /></push>
            RZX);
        $html = $this->runtime()->render('T', ['title' => 'a<b'], ['' => new SafeHtml('<main>page</main>')]);
        $this->assertSame('<!DOCTYPE html><html lang="en"><head><title>a&lt;b</title><!--anchor:head.styles--></head><body><main>page</main><!--anchor:body.scripts--></body></html>', $html);
        $this->assertSame([['body.scripts', '<script type="module" src="/a&lt;b.js"></script>', null, 0]], $this->sink->pushes, 'false <if> pushes nothing');

        $this->sink->pushes = [];
        $this->runtime()->render('T', ['title' => 'x', 'on' => true]);
        $this->assertSame(['head.styles', '<link rel="stylesheet" href="/t.css">', 'theme', -1], $this->sink->pushes[0]);
    }

    public function testPushWithoutAnchorsIsAnError(): void
    {
        $this->component('T', '<p><push to="body.scripts"><b>x</b></push></p>');
        $rt = new Runtime($this->cache());
        $this->expectException(RenderError::class);
        $this->expectExceptionMessage('needs a page layout');
        $rt->render('T');
    }

    public function testDataAttributesStartingWithOnAreNotHandlers(): void
    {
        $this->assertSame('<p onboarding-step="2" data-on="x"></p>', $this->render('<p onboarding-step="2" data-on="x"></p>'));
    }

    public function testSelfClosedCustomElementGetsAnEndTag(): void
    {
        $this->assertSame('<x-a></x-a><br><p></p>', \str_replace(['<div>', '</div>'], '', $this->render('<div><x-a /><br/><p /></div>')));
    }
}
