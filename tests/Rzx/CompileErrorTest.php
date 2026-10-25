<?php

declare(strict_types=1);

namespace Razy\Tests\Rzx;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Rzx\Compile\CodeGen;
use Razy\Rzx\Compile\ComponentCall;
use Razy\Rzx\Compile\ExprGen;
use Razy\Rzx\CompileError;
use Razy\Rzx\Compiler;
use Razy\Rzx\PropsReader;

/**
 * RZ-28b: what the compiler rejects, with the `.rzx` line:col. The point of
 * compiling is that these never reach a browser.
 */
#[CoversClass(CodeGen::class)]
#[CoversClass(ComponentCall::class)]
#[CoversClass(ExprGen::class)]
#[CoversClass(Compiler::class)]
#[CoversClass(PropsReader::class)]
class CompileErrorTest extends TestCase
{
    use RzxHarness;

    private const P = "<props>\nreturn ['a' => Prop::string(), 'user' => Prop::shape(['name' => Prop::string()]), 'rows' => Prop::list(Prop::shape(['id' => Prop::int()]))];\n</props>\n";

    /**
     * @return iterable<string, array{string, string, int, int, string}>
     */
    public static function errors(): iterable
    {
        // names and paths (line 4 is the first markup line after P)
        yield 'unknown name' => [self::P . '<p>{b}</p>', 'RZX101', 4, 5, 'Unknown name `b`'];
        yield 'no props block' => ['<p>{a}</p>', 'RZX101', 1, 5, 'Unknown name `a`'];
        yield 'unknown shape field' => [self::P . '<p>{user.mail}</p>', 'RZX102', 4, 5, 'No field `mail` in this shape (fields: name)'];
        yield 'loop item field' => [self::P . '<ul><for each={rows} as="r"><li>{r.name}</li></for></ul>', 'RZX102', 4, 34, 'No field `name`'];
        yield 'loop var outside loop' => [self::P . '<ul><for each={rows} as="r"></for>{r}</ul>', 'RZX101', 4, 36, 'Unknown name `r`'];
        yield 'loop shadows prop' => [self::P . '<ul><for each={rows} as="a"></for></ul>', 'RZX104', 4, 5, 'shadows'];
        yield 'unknown function' => [self::P . '<p>{nope(a)}</p>', 'RZX045', 4, 5, 'Unknown function `nope`'];
        yield 'count arity' => [self::P . '<p>{count(a, a)}</p>', 'RZX103', 4, 5, 'count() takes one argument'];
        yield 'html inside expression' => [self::P . "<p>{'x' ~ html(a)}</p>", 'RZX137', 4, 11, 'whole of a text expression'];
        yield 'html of string prop' => [self::P . '<p>{html(a)}</p>', 'RZX137', 4, 10, 'needs a SafeHtml value'];
        yield 'html of literal' => [self::P . "<p>{html('<b>')}</p>", 'RZX137', 4, 10, 'needs a SafeHtml value'];

        // markup safety
        yield 'style element' => [self::P . "<div>\n  <style>p{}</style></div>", 'RZX040', 5, 11, 'Empty expression'];
        yield 'style element empty' => [self::P . "<div>\n  <style></style></div>", 'RZX130', 5, 3, '<style> is not allowed'];
        yield 'style attribute' => [self::P . '<p style="color:red"></p>', 'RZX131', 4, 4, 'hooks={{'];
        yield 'event handler' => [self::P . '<button onClick={a}></button>', 'RZX132', 4, 9, 'Inline event handlers (onClick)'];
        yield 'srcdoc' => [self::P . '<iframe srcdoc={a}></iframe>', 'RZX135', 4, 9, 'srcdoc'];
        yield 'inline js' => [self::P . '<div><script>{a}</script></div>', 'RZX133', 4, 6, 'Inline JavaScript'];
        yield 'module inline js' => [self::P . '<div><script type="module">{a}</script></div>', 'RZX133', 4, 6, 'Inline JavaScript'];
        yield 'src script with body' => [self::P . '<div><script src="/a.js">{a}</script></div>', 'RZX133', 4, 26, 'must be empty'];
        yield 'json script text' => [self::P . '<div><script type="application/json">x</script></div>', 'RZX133', 4, 38, 'exactly one expression'];
        yield 'literal javascript url' => [self::P . '<a href="javascript:void(0)">x</a>', 'RZX134', 4, 4, 'Unsafe URL in href'];
        yield 'entity-encoded scheme' => [self::P . '<a href="javascript&#58;x">x</a>', 'RZX134', 4, 4, 'Unsafe URL in href'];
        yield 'entity-encoded scheme named' => [self::P . '<a HREF="jav&Tab;ascript&colon;x">x</a>', 'RZX134', 4, 4, 'Unsafe URL in HREF'];
        yield 'literal data src' => [self::P . '<img src=" data:image/svg+xml,x" />', 'RZX134', 4, 6, 'Unsafe URL in src'];
        yield 'hooks not a map' => [self::P . '<p hooks={a}></p>', 'RZX136', 4, 4, 'hooks takes a map'];
        yield 'hook name' => [self::P . "<p hooks={{ color: 'red' }}></p>", 'RZX136', 4, 13, 'literal custom property'];
        yield 'nested push' => [self::P . '<p><push to="a"><push to="b"></push></push></p>', 'RZX140', 4, 17, 'cannot be nested'];
        yield 'fill outside component' => [self::P . '<p><Fill slot="x"></Fill></p>', 'RZX124', 4, 4, 'direct child of a component'];
        yield 'duplicate slot' => [self::P . '<p><Slot name="x" /><Slot name="x" /></p>', 'RZX126', 4, 21, 'declared twice'];
        yield 'two default slots' => [self::P . '<p><Slot /><Slot /></p>', 'RZX126', 4, 12, 'The default slot is declared twice'];

        // component use
        yield 'unknown component' => [self::P . "<div>\n  <Kit.Nope /></div>", 'RZX110', 5, 3, 'Unknown component <Kit.Nope>'];
        yield 'unknown prop' => [self::P . '<Kit.Card title="t" size="x" />', 'RZX111', 4, 21, 'Unknown prop size on <Kit.Card>; props: title, tone, count'];
        yield 'prop on propless' => [self::P . '<Kit.Bare x="1" />', 'RZX111', 4, 11, 'it takes no props'];
        yield 'missing required' => [self::P . '<Kit.Card />', 'RZX112', 4, 1, 'needs the required prop title'];
        yield 'enum literal' => [self::P . '<Kit.Card title="t" tone="loud" />', 'RZX113', 4, 21, "tone must be one of 'calm', 'warn'"];
        yield 'int as string' => [self::P . '<Kit.Card title="t" count="3" />', 'RZX113', 4, 21, 'write count={…}'];
        yield 'int out of range' => [self::P . '<Kit.Card title="t" count={99} />', 'RZX113', 4, 21, 'count must be at most 10'];
        yield 'list literal type' => [self::P . '<Kit.Card title={[1]} />', 'RZX113', 4, 11, 'title must be a string'];
        yield 'unknown slot' => [self::P . '<Kit.Card title="t"><Fill slot="footer"></Fill></Kit.Card>', 'RZX120', 4, 21, 'has no slot "footer" (slots: actions)'];
        yield 'no default slot' => [self::P . '<Kit.Bare><p>x</p></Kit.Bare>', 'RZX120', 4, 11, 'has no default <Slot />'];
        yield 'not accepted' => [self::P . '<Kit.Card title="t"><Fill slot="actions"><a>x</a></Fill></Kit.Card>', 'RZX121', 4, 42, 'accepts button; found <a>'];
        yield 'text not accepted' => [self::P . '<Kit.Card title="t"><Fill slot="actions">{a}</Fill></Kit.Card>', 'RZX121', 4, 42, 'found text or an expression'];
        yield 'default not accepted' => [self::P . "<Kit.Card title=\"t\">\n  <div></div>\n</Kit.Card>", 'RZX121', 5, 3, 'the default slot of <Kit.Card> accepts p'];
        yield 'over max' => [self::P . '<Kit.Card title="t"><Fill slot="actions"><button /><button /><button /></Fill></Kit.Card>', 'RZX122', 4, 42, 'at most 2 children, got 3'];
        yield 'over max via if' => [self::P . '<Kit.Card title="t"><Fill slot="actions"><button /><if test={a}><button /><button /></if></Fill></Kit.Card>', 'RZX122', 4, 42, 'got 3'];
        yield 'loop into max' => [self::P . '<Kit.Card title="t"><Fill slot="actions"><for each={rows} as="r"><button /></for></Fill></Kit.Card>', 'RZX125', 4, 42, 'count must be known'];
        yield 'filled twice' => [self::P . '<Kit.Card title="t"><Fill slot="actions"></Fill><Fill slot="actions"></Fill></Kit.Card>', 'RZX123', 4, 49, 'already filled'];
        yield 'fill mixed into if' => [self::P . '<Kit.Card title="t"><if test={a}><Fill slot="actions"></Fill><p></p></if></Kit.Card>', 'RZX124', 4, 21, 'direct child of <Kit.Card>'];
        yield 'computed fill name' => [self::P . "<Kit.Card title=\"t\"><Fill slot={'x' ~ a}></Fill></Kit.Card>", 'RZX120', 4, 21, 'no slots with computed names'];
        yield 'cycle' => [self::P . '<Kit.Loop />', 'RZX114', 1, 1, 'cycle: T → Kit.Loop → Kit.Back → Kit.Loop'];

        // props schema
        yield 'props not return' => ["<props>\n\$x = 1;\n</props><p></p>", 'RZX060', 2, 1, 'return [ … ];'];
        yield 'props php call' => ["<props>\nreturn ['a' => system('id')];\n</props><p></p>", 'RZX062', 2, 16, 'found `system`'];
        yield 'props unknown type' => ["<props>\nreturn ['a' => Prop::date()];\n</props><p></p>", 'RZX063', 2, 22, 'Unknown Prop::date()'];
        yield 'props unknown modifier' => ["<props>\nreturn ['a' => Prop::int()->exec('x')];\n</props><p></p>", 'RZX063', 2, 29, 'Unknown modifier ->exec()'];
        yield 'props bad default' => ["<props>\nreturn ['a' => Prop::int()->default('x')];\n</props><p></p>", 'RZX064', 2, 29, 'default must be a int'];
        yield 'props bad enum default' => ["<props>\nreturn ['s' => Prop::enum('a')->default('b')];\n</props><p></p>", 'RZX064', 2, 33, "default must be one of 'a'"];
        yield 'props not a chain' => ["<props>\nreturn ['a' => 'string'];\n</props><p></p>", 'RZX061', 2, 9, 'must be a Prop:: chain'];
        yield 'props bad name' => ["<props>\nreturn ['a-b' => Prop::int()];\n</props><p></p>", 'RZX061', 2, 9, 'Prop names must be identifiers'];
        yield 'props duplicate' => ["<props>\nreturn ['a' => Prop::int(), 'a' => Prop::int()];\n</props><p></p>", 'RZX061', 2, 29, 'Duplicate key'];
        yield 'props interpolation' => ["<props>\nreturn ['a' => Prop::enum(\"\$x\")];\n</props><p></p>", 'RZX062', 2, 28, 'Variables are not allowed'];
        yield 'props trailing code' => ["<props>\nreturn []; echo 1;\n</props><p></p>", 'RZX060', 2, 12, 'Unexpected code after'];
        yield 'props new' => ["<props>\nreturn [new Foo()];\n</props><p></p>", 'RZX062', 2, 9, 'found `new`'];
    }

    #[DataProvider('errors')]
    public function testCompileErrors(string $source, string $code, int $line, int $column, string $message): void
    {
        $this->component('Kit.Card', <<<'RZX'
            <props>
            return ['title' => Prop::string()->required(), 'tone' => Prop::enum('calm', 'warn')->default('calm'), 'count' => Prop::int()->max(10)];
            </props>
            <section><Slot name="actions" accepts="button" max="2" /><Slot accepts="p" /></section>
            RZX);
        $this->component('Kit.Bare', '<hr />');
        $this->component('Kit.Loop', '<div><Kit.Back /></div>');
        $this->component('Kit.Back', '<div><Kit.Loop /></div>');
        $this->component('T', $source);

        try {
            $this->runtime()->render('T', []);
            $this->fail('compiled: ' . $source);
        } catch (CompileError $e) {
            $this->assertSame($code, $e->rzxCode, $e->getMessage());
            $this->assertStringContainsString($message, $e->getMessage());
            if ($code !== 'RZX114') {
                $this->assertSame([$line, $column], [$e->sourceLine, $e->sourceColumn], $e->getMessage());
                $this->assertStringEndsWith('T.rzx', $e->source->file);
            }
        }
    }

    public function testErrorInAUsedComponentNamesThatComponentsFile(): void
    {
        $this->component('Kit.Broken', "<div>\n  {missing}\n</div>");
        $this->component('T', '<main><Kit.Broken /></main>');

        try {
            $this->runtime()->render('T');
            $this->fail('compiled');
        } catch (CompileError $e) {
            $this->assertSame('RZX101', $e->rzxCode);
            $this->assertStringEndsWith('kit/Broken.rzx', $e->source->file);
            $this->assertSame(2, $e->sourceLine);
        }
    }
}
