<?php

declare(strict_types=1);

namespace Razy\Tests\Rzx;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Rzx\Expr\Binary;
use Razy\Rzx\Expr\Call;
use Razy\Rzx\Expr\ListLiteral;
use Razy\Rzx\Expr\Literal;
use Razy\Rzx\Expr\MapLiteral;
use Razy\Rzx\Expr\Member;
use Razy\Rzx\Expr\Ternary;
use Razy\Rzx\Expr\Variable;
use Razy\Rzx\Lexer;
use Razy\Rzx\Node\AnchorNode;
use Razy\Rzx\Node\AttributeNode;
use Razy\Rzx\Node\ComponentNode;
use Razy\Rzx\Node\DocumentNode;
use Razy\Rzx\Node\ElementNode;
use Razy\Rzx\Node\FillNode;
use Razy\Rzx\Node\ForNode;
use Razy\Rzx\Node\IfNode;
use Razy\Rzx\Node\Node;
use Razy\Rzx\Node\OutputNode;
use Razy\Rzx\Node\PushNode;
use Razy\Rzx\Node\SlotNode;
use Razy\Rzx\Node\TextNode;
use Razy\Rzx\Parser;
use Razy\Rzx\Source;
use Razy\Rzx\TagRules;

/**
 * RZ-28a: `.rzx` markup to AST. The fixtures are the samples from the RZX
 * design (layout, list, form dialog, split dialog, screen), with neutral
 * names, so the approved syntax is pinned as it was written.
 */
#[CoversClass(Parser::class)]
#[CoversClass(Lexer::class)]
#[CoversClass(TagRules::class)]
#[CoversClass(Source::class)]
class ParserTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function samples(): iterable
    {
        foreach (\glob(__DIR__ . '/fixtures/*.rzx') ?: [] as $file) {
            yield \basename($file) => [$file];
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function whitespace(): iterable
    {
        yield 'inline kept' => ['a  b ', 'a  b '];
        yield 'lines joined' => ["\n  Hello\n  world\n", 'Hello world'];
        yield 'blank lines dropped' => ["one\n\n\n   two", 'one two'];
        yield 'space before newline trimmed' => ["Hi   \n  ", 'Hi'];
        yield 'leading space on first line kept' => ['  x', '  x'];
        yield 'only whitespace' => ["\n   \n", ''];
    }

    #[DataProvider('samples')]
    public function testDesignSampleParses(string $file): void
    {
        $doc = (new Parser())->parseFile($file);
        $this->assertNotNull($doc->props);
        $this->assertStringContainsString('Prop::', $doc->props->php);
        $this->assertStringStartsWith("\nreturn [", $doc->props->php);
        $this->assertSame('return [', \substr($doc->source->text, $doc->props->bodyOffset + 1, 8));
    }

    public function testManagementList(): void
    {
        $root = $this->root('ManagementList.rzx');
        $this->assertInstanceOf(ElementNode::class, $root);
        $this->assertSame('ui-data-table', $root->tag);

        $features = $this->attr($root, 'ui-features');
        $this->assertInstanceOf(Ternary::class, $features->expr);
        $this->assertInstanceOf(Variable::class, $features->expr->test);
        $this->assertInstanceOf(Call::class, $this->attr($root, 'ui-filter-key')->expr);
        $this->assertSame('card', $this->attr($root, 'ui-frame')->literal);
        $this->assertSame('rise', $this->attr($root, 'motion')->literal);
        $loading = $this->attr($root, 'ui-loading')->expr;
        $this->assertInstanceOf(Binary::class, $loading);
        $this->assertSame('==', $loading->operator);

        $slots = $this->find($root, SlotNode::class);
        $this->assertCount(2, $slots);
        $this->assertSame('toolbar-start', $slots[0]->name);
        $this->assertSame(['ui-segmented', 'ui-input'], $slots[0]->accepts);
        $this->assertSame(1, $slots[0]->max);
        $this->assertSame(['ui-button'], $slots[1]->accepts);
        $this->assertSame(3, $slots[1]->max);
    }

    public function testFormDialog(): void
    {
        $root = $this->root('FormDialog.rzx');
        $this->assertInstanceOf(ElementNode::class, $root);
        $this->assertTrue($this->attr($root, 'ui-ruled-header')->isBoolean());
        $this->assertInstanceOf(Variable::class, $this->attr($root, 'ui-open')->expr);

        [$default, $footer] = $this->find($root, SlotNode::class);
        $this->assertNull($default->name);
        $this->assertSame(['Kit.FieldGroup', 'Kit.Inset', 'Kit.Attached', 'p'], $default->accepts);
        $this->assertNull($default->max);
        $this->assertSame('footer-start', $footer->name);

        $buttons = \array_values(\array_filter($root->children, static fn (Node $n): bool => $n instanceof ElementNode));
        $this->assertCount(2, $buttons);
        $this->assertTrue($this->attr($buttons[0], 'data-ui-dialog-close')->isBoolean());
        $this->assertSame('submit', $this->attr($buttons[1], 'type')->literal);
    }

    public function testSplitDialog(): void
    {
        $root = $this->root('SplitDialog.rzx');
        $loops = $this->find($root, ForNode::class);
        $this->assertCount(2, $loops);
        $this->assertSame('s', $loops[0]->as);
        $this->assertSame('id', $loops[0]->key);
        $this->assertNull($loops[0]->index);
        $this->assertNull($loops[0]->empty);
        $this->assertInstanceOf(Variable::class, $loops[0]->each);

        $if = $this->find($loops[0], IfNode::class)[0];
        $this->assertInstanceOf(Member::class, $if->branches[0][0]);
        $this->assertNull($if->else);

        $dynamic = $this->find($loops[1], SlotNode::class)[0];
        $this->assertInstanceOf(Binary::class, $dynamic->name);
        $this->assertSame('~', $dynamic->name->operator);
        $this->assertInstanceOf(Literal::class, $dynamic->name->left);
        $this->assertSame('section:', $dynamic->name->left->value);

        $button = $this->find($loops[0], ElementNode::class)[1];
        $this->assertSame('button', $button->tag);
        $this->assertInstanceOf(OutputNode::class, $button->children[0]);
    }

    public function testScreenWithComponentsFillsAndPushes(): void
    {
        $doc = $this->doc('Members.rzx');
        $page = $doc->root;
        $this->assertInstanceOf(ComponentNode::class, $page);
        $this->assertSame('App.SettingsPage', $page->name);
        $this->assertSame('App', $page->namespace());
        $this->assertSame('SettingsPage', $page->localName());
        $this->assertInstanceOf(Call::class, $this->attr($page, 'title')->expr);

        $list = $page->children[0];
        $this->assertInstanceOf(ComponentNode::class, $list);
        $this->assertSame('Kit.ManagementList', $list->name);
        $this->assertInstanceOf(ListLiteral::class, $this->attr($list, 'searchKeys')->expr);

        $columns = $this->attr($list, 'columns')->expr;
        $this->assertInstanceOf(ListLiteral::class, $columns);
        $this->assertCount(8, $columns->items);
        $role = $columns->items[2];
        $this->assertInstanceOf(MapLiteral::class, $role);
        [$tonesKey, $tones] = $role->entries[4];
        $this->assertSame('tones', $tonesKey instanceof Literal ? $tonesKey->value : null);
        $this->assertInstanceOf(MapLiteral::class, $tones);
        $this->assertInstanceOf(Call::class, $tones->entries[0][0], 'computed key [t(…)]');

        $guard = $list->children[0];
        $this->assertInstanceOf(IfNode::class, $guard);
        $fill = $guard->branches[0][1][0];
        $this->assertInstanceOf(FillNode::class, $fill);
        $this->assertSame('toolbar-end', $fill->slot);
        $this->assertCount(2, $fill->children);

        $this->assertCount(2, $doc->pushes);
        [$overlay, $scripts] = $doc->pushes;
        $this->assertInstanceOf(IfNode::class, $overlay);
        $push = $overlay->branches[0][1][0];
        $this->assertInstanceOf(PushNode::class, $push);
        $this->assertSame('body.overlays', $push->to);
        $this->assertSame('app-members-add', $push->key);
        $this->assertInstanceOf(ComponentNode::class, $push->children[0]);
        $this->assertInstanceOf(PushNode::class, $scripts);
        $this->assertSame('body.scripts', $scripts->to);
        $script = $scripts->children[0];
        $this->assertInstanceOf(ElementNode::class, $script);
        $this->assertInstanceOf(Call::class, $this->attr($script, 'src')->expr);
    }

    public function testLayoutAnchorsAndVoidElements(): void
    {
        $root = $this->root('site.rzx');
        $this->assertInstanceOf(ElementNode::class, $root);
        $this->assertSame('html', $root->tag);
        $anchors = \array_map(static fn (AnchorNode $a): string => $a->name, $this->find($root, AnchorNode::class));
        $this->assertSame(['head.meta', 'head.styles', 'head.importmap', 'head.modulepreload', 'body.overlays', 'body.scripts'], $anchors);
        $meta = $this->find($root, ElementNode::class)[2];
        $this->assertSame('meta', $meta->tag);
        $this->assertSame([], $meta->children);
        $this->assertCount(1, $this->find($root, SlotNode::class));
    }

    public function testControlFlowBranchesAndLoopParts(): void
    {
        $doc = $this->parse(<<<'RZX'
            <ul>
              <if test={rows == []}><li>none</li></if> <elseif test={loading}>wait</elseif>
              <else>{count(rows)} rows</else>
              <for each={rows} as="row" key="id" index="i">
                <li>{i}: {row.name}</li>
                <empty><li>empty</li></empty>
              </for>
              <br>
              <push to="body.scripts" order="-5" />
            </ul>
            RZX);
        $root = $doc->root;
        $this->assertInstanceOf(ElementNode::class, $root);
        [$if, $for, $br, $push] = $root->children;
        $this->assertInstanceOf(IfNode::class, $if);
        $this->assertCount(2, $if->branches);
        $this->assertNotNull($if->else);
        $this->assertInstanceOf(OutputNode::class, $if->else[0]);
        $this->assertSame(' rows', $this->text($if->else[1]));

        $this->assertInstanceOf(ForNode::class, $for);
        $this->assertSame('i', $for->index);
        $this->assertCount(1, $for->children);
        $this->assertNotNull($for->empty);
        $this->assertCount(1, $for->empty);

        $this->assertInstanceOf(ElementNode::class, $br);
        $this->assertTrue($br->void);
        $this->assertInstanceOf(PushNode::class, $push);
        $this->assertInstanceOf(Literal::class, $push->order);
        $this->assertSame(-5, $push->order->value);
    }

    #[DataProvider('whitespace')]
    public function testJsxWhitespace(string $raw, string $expected): void
    {
        $this->assertSame($expected, Parser::normalizeText($raw));
    }

    public function testCommentsAndBracesInStrings(): void
    {
        $root = $this->parse("<p title=\"{literal}\">{/* gone\n */}{'}'}{ /* also gone */ }</p>")->root;
        $this->assertInstanceOf(ElementNode::class, $root);
        $this->assertSame('{literal}', $this->attr($root, 'title')->literal);
        $this->assertCount(1, $root->children);
        $out = $root->children[0];
        $this->assertInstanceOf(OutputNode::class, $out);
        $this->assertInstanceOf(Literal::class, $out->expr);
        $this->assertSame('}', $out->expr->value);
    }

    public function testHooksMapInsideAttributeBraces(): void
    {
        $root = $this->parse("<x-box hooks={{ '--x-margin': '0px', '--x-gap': token('space-4') }} />")->root;
        $this->assertInstanceOf(ElementNode::class, $root);
        $hooks = $this->attr($root, 'hooks')->expr;
        $this->assertInstanceOf(MapLiteral::class, $hooks);
        $this->assertCount(2, $hooks->entries);
    }

    public function testPositionsAreLineAndCharacterColumns(): void
    {
        $source = new Source("<p>\n  é{name}</p>");
        $doc = (new Parser())->parse($source);
        $this->assertInstanceOf(ElementNode::class, $doc->root);
        $out = $doc->root->children[1];
        $this->assertInstanceOf(OutputNode::class, $out);
        $this->assertSame([2, 4], $source->position($out->offset), 'é counts as one column');
        $this->assertSame([2, 3], $source->position($doc->root->children[0]->offset), 'text starts at its first visible character');
    }

    public function testSelfClosedVoidElementsAreVoid(): void
    {
        $root = $this->parse('<p><br/><img src="x" /><meta charset="utf-8" /><span /></p>')->root;
        $this->assertInstanceOf(ElementNode::class, $root);
        $void = \array_map(static fn (Node $n): bool => $n instanceof ElementNode && $n->void, $root->children);
        $this->assertSame([true, true, true, false], $void);
    }

    public function testBranchesGroupAcrossSpacesAndComments(): void
    {
        $root = $this->parse('<p><if test={a}>T</if> {/* c */} <elseif test={b}>E</elseif>  <else>F</else></p>')->root;
        $this->assertInstanceOf(ElementNode::class, $root);
        $this->assertCount(1, $root->children);
        $if = $root->children[0];
        $this->assertInstanceOf(IfNode::class, $if);
        $this->assertCount(2, $if->branches);
        $this->assertNotNull($if->else);
    }

    public function testTopLevelSpacesAreDropped(): void
    {
        $this->assertNotNull($this->parse('<props>return [];</props> <p></p>')->props);
        $this->assertCount(1, $this->parse('<p></p> <push to="body.scripts" /> ')->pushes);

        $doc = $this->parse('<p></p><if test={a}> <push to="x"></push> </if><else> {/* none */} <push to="y" /></else>');
        $this->assertCount(1, $doc->pushes);
        $if = $doc->pushes[0];
        $this->assertInstanceOf(IfNode::class, $if);
        $this->assertCount(1, $if->branches[0][1], 'whitespace around the push is not a child');
        $this->assertCount(1, $if->else ?? []);
    }

    public function testTextOffsetsSkipWhitespaceAndComments(): void
    {
        $root = $this->parse('<p>  {/* note */}  x</p>')->root;
        $this->assertInstanceOf(ElementNode::class, $root);
        $this->assertSame(\strpos('<p>  {/* note */}  x</p>', 'x'), $root->children[0]->offset, 'the first visible character');
        $this->assertSame('    x', $this->text($root->children[0]));

        $root = $this->parse('<p>a {/* c */} b</p>')->root;
        $this->assertInstanceOf(ElementNode::class, $root);
        $this->assertSame('a  b', $this->text($root->children[0]), 'one run across the comment');

        $root = $this->parse('<p> <b></b></p>')->root;
        $this->assertInstanceOf(ElementNode::class, $root);
        $this->assertSame(3, $root->children[0]->offset, 'a blank node keeps its own offset');

        $root = $this->parse("<p><x-a />   {/* note */}\n  <x-b /></p>")->root;
        $this->assertInstanceOf(ElementNode::class, $root);
        $this->assertCount(2, $root->children, 'indent around a comment is dropped');
    }

    private function doc(string $fixture): DocumentNode
    {
        return (new Parser())->parseFile(__DIR__ . '/fixtures/' . $fixture);
    }

    private function root(string $fixture): Node
    {
        return $this->doc($fixture)->root;
    }

    private function parse(string $text): DocumentNode
    {
        return (new Parser())->parse(new Source($text, 'inline.rzx'));
    }

    private function attr(ElementNode|ComponentNode $node, string $name): AttributeNode
    {
        foreach ($node->attributes as $attr) {
            if ($attr->name === $name) {
                return $attr;
            }
        }
        $this->fail('no attribute ' . $name);
    }

    private function text(Node $node): string
    {
        $this->assertInstanceOf(TextNode::class, $node);

        return $node->text;
    }

    /**
     * Depth-first search for nodes of one class.
     *
     * @template T of Node
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    private function find(Node $node, string $class): array
    {
        $found = [];
        $walk = static function (Node $n) use (&$walk, &$found, $class): void {
            if ($n instanceof $class) {
                $found[] = $n;
            }
            $lists = match (true) {
                $n instanceof IfNode => [...\array_column($n->branches, 1), $n->else ?? []],
                $n instanceof ForNode => [$n->children, $n->empty ?? []],
                $n instanceof SlotNode => [$n->fallback],
                $n instanceof ElementNode, $n instanceof ComponentNode, $n instanceof FillNode, $n instanceof PushNode => [$n->children],
                default => [],
            };
            foreach ($lists as $list) {
                foreach ($list as $child) {
                    $walk($child);
                }
            }
        };
        $walk($node);

        return $found;
    }
}
