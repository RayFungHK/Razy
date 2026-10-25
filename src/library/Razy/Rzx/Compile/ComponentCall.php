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

namespace Razy\Rzx\Compile;

use Closure;
use Razy\Rzx\ComponentMeta;
use Razy\Rzx\Node\AttributeNode;
use Razy\Rzx\Node\ComponentNode;
use Razy\Rzx\Node\ElementNode;
use Razy\Rzx\Node\FillNode;
use Razy\Rzx\Node\ForNode;
use Razy\Rzx\Node\IfNode;
use Razy\Rzx\Node\Node;
use Razy\Rzx\Node\SlotNode;
use Razy\Rzx\Node\TextNode;
use Razy\Rzx\Prop;

/**
 * Generates one component use, `<Kit.Card title={x}>…</Kit.Card>`, and
 * checks it against the component's contract at compile time:
 *
 * - props: unknown names, missing required ones, and constant values of the
 *   wrong type or outside an enum are errors;
 * - fills: a `<Fill slot>` must name a slot the component declares; its
 *   direct children (seen through `<if>` / `<for>`) must be in the slot's
 *   `accepts` list and within its `max`; other children fill the default
 *   slot, which must exist.
 *
 * Fills compile to closures over the caller's scope, registered in a
 * SlotBag; `<if>` / `<for>` around fills stay real control flow.
 */
final class ComponentCall
{
    /**
     * @param Closure(string, int): ComponentMeta $metaFor
     */
    public function __construct(private readonly CodeGen $gen, private readonly Closure $metaFor)
    {
    }

    public function generate(ComponentNode $node): void
    {
        $meta = ($this->metaFor)($node->name, $node->offset);
        $props = $this->props($node, $meta);
        $id = $this->gen->id();
        $bag = '$__f' . $id;
        $out = $this->gen->out;

        $out->statement($bag . ' = [];', $node->offset);
        $default = [];
        $static = [];
        foreach ($node->children as $child) {
            if ($child instanceof TextNode && \trim($child->text) === '') {
                continue;
            }
            if ($this->isFillTree($child)) {
                $this->fills($child, $meta, $bag, $static);
            } elseif ($this->containsFill($child)) {
                throw $this->gen->error('RZX124', '<Fill> must be a direct child of <' . $node->name . '> (optionally inside <if> or <for> holding only fills)', $child->offset);
            } else {
                $default[] = $child;
            }
        }
        if ($default !== []) {
            if (!isset($meta->slots[''])) {
                throw $this->gen->error('RZX120', '<' . $node->name . '> has no default <Slot />; put children in a <Fill slot="…">', $default[0]->offset);
            }
            $this->checkAccepts($node->name, '', $meta->slots[''], $default);
            $this->closure($bag . "['']", $default, $node->offset);
        }
        $out->statement('$__rt->component(' . \var_export($node->name, true) . ', [' . \implode(', ', $props) . '], new \Razy\Rzx\Runtime\SlotBag(' . $bag . '), ' . \var_export($this->gen->site($node->offset), true) . ');', $node->offset);
    }

    /**
     * @return list<string> `'name' => code` items
     */
    private function props(ComponentNode $node, ComponentMeta $meta): array
    {
        $items = [];
        $given = [];
        foreach ($node->attributes as $attr) {
            $schema = $meta->props[$attr->name] ?? null;
            if ($schema === null) {
                $known = $meta->props === [] ? 'it takes no props' : 'props: ' . \implode(', ', \array_keys($meta->props));
                throw $this->gen->error('RZX111', 'Unknown prop ' . $attr->name . ' on <' . $node->name . '>; ' . $known, $attr->offset);
            }
            [$constant, $value] = $this->constant($attr);
            if ($constant && ($error = $schema->check($value, $attr->name)) !== null) {
                $hint = \is_string($value) && \in_array($schema->type, ['int', 'float', 'bool', 'list', 'map', 'shape'], true) ? ' (write ' . $attr->name . '={…} for a non-string value)' : '';
                throw $this->gen->error('RZX113', 'Invalid prop on <' . $node->name . '>: ' . $error . $hint, $attr->offset);
            }
            $code = $attr->expr !== null ? $this->gen->expr->compile($attr->expr) : \var_export($value, true);
            $items[] = \var_export($attr->name, true) . ' => ' . $code;
            $given[$attr->name] = true;
        }
        foreach ($meta->props as $name => $schema) {
            if ($schema->isRequired() && !$schema->hasDefault() && !isset($given[$name])) {
                throw $this->gen->error('RZX112', '<' . $node->name . '> needs the required prop ' . $name, $node->offset);
            }
        }

        return $items;
    }

    /**
     * @return array{bool, mixed}
     */
    private function constant(AttributeNode $attr): array
    {
        if ($attr->isBoolean()) {
            return [true, true];
        }
        if ($attr->literal !== null) {
            return [true, $attr->literal];
        }

        return $attr->expr === null ? [false, null] : ExprGen::constant($attr->expr);
    }

    /**
     * Emit a fill (or the control flow around fills).
     *
     * @param array<string, bool> $static literal fill names seen: true outside control flow, false only inside it
     */
    private function fills(Node $node, ComponentMeta $meta, string $bag, array &$static, bool $nested = false): void
    {
        $out = $this->gen->out;
        if ($node instanceof FillNode) {
            $name = $node->slot;
            if (\is_string($name)) {
                if (!isset($meta->slots[$name]) && !$meta->dynamicSlots) {
                    $known = \array_filter(\array_keys($meta->slots), static fn ($n) => $n !== '');
                    throw $this->gen->error('RZX120', '<' . $meta->name . '> has no slot "' . $name . '" (slots: ' . (\implode(', ', $known) ?: 'none') . ')', $node->offset);
                }
                // $static: name => true for a fill outside control flow, false inside <if>/<for>.
                // An unconditional fill conflicts with any other fill of that slot, in either
                // order; fills in different branches of control flow may share a slot.
                if (isset($static[$name]) && (!$nested || $static[$name])) {
                    throw $this->gen->error('RZX123', 'Slot "' . $name . '" is already filled', $node->offset);
                }
                $static[$name] = !$nested || ($static[$name] ?? false);
                if (isset($meta->slots[$name])) {
                    $this->checkAccepts($meta->name, $name, $meta->slots[$name], $node->children);
                }
                $key = \var_export($name, true);
            } else {
                if (!$meta->dynamicSlots) {
                    throw $this->gen->error('RZX120', '<' . $meta->name . '> has no slots with computed names', $node->offset);
                }
                $key = '$__rt->str(' . $this->gen->expr->compile($name) . ')';
            }
            $this->closure($bag . '[' . $key . ']', $node->children, $node->offset);

            return;
        }
        if ($node instanceof IfNode) {
            foreach ($node->branches as $i => [$test, $children]) {
                $condition = '$__rt->truthy(' . $this->gen->expr->compile($test) . ')';
                if ($i === 0) {
                    $out->open('if (' . $condition . ') {', $node->offset);
                } else {
                    $out->close('} elseif (' . $condition . ') {');
                }
                $this->fillList($children, $meta, $bag, $static);
            }
            if ($node->else !== null) {
                $out->close('} else {');
                $this->fillList($node->else, $meta, $bag, $static);
            }
            $out->close();

            return;
        }
        if ($node instanceof ForNode) {
            // A loop of fills (one per item, typically with computed slot names),
            // with the same bindings and checks as a markup <for>.
            foreach (\array_filter([$node->as, $node->index]) as $name) {
                if ($this->gen->expr->declared($name)) {
                    throw $this->gen->error('RZX104', '<for> variable `' . $name . '` shadows a prop or an outer loop variable', $node->offset);
                }
            }
            $id = $this->gen->id();
            $type = $this->gen->expr->typeOf($node->each);
            $out->statement('$__c' . $id . ' = 0;', $node->offset);
            if ($node->key !== null) {
                $out->statement('$__k' . $id . ' = [];', $node->offset);
            }
            $out->open('foreach ($__rt->iter(' . $this->gen->expr->compile($node->each) . ') as $__v' . $id . ') {', $node->offset);
            $out->statement('$__s[' . \var_export($node->as, true) . '] = $__v' . $id . ';', $node->offset);
            $names = [$node->as => $type?->type === 'list' ? $type->item : null];
            if ($node->index !== null) {
                $out->statement('$__s[' . \var_export($node->index, true) . '] = $__c' . $id . ';', $node->offset);
                $names[$node->index] = Prop::int();
            }
            if ($node->key !== null) {
                $out->statement('$__rt->loopKey($__k' . $id . ', $__v' . $id . ', ' . \var_export($node->key, true) . ', ' . \var_export($this->gen->site($node->offset), true) . ');', $node->offset);
            }
            $this->gen->expr->push($names);
            $this->fillList($node->children, $meta, $bag, $static);
            $this->gen->expr->pop();
            $out->statement('++$__c' . $id . ';', $node->offset);
            $out->close();
            if ($node->empty !== null) {
                $out->open('if ($__c' . $id . ' === 0) {', $node->offset);
                $this->fillList($node->empty, $meta, $bag, $static);
                $out->close();
            }
        }
    }

    /**
     * @param list<Node> $nodes
     * @param array<string, bool> $static
     */
    private function fillList(array $nodes, ComponentMeta $meta, string $bag, array &$static): void
    {
        foreach ($nodes as $child) {
            if (!($child instanceof TextNode && \trim($child->text) === '')) {
                $this->fills($child, $meta, $bag, $static, true);
            }
        }
    }

    /**
     * @param list<Node> $children
     */
    private function closure(string $target, array $children, int $offset): void
    {
        $this->gen->out->open($target . ' = ' . CodeGen::CLOSURE, $offset);
        $this->gen->nodes($children);
        $this->gen->out->close('};');
    }

    /**
     * Check a fill's direct children against the slot's accepts list and max.
     *
     * @param array{list<string>, ?int} $slot
     * @param list<Node> $children
     */
    private function checkAccepts(string $component, string $slotName, array $slot, array $children): void
    {
        [$accepts, $max] = $slot;
        if ($accepts === [] && $max === null) {
            return;
        }
        $label = $slotName === '' ? 'the default slot' : 'slot "' . $slotName . '"';
        $count = $this->countChildren($children, $accepts, $component, $label, $max !== null);
        if ($max !== null && $count > $max) {
            throw $this->gen->error('RZX122', $label . ' of <' . $component . '> takes at most ' . $max . ' children, got ' . $count, $children[0]->offset);
        }
    }

    /**
     * @param list<Node> $children
     * @param list<string> $accepts
     */
    private function countChildren(array $children, array $accepts, string $component, string $label, bool $limited): int
    {
        $count = 0;
        foreach ($children as $child) {
            if ($child instanceof TextNode && \trim($child->text) === '') {
                continue;
            }
            if ($child instanceof IfNode) {
                $branches = [...\array_column($child->branches, 1), $child->else ?? []];
                $count += \max(\array_map(fn (array $b): int => $this->countChildren($b, $accepts, $component, $label, $limited), $branches));
                continue;
            }
            if ($child instanceof ForNode) {
                if ($limited) {
                    throw $this->gen->error('RZX125', 'A <for> cannot fill ' . $label . ' of <' . $component . '>, which has a max; the count must be known at compile time', $child->offset);
                }
                $this->countChildren($child->children, $accepts, $component, $label, $limited);
                $this->countChildren($child->empty ?? [], $accepts, $component, $label, $limited);
                continue;
            }
            if ($child instanceof SlotNode) {
                ++$count; // a forwarded slot: its own contract applies where it is filled

                continue;
            }
            $tag = $child instanceof ElementNode ? $child->tag : ($child instanceof ComponentNode ? $child->name : null);
            if ($accepts !== [] && ($tag === null || !\in_array($tag, $accepts, true))) {
                $what = $tag === null ? 'text or an expression' : '<' . $tag . '>';
                throw $this->gen->error('RZX121', $label . ' of <' . $component . '> accepts ' . \implode(', ', $accepts) . '; found ' . $what, $child->offset);
            }
            ++$count;
        }

        return $count;
    }

    private function isFillTree(Node $node): bool
    {
        if ($node instanceof FillNode) {
            return true;
        }
        $lists = match (true) {
            $node instanceof IfNode => [...\array_column($node->branches, 1), $node->else ?? []],
            $node instanceof ForNode => [$node->children, $node->empty ?? []],
            default => null,
        };
        if ($lists === null) {
            return false;
        }
        $any = false;
        foreach ($lists as $list) {
            foreach ($list as $child) {
                if ($child instanceof TextNode && \trim($child->text) === '') {
                    continue;
                }
                if (!$this->isFillTree($child)) {
                    return false;
                }
                $any = true;
            }
        }

        return $any;
    }

    private function containsFill(Node $node): bool
    {
        $lists = match (true) {
            $node instanceof IfNode => [...\array_column($node->branches, 1), $node->else ?? []],
            $node instanceof ForNode => [$node->children, $node->empty ?? []],
            default => [],
        };
        foreach ($lists as $list) {
            foreach ($list as $child) {
                if ($child instanceof FillNode || $this->containsFill($child)) {
                    return true;
                }
            }
        }

        return false;
    }
}
