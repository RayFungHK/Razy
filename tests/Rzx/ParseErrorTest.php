<?php

declare(strict_types=1);

namespace Razy\Tests\Rzx;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Exception\TemplateException;
use Razy\Rzx\CompileError;
use Razy\Rzx\Lexer;
use Razy\Rzx\Parser;
use Razy\Rzx\Source;
use Razy\Rzx\TagRules;

/**
 * RZ-28a: malformed `.rzx` markup fails at compile time with a stable code,
 * the exact line:col, and a code frame. Nothing malformed becomes text.
 */
#[CoversClass(CompileError::class)]
#[CoversClass(Parser::class)]
#[CoversClass(Lexer::class)]
#[CoversClass(TagRules::class)]
class ParseErrorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, int, int, string}>
     */
    public static function malformed(): iterable
    {
        // lexer
        yield 'unterminated tag' => ["<div>\n  <p class=\"a\"", 'RZX001', 2, 3, 'Unterminated tag <p>'];
        yield 'bare less-than' => ['<p>a < b</p>', 'RZX002', 1, 6, 'write &lt;'];
        yield 'stray close brace' => ['<p>a } b</p>', 'RZX003', 1, 6, "{'}'}"];
        yield 'unterminated attr value' => ['<p title="abc></p>', 'RZX004', 1, 10, 'Unterminated attribute value for title'];
        yield 'unterminated comment' => ["<p>{/* never closed</p>\n", 'RZX005', 1, 4, 'Unterminated comment'];
        yield 'html comment' => ["<p>\n  <!-- note -->\n</p>", 'RZX006', 2, 3, 'HTML comments are not allowed'];
        yield 'doctype' => ['<!DOCTYPE html><html></html>', 'RZX006', 1, 1, 'doctype'];
        yield 'close tag without name' => ['<p></ >', 'RZX007', 1, 6, 'Expected a tag name after `</`'];
        yield 'close tag junk' => ['<p></p x>', 'RZX007', 1, 8, 'Expected `>`'];
        yield 'unterminated props' => ["<props>\nreturn [];\n", 'RZX008', 1, 1, 'expected </props>'];
        yield 'props with spaced close' => ["<props>\nreturn [];\n</props >", 'RZX024', 3, 10, 'needs exactly one root'];
        yield 'slash without gt' => ['<br / >', 'RZX009', 1, 5, 'Expected `/>`'];
        yield 'attr junk' => ['<p #id></p>', 'RZX010', 1, 4, 'Unexpected `#` in <p>'];
        yield 'attr missing value' => ['<p title=></p>', 'RZX010', 1, 10, 'Expected a quoted value'];
        yield 'spread attr' => ['<Card {...props} />', 'RZX011', 1, 7, 'Spread attributes'];
        yield 'nameless expr attr' => ['<p {x}></p>', 'RZX011', 1, 4, 'needs a name'];
        yield 'unterminated expression' => ["<p>{name\n</p>", 'RZX030', 1, 4, 'expected `}`'];
        yield 'unterminated expression at eof' => ['<p title={a', 'RZX030', 1, 10, 'expected `}`'];
        yield 'php in text expr' => ['<p>{$name}</p>', 'RZX032', 1, 5, 'not `$name`'];
        yield 'php in attr expr' => ["<p\n  title={user->name}></p>", 'RZX032', 2, 14, 'Use `.`'];
        yield 'empty expression' => ['<p>{ }</p>', 'RZX040', 1, 4, 'Empty expression'];
        yield 'empty attr expression' => ['<p title={}></p>', 'RZX040', 1, 10, 'Empty expression'];

        // tree
        yield 'mismatched close' => ["<div>\n  <span>x</div>", 'RZX012', 2, 10, 'Expected </span> to close <span> opened at 2:3, found </div>'];
        yield 'never closed' => ["<div>\n  <section>\n  </section>\n", 'RZX014', 1, 1, '<div> is never closed'];
        yield 'duplicate attribute' => ['<p id="a" class="b" id="c"></p>', 'RZX013', 1, 21, 'Duplicate attribute id on <p>'];
        yield 'void close' => ['<p><br></br></p>', 'RZX015', 1, 8, 'void element'];
        yield 'stray close at top' => ['<p></p></div>', 'RZX016', 1, 8, 'no element is open'];
        yield 'bad tag name' => ['<Div_x></Div_x>', 'RZX017', 1, 1, 'Invalid tag name <Div_x'];
        yield 'bad component name' => ['<Rui.lower />', 'RZX017', 1, 1, 'Invalid component name'];
        yield 'uppercase in element' => ['<my-Tag></my-Tag>', 'RZX017', 1, 1, 'Invalid element name'];
        yield 'too deep' => [\str_repeat('<i>', 300) . \str_repeat('</i>', 300), 'RZX019', 1, 769, 'nested too deeply'];
        yield 'props not first' => ["<p></p>\n<props>return [];</props>", 'RZX020', 2, 1, '<props> must be the first'];
        yield 'second props' => ["<props>return [];</props>\n<props>return [];</props><p></p>", 'RZX020', 2, 1, 'must be the first'];
        yield 'props with attribute' => ['<props lang="php">return [];</props><p></p>', 'RZX020', 1, 1, 'takes no attributes'];
        yield 'text outside root' => ["<p></p>\n  hello", 'RZX021', 2, 3, 'Text outside the root'];
        yield 'expression outside root' => ['{x}<p></p>', 'RZX021', 1, 1, 'expression outside'];
        yield 'root is control flow' => ['<if test={a}><p></p></if>', 'RZX022', 1, 1, 'not <if>'];
        yield 'two roots' => ["<p></p>\n<div></div>", 'RZX023', 2, 1, 'exactly one root'];
        yield 'non-push after root' => ["<p></p>\n<if test={a}><p></p></if>", 'RZX023', 2, 1, 'Only <push> blocks'];
        yield 'no root' => ["{/* nothing */}\n", 'RZX024', 2, 1, 'needs exactly one root'];
        yield 'push only' => ['<push to="body.scripts"></push>', 'RZX022', 1, 1, 'not <push>'];

        // review fixes
        yield 'void close at top level' => ['<br></br>', 'RZX015', 1, 5, 'void element'];
        yield 'top-level text column' => ['<props>return [];</props> x<p></p>', 'RZX021', 1, 27, 'Text outside the root'];
        yield 'empty if after root' => ['<p></p><if test={a}></if>', 'RZX023', 1, 8, 'Only <push> blocks'];
        yield 'int overflow' => ['<p>{9223372036854775808}</p>', 'RZX036', 1, 5, 'Number out of range'];
        yield 'huge int' => ['<p>{1' . \str_repeat('0', 400) . '}</p>', 'RZX036', 1, 5, 'Number out of range: 100000000000000000000000…'];
        yield 'infinite float' => ['<p>{1' . \str_repeat('0', 400) . '.0}</p>', 'RZX036', 1, 5, 'Number out of range'];
        yield 'leading zeros are fine, overflow is not' => ['<p>{0009223372036854775808}</p>', 'RZX036', 1, 5, 'out of range'];
        yield 'slot max overflow' => ['<p><Slot max="1' . \str_repeat('0', 400) . '" /></p>', 'RZX050', 1, 10, 'max must be a positive integer'];
        yield 'push order overflow' => ['<p><push to="a" order="-1' . \str_repeat('0', 400) . '"></push></p>', 'RZX050', 1, 17, 'order must be an integer'];
        yield 'long invalid component name' => ['<A' . \str_repeat('.b', 20000) . ' />', 'RZX017', 1, 1, 'Invalid component name'];
        yield 'props cut by a comment' => ["<props>\nreturn ['ok' => true]; // </props><p></p>", 'RZX008', 1, 1, 'outside strings and comments'];
        yield 'props heredoc' => ["<props>\nreturn [<<<X\nX];\n</props><p></p>", 'RZX008', 2, 9, 'Heredoc'];

        // control tags
        yield 'if without test' => ['<p><if><b></b></if></p>', 'RZX050', 1, 4, '<if> needs test={…}'];
        yield 'if literal test' => ['<p><if test="a"></if></p>', 'RZX050', 1, 8, 'needs test={…}'];
        yield 'if unknown attr' => ['<p><if test={a} when={b}></if></p>', 'RZX051', 1, 17, 'Unknown attribute when on <if>; <if> takes test'];
        yield 'orphan else' => ["<p>\n  <else>x</else>\n</p>", 'RZX052', 2, 3, '<else> must directly follow'];
        yield 'else after text' => ['<p><if test={a}>x</if>text<else>y</else></p>', 'RZX052', 1, 27, 'must directly follow'];
        yield 'else after else' => ['<p><if test={a}></if><else></else><else></else></p>', 'RZX052', 1, 35, 'must directly follow'];
        yield 'elseif after else' => ['<p><if test={a}></if><else></else><elseif test={b}></elseif></p>', 'RZX052', 1, 35, 'must directly follow'];
        yield 'else with attribute' => ['<p><if test={a}></if><else test={b}></else></p>', 'RZX051', 1, 28, '<else> takes no attributes'];
        yield 'for without each' => ['<ul><for as="r"></for></ul>', 'RZX050', 1, 5, 'needs each={…}'];
        yield 'for without as' => ['<ul><for each={rows}></for></ul>', 'RZX050', 1, 5, 'needs as="…"'];
        yield 'for bad as' => ['<ul><for each={rows} as="row-1"></for></ul>', 'RZX050', 1, 22, 'not a valid variable name'];
        yield 'for keyword as' => ['<ul><for each={rows} as="null"></for></ul>', 'RZX050', 1, 22, 'not a valid variable name'];
        yield 'for same index' => ['<ul><for each={rows} as="r" index="r"></for></ul>', 'RZX050', 1, 29, 'must differ'];
        yield 'for expr key' => ['<ul><for each={rows} as="r" key={k}></for></ul>', 'RZX050', 1, 29, 'key must be a quoted string'];
        yield 'empty outside for' => ['<ul><empty></empty></ul>', 'RZX053', 1, 5, 'only allowed directly inside <for>'];
        yield 'empty nested deeper' => ['<ul><for each={r} as="x"><li><empty></empty></li></for></ul>', 'RZX053', 1, 30, 'directly inside <for>'];
        yield 'two empties' => ['<ul><for each={r} as="x"><empty/><empty/></for></ul>', 'RZX053', 1, 34, 'only one <empty>'];
        yield 'push without to' => ['<p><push key="a"></push></p>', 'RZX050', 1, 4, 'needs to="…"'];
        yield 'push bad anchor' => ['<p><push to="Body Scripts"></push></p>', 'RZX050', 1, 10, 'not a valid anchor name'];
        yield 'push to content' => ['<p><push to="content"></push></p>', 'RZX050', 1, 10, 'the page itself'];
        yield 'push bad order' => ['<p><push to="a" order="first"></push></p>', 'RZX050', 1, 17, 'order must be an integer'];
        yield 'slot bad accepts' => ['<p><Slot accepts="ui-button, 9x" /></p>', 'RZX050', 1, 10, 'accepts must be'];
        yield 'slot bad max' => ['<p><Slot max="0" /></p>', 'RZX050', 1, 10, 'max must be a positive integer'];
        yield 'slot empty name' => ['<p><Slot name=" " /></p>', 'RZX050', 1, 10, 'must not be empty'];
        yield 'fill without slot' => ['<Card><Fill></Fill></Card>', 'RZX050', 1, 7, '<Fill> needs slot="…"'];
        yield 'anchor children' => ["<head><Anchor name=\"head.meta\">\n  <meta /></Anchor></head>", 'RZX050', 2, 3, 'has no children'];
        yield 'anchor bad name' => ['<head><Anchor name="Head" /></head>', 'RZX050', 1, 15, 'not a valid anchor name'];
    }

    #[DataProvider('malformed')]
    public function testMalformedInputIsRejectedAtTheRightPlace(string $text, string $code, int $line, int $column, string $message): void
    {
        try {
            (new Parser())->parse(new Source($text, 'Broken.rzx'));
            $this->fail('accepted malformed input');
        } catch (CompileError $e) {
            $this->assertSame($code, $e->rzxCode, $e->getMessage());
            $this->assertSame([$line, $column], [$e->sourceLine, $e->sourceColumn], $e->getMessage());
            $this->assertStringContainsString($message, $e->getMessage());
            $this->assertSame('Broken.rzx:' . $line . ':' . $column, $e->location());
        }
    }

    public function testUnknownFunctionIsRejectedWhenTheListIsKnown(): void
    {
        $parser = new Parser(['count']);
        $parser->parse(new Source('<p>{count(rows)}</p>'));

        $this->expectException(CompileError::class);
        $this->expectExceptionMessageMatches('/^RZX045 Unknown function `system`\n  at Bad\.rzx:2:13/');
        $parser->parse(new Source("<p>\n  <b title={system('id')}></b></p>", 'Bad.rzx'));
    }

    public function testMessageHasCodeLocationAndThreeLineFrame(): void
    {
        $text = "<div>\n  <p>one</p>\n  <p>two</div>\n  <p>four</p>\n";
        try {
            (new Parser())->parse(new Source($text, 'Frame.rzx'));
            $this->fail('accepted');
        } catch (CompileError $e) {
            $this->assertInstanceOf(TemplateException::class, $e, 'catchable like other template errors');
            $expected = <<<'TXT'
                RZX012 Expected </p> to close <p> opened at 3:3, found </div>
                  at Frame.rzx:3:9
                  2 |   <p>one</p>
                > 3 |   <p>two</div>
                    |         ^
                  4 |   <p>four</p>
                TXT;
            $this->assertSame($expected, $e->getMessage());
        }
    }

    public function testAByteOrderMarkIsNotAColumn(): void
    {
        try {
            (new Parser())->parse(new Source("\xEF\xBB\xBF<p>{\$x}</p>", 'Bom.rzx'));
            $this->fail('accepted');
        } catch (CompileError $e) {
            $this->assertSame([1, 5], [$e->sourceLine, $e->sourceColumn]);
            $this->assertStringContainsString("> 1 | <p>{\$x}</p>\n    |     ^", $e->getMessage(), 'the frame shows no BOM and the caret lines up');
        }
    }

    public function testUnreadableFile(): void
    {
        $this->expectException(CompileError::class);
        $this->expectExceptionMessageMatches('/^RZX000 Cannot read/');
        (new Parser())->parseFile(\sys_get_temp_dir() . '/razy-rzx-missing-' . \uniqid() . '.rzx');
    }
}
