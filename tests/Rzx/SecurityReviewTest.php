<?php

declare(strict_types=1);

namespace Razy\Tests\Rzx;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Rzx\Compile\CodeGen;
use Razy\Rzx\Compile\ComponentCall;
use Razy\Rzx\CompileCache;
use Razy\Rzx\CompileError;
use Razy\Rzx\Prop;
use Razy\Rzx\Runtime\Escape;
use Razy\Rzx\Runtime\RenderError;
use Razy\Rzx\Runtime\Runtime;

/**
 * RZ-28b: regressions for the security and correctness review of #35.
 */
#[CoversClass(CodeGen::class)]
#[CoversClass(ComponentCall::class)]
#[CoversClass(Escape::class)]
#[CoversClass(Runtime::class)]
#[CoversClass(CompileCache::class)]
#[CoversClass(Prop::class)]
class SecurityReviewTest extends TestCase
{
    use RzxHarness;

    private const P = "<props>\nreturn ['v' => Prop::any()];\n</props>\n";

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function svgAnimation(): iterable
    {
        yield 'set href' => ['<svg><a><set attributeName="href" to="javascript:alert(1)" /></a></svg>', 'cannot animate href'];
        yield 'animate xlink:href' => ['<svg><a><animate attributeName="xlink:href" values="javascript:x" /></a></svg>', 'cannot animate xlink:href'];
        yield 'case and spaces' => ['<svg><a><set attributeName=" HREF " to="#" /></a></svg>', 'cannot animate'];
        yield 'dynamic target' => ['<svg><animate attributeName={v} to="1" /></svg>', 'attributeName must be a literal'];
        yield 'dynamic to' => ['<svg><animate attributeName="opacity" to={v} /></svg>', 'to must be a literal'];
        yield 'dynamic values' => ['<svg><animatemotion values={v} /></svg>', 'values must be a literal'];
        yield 'dynamic from' => ['<svg><set attributeName="fill" from={v} /></svg>', 'from must be a literal'];
        yield 'dynamic by' => ['<svg><animatetransform attributeName="transform" by={v} /></svg>', 'by must be a literal'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function navigation(): iterable
    {
        yield 'dynamic base' => ['<html><head><base href={v} /></head></html>', 'RZX139'];
        yield 'dynamic base target' => ['<html><head><base target={v} /></head></html>', 'RZX139'];
        yield 'dynamic refresh content' => ['<html><head><meta http-equiv="refresh" content={v} /></head></html>', 'RZX139'];
        yield 'dynamic http-equiv' => ['<html><head><meta http-equiv={v} content="0" /></head></html>', 'RZX139'];
        yield 'literal javascript refresh' => ['<html><head><meta http-equiv="Refresh" content="0; URL=\'javascript:alert(1)\'" /></head></html>', 'RZX134'];
        yield 'entity refresh' => ['<html><head><meta http-equiv="refresh" content="0;url=javascript&#58;x" /></head></html>', 'RZX134'];
    }

    #[DataProvider('svgAnimation')]
    public function testSvgAnimationCannotWriteALink(string $markup, string $message): void
    {
        try {
            $this->render(self::P . $markup, ['v' => 'javascript:alert(1)']);
            $this->fail('compiled: ' . $markup);
        } catch (CompileError $e) {
            $this->assertSame('RZX138', $e->rzxCode);
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function testLiteralSvgAnimationStillWorks(): void
    {
        $html = $this->render('<svg><circle r="4"><animate attributeName="opacity" values="0;1" dur="1s" /></circle></svg>');
        $this->assertSame('<svg><circle r="4"><animate attributeName="opacity" values="0;1" dur="1s"></animate></circle></svg>', $html);
    }

    public function testSrcsetCandidatesAreCheckedOneByOne(): void
    {
        $html = $this->render(self::P . '<img srcset={v} />', ['v' => 'a.png 1x, javascript:alert(1) 2x,  https://cdn.test/b.png 640w']);
        $this->assertSame('<img srcset="a.png 1x, # 2x, https://cdn.test/b.png 640w">', $html);
        $this->assertStringContainsString('Unsafe URL', $this->warnings[0]);
        $this->assertSame('<link imagesrcset="#">', $this->render(self::P . '<link imagesrcset={v} />', ['v' => 'data:image/svg+xml;utf8,x']));
        $this->assertSame('<img srcset="a.png 1x, b.png 2x">', $this->render('<img srcset="a.png 1x, b.png 2x" />'), 'a safe literal is kept as written');

        $this->expectException(CompileError::class);
        $this->expectExceptionMessage('RZX134 Unsafe URL in srcset');
        $this->render('<img srcset="a.png 1x, javascript&#58;x 2x" />');
    }

    public function testFalsyDefaultsSurviveTheRoundTrip(): void
    {
        foreach ([false, 0, '', [], 0.0] as $default) {
            $prop = Prop::fromArray(Prop::any()->required()->default($default)->toArray());
            $this->assertTrue($prop->hasDefault(), \var_export($default, true));
            $this->assertSame($default, $prop->defaultValue());
        }
        $this->component('Kit.Flag', "<props>\nreturn ['on' => Prop::bool()->required()->default(false), 'tags' => Prop::list()->default([])];\n</props>\n<i data-on={on ? 'yes' : 'no'}>{count(tags)}</i>");
        $this->component('T', '<p><Kit.Flag /></p>');
        $this->assertSame('<p><i data-on="no">0</i></p>', $this->runtime()->render('T'));
    }

    public function testFailedPropChecksDoNotLeakDepth(): void
    {
        $this->component('T', "<props>\nreturn ['n' => Prop::int()->required()];\n</props>\n<p>{n}</p>");
        $runtime = $this->runtime();
        for ($i = 0; $i < 70; ++$i) {
            try {
                $runtime->render('T', ['n' => 'x']);
            } catch (RenderError) {
            }
        }
        $this->assertSame('<p>1</p>', $runtime->render('T', ['n' => 1]));
    }

    public function testAnErrorInAFillPointsAtTheFill(): void
    {
        $this->component('Kit.Wrap', "<div>\n  <Slot />\n</div>");
        $this->component('T', self::P . "<Kit.Wrap>\n  <p>\n    {v}\n  </p>\n</Kit.Wrap>");
        try {
            $this->runtime()->render('T', ['v' => [1]]);
            $this->fail('printed an array');
        } catch (RenderError $e) {
            $this->assertStringEndsWith('app/T.rzx:6', $e->rzxLocation);
        }
    }

    public function testFillLoopsBindLikeMarkupLoops(): void
    {
        $this->component('Kit.Tabs', "<props>\nreturn ['n' => Prop::int()->required()];\n</props>\n<div><for each={[0, 1]} as=\"k\"><Slot name={'tab:' ~ k} /></for><Slot name=\"none\" /></div>");
        $this->component('T', <<<'RZX'
            <props>
            return ['items' => Prop::list(Prop::shape(['label' => Prop::string()]))->required()];
            </props>
            <Kit.Tabs n={2}>
              <for each={items} as="item" index="i" key="label"><Fill slot={'tab:' ~ i}><b>{item.label}</b></Fill><empty><Fill slot="none"><i>empty</i></Fill></empty></for>
            </Kit.Tabs>
            RZX);
        $runtime = $this->runtime();
        $this->assertSame('<div><b>a</b><b>b</b></div>', $runtime->render('T', ['items' => [['label' => 'a'], ['label' => 'b']]]));
        $this->assertSame('<div><i>empty</i></div>', $runtime->render('T', ['items' => []]));

        $this->component('T', "<props>\nreturn ['items' => Prop::list()];\n</props>\n<Kit.Tabs n={1}><for each={items} as=\"items\"><Fill slot=\"none\"></Fill></for></Kit.Tabs>");
        $this->expectException(CompileError::class);
        $this->expectExceptionMessage('RZX104');
        $this->runtime()->render('T', ['items' => []]);
    }

    public function testContentChangeWithARestoredMtimeIsSeen(): void
    {
        $path = $this->component('T', '<p>aaa</p>');
        $this->runtime()->render('T');
        $mtime = \filemtime($path);
        \file_put_contents($path, '<p>bbb</p>');
        \touch($path, (int) $mtime);
        \clearstatcache();

        $this->assertSame('<p>bbb</p>', $this->runtime($this->cache())->render('T'), 'dev mode recompiles on content');

        $this->cache()->build(['T']);
        \file_put_contents($path, '<p>ccc</p>');
        \touch($path, (int) $mtime);
        \clearstatcache();
        $this->expectException(RenderError::class);
        $this->expectExceptionMessage('T is stale');
        $this->runtime($this->cache(null, 'compiled'))->render('T');
    }

    public function testCompiledModeChecksDependencies(): void
    {
        $this->component('Kit.B', '<b>1</b>');
        $this->component('T', '<p><Kit.B /></p>');
        $this->cache()->build(['T']);
        $this->component('Kit.B', '<b>2</b>');

        $this->expectException(RenderError::class);
        $this->expectExceptionMessage('Kit.B is stale');
        $this->runtime($this->cache(null, 'compiled'))->render('T');
    }

    public function testConcurrentBuildsKeepEachOthersEntries(): void
    {
        foreach (['A', 'B', 'C'] as $name) {
            $this->component($name, '<p>' . $name . '</p>');
        }
        $first = $this->cache();
        $first->load('A');              // reads and memoizes an index holding A
        $this->cache()->load('B');      // another process adds B
        $first->load('C');              // the first process writes again

        $index = (include $this->root . '/cache/index.php')['components'];
        $this->assertSame(['A', 'B', 'C'], \array_keys($index));
    }

    public function testCacheFilesAreNotWorldWritable(): void
    {
        $umask = \umask(0);

        try {
            $this->component('T', '<p>x</p>');
            $this->runtime()->render('T');
        } finally {
            \umask($umask);
        }
        $this->assertSame('755', \substr(\sprintf('%o', \fileperms($this->root . '/cache')), -3));
        $this->assertSame('644', \substr(\sprintf('%o', \fileperms($this->root . '/cache/index.php')), -3));
        $this->assertSame('644', \substr(\sprintf('%o', \fileperms(\glob($this->root . '/cache/T.*.php')[0])), -3));
    }

    public function testLoadIncludesOnlyCacheArtifacts(): void
    {
        $this->component('T', '<p>x</p>');
        $this->runtime()->render('T');
        $file = $this->root . '/cache/index.php';
        $index = include $file;
        $index['components']['T']['file'] = '../app/evil.php';
        \file_put_contents($this->root . '/app/evil.php', '<?php throw new \LogicException("included");');
        \file_put_contents($file, '<?php return ' . \var_export($index, true) . ';');

        $this->expectException(RenderError::class);
        $this->expectExceptionMessage('unexpected file');
        $this->runtime($this->cache(null, 'compiled'))->render('T');
    }

    #[DataProvider('navigation')]
    public function testNavigationSinksTakeLiteralsOnly(string $markup, string $code): void
    {
        try {
            $this->render(self::P . $markup, ['v' => 'https://evil.test/']);
            $this->fail('compiled: ' . $markup);
        } catch (CompileError $e) {
            $this->assertSame($code, $e->rzxCode, $e->getMessage());
        }
    }

    public function testLiteralNavigationAndOtherUrlSinks(): void
    {
        $this->assertStringContainsString('<base href="/app/"><meta http-equiv="refresh" content="30;url=/next"><meta name="x" content="y">', $this->render('<html><head><base href="/app/" /><meta http-equiv="refresh" content="30;url=/next" /><meta name="x" content="y" /></head></html>'));
        $html = $this->render(self::P . '<div><svg><use href={v} /><image xlink:href={v} /><feimage href={v} /></svg><math><mi href={v}>x</mi></math><object data={v} classid={v}></object><embed src={v} /><iframe src={v}></iframe><a ping={v}>p</a><video poster={v}></video><img lowsrc={v} /></div>', ['v' => 'javascript:alert(1)']);
        $this->assertStringNotContainsString('javascript', $html);
        $this->assertSame(11, \substr_count($html, '="#"'), 'every one of the 11 URL attributes');
    }
}
