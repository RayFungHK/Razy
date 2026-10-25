<?php

declare(strict_types=1);

namespace Razy\Tests\Rzx;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Rzx\CompileCache;
use Razy\Rzx\CompileError;
use Razy\Rzx\Compiler;
use Razy\Rzx\FunctionRegistry;
use Razy\Rzx\Runtime\RenderError;

/**
 * RZ-28b: the build-time cache. Checksums decide recompiles, dependencies
 * invalidate their users, and production mode never compiles.
 */
#[CoversClass(CompileCache::class)]
#[CoversClass(Compiler::class)]
class CompileCacheTest extends TestCase
{
    use RzxHarness;

    public function testFirstLoadWritesTheIndexAndOneFile(): void
    {
        $path = $this->component('T', '<p>one</p>');
        $this->assertSame('<p>one</p>', $this->runtime()->render('T'));

        $index = include $this->root . '/cache/index.php';
        $this->assertSame('rzx-1', $index['schema']);
        $this->assertSame(Compiler::VERSION, $index['compiler']);
        $entry = $index['components']['T'];
        $this->assertSame($path, $entry['source']);
        $this->assertSame(\hash_file('sha256', $path), $entry['sha']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $entry['hash']);
        $this->assertSame('T.' . \substr($entry['hash'], 0, 16) . '.php', $entry['file']);
        $this->assertFileExists($this->root . '/cache/' . $entry['file']);
        $this->assertSame([], \glob($this->root . '/cache/*.tmp'));
    }

    public function testUnchangedSourceIsNotRecompiled(): void
    {
        $this->component('T', '<p>one</p>');
        $this->runtime()->render('T');
        $file = \glob($this->root . '/cache/T.*.php')[0];
        \touch($file, 1_000_000);
        \clearstatcache();

        $this->assertSame('<p>one</p>', $this->runtime($this->cache())->render('T'));
        \clearstatcache();
        $this->assertSame(1_000_000, \filemtime($file), 'a fresh entry is reused, not rewritten');
    }

    public function testEditedSourceIsRecompiledInDevMode(): void
    {
        $this->component('T', '<p>one</p>');
        $this->runtime()->render('T');
        $before = \glob($this->root . '/cache/T.*.php');

        $this->component('T', '<p>two!</p>');
        $this->assertSame('<p>two!</p>', $this->runtime($this->cache())->render('T'));
        $after = \glob($this->root . '/cache/T.*.php');
        $this->assertCount(1, $after, 'the stale file is removed');
        $this->assertNotSame($before, $after);
    }

    public function testChangingAComponentRecompilesItsUsers(): void
    {
        $this->component('Kit.Badge', "<props>\nreturn ['label' => Prop::string()->required()];\n</props>\n<b>{label}</b>");
        $this->component('T', '<p><Kit.Badge label="x" /></p>');
        $this->assertSame('<p><b>x</b></p>', $this->runtime()->render('T'));
        $hash = (include $this->root . '/cache/index.php')['components']['T']['hash'];

        // The badge now requires a prop T does not pass: T must be recompiled and fail.
        $this->component('Kit.Badge', "<props>\nreturn ['label' => Prop::string()->required(), 'tone' => Prop::string()->required()];\n</props>\n<b>{label}</b>");

        try {
            $this->runtime($this->cache())->render('T');
            $this->fail('the user of a changed component was not rechecked');
        } catch (CompileError $e) {
            $this->assertSame('RZX112', $e->rzxCode);
            $this->assertStringEndsWith('T.rzx', $e->source->file);
        }

        $this->component('T', '<p><Kit.Badge label="x" tone="y" /></p>');
        $this->runtime($this->cache())->render('T');
        $this->assertNotSame($hash, (include $this->root . '/cache/index.php')['components']['T']['hash']);
    }

    public function testRegisteringAFunctionInvalidatesTheCache(): void
    {
        $this->component('T', '<p>x</p>');
        $this->runtime()->render('T');
        $hash = (include $this->root . '/cache/index.php')['components']['T']['hash'];

        $functions = $this->functions()->register('extra', static fn (): string => 'e');
        (new CompileCache(new \Razy\Rzx\DirectoryResolver(['' => $this->root . '/app']), $this->root . '/cache', $functions))->load('T');
        $this->assertNotSame($hash, (include $this->root . '/cache/index.php')['components']['T']['hash']);
    }

    public function testCompiledModeNeverCompiles(): void
    {
        $this->component('T', '<p>one</p>');
        try {
            $this->runtime($this->cache(null, 'compiled'))->render('T');
            $this->fail('compiled at request time');
        } catch (RenderError $e) {
            $this->assertStringContainsString('T is not compiled; run php Razy.phar compile --rzx at deploy', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->root . '/cache/index.php');

        $hashes = $this->cache()->build(['T']);
        $this->assertArrayHasKey('T', $hashes);
        $this->assertSame('<p>one</p>', $this->runtime($this->cache(null, 'compiled'))->render('T'));

        $this->component('T', '<p>edited</p>');
        $this->expectException(RenderError::class);
        $this->expectExceptionMessage('T is stale');
        $this->runtime($this->cache(null, 'compiled'))->render('T');
    }

    public function testBuildFailsOnACompileError(): void
    {
        $this->component('Good', '<p>ok</p>');
        $this->component('Bad', '<p>{nope}</p>');
        $this->expectException(CompileError::class);
        $this->cache()->build(['Good', 'Bad']);
    }

    public function testCorruptIndexIsRebuilt(): void
    {
        \mkdir($this->root . '/cache');
        \file_put_contents($this->root . '/cache/index.php', '<?php return "garbage";');
        $this->component('T', '<p>ok</p>');
        $this->assertSame('<p>ok</p>', $this->runtime()->render('T'));
        $this->assertSame('rzx-1', (include $this->root . '/cache/index.php')['schema']);
    }

    public function testUnknownAndInvalidNames(): void
    {
        $runtime = $this->runtime();
        try {
            $runtime->render('Missing');
            $this->fail('rendered an unknown component');
        } catch (RenderError $e) {
            $this->assertStringContainsString('Unknown .rzx component Missing', $e->getMessage());
        }
        $this->expectExceptionMessage('Invalid .rzx component name: ../etc/passwd');
        $runtime->render('../etc/passwd');
    }

    public function testAComponentMayRenderItself(): void
    {
        $this->component('Tree', <<<'RZX'
            <props>
            return ['node' => Prop::shape(['name' => Prop::string(), 'kids' => Prop::list()])->required()];
            </props>
            <li>{node.name}<if test={count(node.kids)}><ul><for each={node.kids} as="k"><Tree node={k} /></for></ul></if></li>
            RZX);
        $tree = ['name' => 'a', 'kids' => [['name' => 'b', 'kids' => [['name' => 'c', 'kids' => []]]]]];
        $this->assertSame('<li>a<ul><li>b<ul><li>c</li></ul></li></ul></li>', $this->runtime()->render('Tree', ['node' => $tree]));
    }

    public function testRendersAreIndependentInOneRuntime(): void
    {
        $this->component('T', "<props>\nreturn ['n' => Prop::int()->required()];\n</props>\n<ul><for each={[1, 2]} as=\"i\"><li>{i + n}</li></for></ul>");
        $runtime = $this->runtime();
        $this->assertSame('<ul><li>11</li><li>12</li></ul>', $runtime->render('T', ['n' => 10]));
        $this->assertSame('<ul><li>2</li><li>3</li></ul>', $runtime->render('T', ['n' => 1]), 'no state carried over');
    }

    public function testCustomFunctionsAreCalledWithValues(): void
    {
        $functions = (new FunctionRegistry())->register('greet', static fn (string $who): string => 'Hi <' . $who . '>');
        $this->component('T', '<p>{greet("x")}</p>');
        $cache = new CompileCache(new \Razy\Rzx\DirectoryResolver(['' => $this->root . '/app']), $this->root . '/cache', $functions);
        $runtime = new \Razy\Rzx\Runtime\Runtime($cache, $functions);
        $this->assertSame('<p>Hi &lt;x&gt;</p>', $runtime->render('T'), 'a function result is escaped like any value');
    }
}
