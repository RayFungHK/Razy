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

use Closure;
use Razy\Rzx\Compile\CodeGen;
use Razy\Rzx\Node\ComponentNode;
use Razy\Rzx\Node\DocumentNode;
use Razy\Rzx\Node\ElementNode;
use Razy\Rzx\Node\FillNode;
use Razy\Rzx\Node\ForNode;
use Razy\Rzx\Node\IfNode;
use Razy\Rzx\Node\Node;
use Razy\Rzx\Node\PushNode;
use Razy\Rzx\Node\SlotNode;

/**
 * Compiles one `.rzx` source to the PHP of a cache file that returns a
 * `Razy\Rzx\Runtime\Compiled` (pipeline: Lexer → Parser → PropsReader →
 * CodeGen). Pure: it reads nothing but the source and the contracts that
 * $metaFor hands it for the components the source uses. CompileCache
 * decides when to compile and where the result goes.
 */
final class Compiler
{
    public const VERSION = 'rzx-1';

    public function __construct(private readonly FunctionRegistry $functions = new FunctionRegistry())
    {
    }

    /**
     * Literal slot names ('' = default) with their contract, and whether any slot has a computed name.
     *
     * @return array{array<string, array{list<string>, ?int}>, bool}
     */
    public static function slots(DocumentNode $doc): array
    {
        $slots = [];
        $dynamic = false;
        $walk = static function (Node $node) use (&$walk, &$slots, &$dynamic, $doc): void {
            if ($node instanceof SlotNode) {
                if (\is_string($node->name) || $node->name === null) {
                    $name = $node->name ?? '';
                    if (isset($slots[$name])) {
                        throw new CompileError('RZX126', ($name === '' ? 'The default slot' : 'Slot "' . $name . '"') . ' is declared twice', $doc->source, $node->offset);
                    }
                    $slots[$name] = [$node->accepts, $node->max];
                } else {
                    $dynamic = true;
                }
            }
            foreach (self::children($node) as $child) {
                $walk($child);
            }
        };
        $walk($doc->root);
        foreach ($doc->pushes as $push) {
            $walk($push);
        }

        return [$slots, $dynamic];
    }

    /**
     * @return list<Node>
     */
    private static function children(Node $node): array
    {
        $lists = match (true) {
            $node instanceof IfNode => [...\array_column($node->branches, 1), $node->else ?? []],
            $node instanceof ForNode => [$node->children, $node->empty ?? []],
            $node instanceof SlotNode => [$node->fallback],
            $node instanceof ElementNode, $node instanceof ComponentNode, $node instanceof FillNode, $node instanceof PushNode => [$node->children],
            default => [],
        };

        return $lists === [] ? [] : \array_merge(...$lists);
    }

    private static function export(mixed $value): string
    {
        return \var_export($value, true);
    }

    /**
     * Parse and read the contract (props + slots) of a source.
     *
     * @return array{DocumentNode, ComponentMeta}
     */
    public function analyze(Source $source, string $name): array
    {
        $doc = (new Parser($this->functions->names()))->parse($source);
        $props = $doc->props === null ? [] : PropsReader::read($source, $doc->props);
        [$slots, $dynamic] = self::slots($doc);

        return [$doc, new ComponentMeta($name, $props, $slots, $dynamic)];
    }

    /**
     * @param Closure(string, int): ComponentMeta $metaFor contract of a used component (throws CompileError)
     */
    public function generate(DocumentNode $doc, ComponentMeta $meta, Closure $metaFor): string
    {
        $source = $doc->source;
        $gen = new CodeGen($source, $metaFor);
        $out = $gen->out;
        $out->raw('<?php');
        $out->raw('');
        $out->raw('// Compiled by the Razy RZX compiler (' . self::VERSION . ') from ' . (\preg_replace('/[^A-Za-z0-9._-]/', '_', \basename($source->file)) ?? 'source') . '.');
        $out->raw('// Generated code: edit the .rzx source, not this file.');
        $out->raw('');
        $out->raw('declare(strict_types=1);');
        $out->raw('');
        $out->raw('return new \Razy\Rzx\Runtime\Compiled(');
        $out->indent(1);
        $out->raw(\var_export($meta->name, true) . ',');
        $out->raw(\var_export($source->file, true) . ',');
        $out->raw('static function (array $__s, \Razy\Rzx\Runtime\SlotBag $__slots, \Razy\Rzx\Runtime\Runtime $__rt): void {');
        $out->indent(1);
        $gen->document($doc, $meta);
        $out->indent(-1);
        $out->raw('},');
        $map = $out->lineMap();
        $out->raw(self::export(\array_map(static fn (Prop $p): array => $p->toArray(), $meta->props)) . ',');
        $out->raw(self::export($map) . ',');
        $out->indent(-1);
        $out->raw(');');

        return $out->code();
    }
}
