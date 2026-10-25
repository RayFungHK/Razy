<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Razy\Rzx;

use Razy\Rzx\Expr\Expr;
use Razy\Rzx\Expr\ExprLexer;
use Razy\Rzx\Expr\Literal;
use Razy\Rzx\Node\AnchorNode;
use Razy\Rzx\Node\AttributeNode;
use Razy\Rzx\Node\FillNode;
use Razy\Rzx\Node\ForNode;
use Razy\Rzx\Node\IfNode;
use Razy\Rzx\Node\Node;
use Razy\Rzx\Node\PushNode;
use Razy\Rzx\Node\SlotNode;

/**
 * Attribute rules for the reserved tags and construction of their nodes:
 * `<if>` `<elseif>` `<else>` `<for>` `<push>` `<Slot>` `<Fill>` `<Anchor>`.
 *
 * Each tag accepts only its own attributes, in a fixed shape (literal or
 * `{expression}`); anything else is a compile error at the attribute.
 */
final class TagRules
{
    /** Anchor names, the same pattern as Razy\View\Anchors (RZ-27). */
    public const ANCHOR_NAME = '/^[a-z][a-z0-9_.-]*$/';

    /** The layout's reserved page anchor; pages never push into it. */
    public const CONTENT_ANCHOR = 'content';

    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    private const SPECIAL = ['if', 'for', 'push', 'Slot', 'Fill', 'Anchor'];

    private const TAGS = [
        IfNode::class => 'if', ForNode::class => 'for', PushNode::class => 'push',
        SlotNode::class => 'Slot', FillNode::class => 'Fill', AnchorNode::class => 'Anchor',
    ];

    public function __construct(private readonly Source $source)
    {
    }

    public static function isSpecial(string $tag): bool
    {
        return \in_array($tag, self::SPECIAL, true);
    }

    public static function tagOf(Node $node): string
    {
        return self::TAGS[$node::class] ?? 'node';
    }

    /**
     * @param list<AttributeNode> $attributes
     * @param list<Node> $children
     * @param list<Node>|null $empty the `<empty>` body of a `<for>`
     */
    public function build(Token $open, array $attributes, array $children, ?array $empty): Node
    {
        $tag = $open->value;
        $attrs = $this->index($tag, $attributes, match ($tag) {
            'if' => ['test'],
            'for' => ['each', 'as', 'key', 'index'],
            'push' => ['to', 'key', 'order'],
            'Slot' => ['name', 'accepts', 'max'],
            'Fill' => ['slot'],
            default => ['name'],
        });

        return match ($tag) {
            'if' => new IfNode($open->offset, $this->expression($open, $attrs, 'test'), $children),
            'for' => $this->forNode($open, $attrs, $children, $empty),
            'push' => $this->pushNode($open, $attrs, $children),
            'Slot' => $this->slotNode($open, $attrs, $children),
            'Fill' => new FillNode($open->offset, $this->name($open, $attrs, 'slot', true) ?? '', $children),
            default => $this->anchorNode($open, $attrs, $children),
        };
    }

    /**
     * Add an `<elseif>` or `<else>` branch to the preceding IfNode.
     *
     * @param list<AttributeNode> $attributes
     * @param list<Node> $children
     */
    public function attachBranch(IfNode $if, Token $open, array $attributes, array $children): void
    {
        if ($open->value === 'else') {
            $this->index('else', $attributes, []);
            $if->else = $children;

            return;
        }
        $attrs = $this->index('elseif', $attributes, ['test']);
        $if->branches[] = [$this->expression($open, $attrs, 'test'), $children];
    }

    /**
     * @param array<string, AttributeNode> $attrs
     * @param list<Node> $children
     * @param list<Node>|null $empty
     */
    private function forNode(Token $open, array $attrs, array $children, ?array $empty): ForNode
    {
        $each = $this->expression($open, $attrs, 'each');
        $as = $this->identifier($open, $attrs, 'as', true);
        $index = $this->identifier($open, $attrs, 'index', false);
        if ($index !== null && $index === $as) {
            throw $this->error('RZX050', '<for> index="' . $index . '" must differ from as="' . $as . '"', $attrs['index']->offset);
        }
        $key = $this->literal($open, $attrs, 'key', false);
        if ($key === '') {
            throw $this->error('RZX050', '<for> key must name a field, e.g. key="id"', $attrs['key']->offset);
        }

        return new ForNode($open->offset, $each, (string) $as, $key, $index, $children, $empty);
    }

    /**
     * @param array<string, AttributeNode> $attrs
     * @param list<Node> $children
     */
    private function pushNode(Token $open, array $attrs, array $children): PushNode
    {
        $to = (string) $this->literal($open, $attrs, 'to', true);
        $this->anchorName($to, $attrs['to'], 'push to');
        if ($to === self::CONTENT_ANCHOR) {
            throw $this->error('RZX050', '"content" is the page itself; push to another anchor', $attrs['to']->offset);
        }
        $order = null;
        if (isset($attrs['order'])) {
            $attr = $attrs['order'];
            $int = $attr->literal !== null && \preg_match('/^-?[0-9]+$/', $attr->literal) ? ExprLexer::number(\ltrim($attr->literal, '-')) : null;
            if (\is_int($int)) {
                $order = new Literal($attr->offset, \str_starts_with((string) $attr->literal, '-') ? -$int : $int);
            } elseif ($attr->expr !== null) {
                $order = $attr->expr;
            } else {
                throw $this->error('RZX050', '<push> order must be an integer, e.g. order={10}', $attr->offset);
            }
        }

        return new PushNode($open->offset, $to, $this->literal($open, $attrs, 'key', false), $order, $children);
    }

    /**
     * @param array<string, AttributeNode> $attrs
     * @param list<Node> $children
     */
    private function slotNode(Token $open, array $attrs, array $children): SlotNode
    {
        $accepts = [];
        $list = $this->literal($open, $attrs, 'accepts', false);
        if ($list !== null) {
            foreach (\explode(',', $list) as $item) {
                $item = \trim($item);
                if (!Names::isElement($item) && !Names::isComponent($item)) {
                    throw $this->error('RZX050', '<Slot> accepts must be a comma-separated list of tag or component names', $attrs['accepts']->offset);
                }
                $accepts[] = $item;
            }
        }
        $max = $this->literal($open, $attrs, 'max', false);
        if ($max !== null && (!\preg_match('/^[1-9][0-9]*$/', $max) || !\is_int(ExprLexer::number($max)))) {
            throw $this->error('RZX050', '<Slot> max must be a positive integer', $attrs['max']->offset);
        }

        return new SlotNode($open->offset, $this->name($open, $attrs, 'name', false), $accepts, $max === null ? null : (int) $max, $children);
    }

    /**
     * @param array<string, AttributeNode> $attrs
     * @param list<Node> $children
     */
    private function anchorNode(Token $open, array $attrs, array $children): AnchorNode
    {
        if ($children !== []) {
            throw $this->error('RZX050', '<Anchor> has no children; write <Anchor name="…" />', $children[0]->offset);
        }
        $name = (string) $this->literal($open, $attrs, 'name', true);
        $this->anchorName($name, $attrs['name'], 'Anchor name');

        return new AnchorNode($open->offset, $name);
    }

    /**
     * Index attributes by name, rejecting any not in $allowed.
     *
     * @param list<AttributeNode> $attributes
     * @param list<string> $allowed
     *
     * @return array<string, AttributeNode>
     */
    private function index(string $tag, array $attributes, array $allowed): array
    {
        $byName = [];
        foreach ($attributes as $attr) {
            if (!\in_array($attr->name, $allowed, true)) {
                $hint = $allowed === [] ? '<' . $tag . '> takes no attributes' : '<' . $tag . '> takes ' . \implode(', ', $allowed);
                throw $this->error('RZX051', 'Unknown attribute ' . $attr->name . ' on <' . $tag . '>; ' . $hint, $attr->offset);
            }
            $byName[$attr->name] = $attr;
        }

        return $byName;
    }

    /**
     * @param array<string, AttributeNode> $attrs
     */
    private function expression(Token $open, array $attrs, string $name): Expr
    {
        $attr = $attrs[$name] ?? null;
        if ($attr === null || $attr->expr === null) {
            throw $this->error('RZX050', '<' . $open->value . '> needs ' . $name . '={…}, an expression', $attr->offset ?? $open->offset);
        }

        return $attr->expr;
    }

    /**
     * @param array<string, AttributeNode> $attrs
     */
    private function literal(Token $open, array $attrs, string $name, bool $required): ?string
    {
        $attr = $attrs[$name] ?? null;
        if ($attr === null) {
            if ($required) {
                throw $this->error('RZX050', '<' . $open->value . '> needs ' . $name . '="…"', $open->offset);
            }

            return null;
        }
        if ($attr->literal === null) {
            throw $this->error('RZX050', '<' . $open->value . '> ' . $name . ' must be a quoted string', $attr->offset);
        }

        return $attr->literal;
    }

    /**
     * A name that may be a literal or an expression (`<Slot name>`, `<Fill slot>`).
     *
     * @param array<string, AttributeNode> $attrs
     */
    private function name(Token $open, array $attrs, string $name, bool $required): string|Expr|null
    {
        $attr = $attrs[$name] ?? null;
        if ($attr?->expr !== null) {
            return $attr->expr;
        }
        $value = $this->literal($open, $attrs, $name, $required);
        if ($value !== null && \trim($value) === '') {
            throw $this->error('RZX050', '<' . $open->value . '> ' . $name . ' must not be empty', $attrs[$name]->offset);
        }

        return $value;
    }

    /**
     * @param array<string, AttributeNode> $attrs
     */
    private function identifier(Token $open, array $attrs, string $name, bool $required): ?string
    {
        $value = $this->literal($open, $attrs, $name, $required);
        if ($value !== null && (!\preg_match(self::IDENTIFIER, $value) || \in_array($value, ['true', 'false', 'null'], true))) {
            throw $this->error('RZX050', '<' . $open->value . '> ' . $name . '="' . $value . '" is not a valid variable name', $attrs[$name]->offset);
        }

        return $value;
    }

    private function anchorName(string $name, AttributeNode $attr, string $what): void
    {
        if (!\preg_match(self::ANCHOR_NAME, $name)) {
            throw $this->error('RZX050', $what . ' "' . $name . '" is not a valid anchor name (lowercase, digits, `_ . -`)', $attr->offset);
        }
    }

    private function error(string $code, string $reason, int $offset): CompileError
    {
        return new CompileError($code, $reason, $this->source, $offset);
    }
}
