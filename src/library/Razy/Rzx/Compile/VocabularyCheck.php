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

use Razy\Rzx\CompileError;
use Razy\Rzx\Expr\Literal;
use Razy\Rzx\Node\AttributeNode;
use Razy\Rzx\Node\ElementNode;
use Razy\Rzx\Profile;
use Razy\Rzx\Source;
use Razy\Rzx\Vocabulary;

/**
 * Checks elements against the registered Vocabulary and the file's Profile,
 * and applies attribute mappings (`motion="rise"` → the kit's attribute).
 *
 * Every check is data-driven: with an empty vocabulary and no profile,
 * `attributes()` returns the attributes unchanged and nothing is rejected.
 */
final class VocabularyCheck
{
    public function __construct(
        private readonly Source $source,
        private readonly Vocabulary $vocabulary,
        private readonly ?Profile $profile,
    ) {
    }

    public function slotMarker(): ?string
    {
        return $this->vocabulary->slotMarker;
    }

    /**
     * Check one element and return the attributes to emit (mappings applied).
     *
     * @return list<AttributeNode>
     */
    public function attributes(ElementNode $node): array
    {
        $tag = $node->tag;
        $profile = $this->profile;
        $custom = \str_contains($tag, '-');
        if ($profile !== null && (($custom && !$profile->customElements) || (!$custom && $profile->elements !== null && !\in_array($tag, $profile->elements, true)))) {
            throw $this->error('RZX150', '<' . $tag . '> is not allowed in a ' . $profile->name . ' file', $node->offset);
        }
        $known = $this->vocabulary->elements[$tag] ?? null;
        if ($known === null && $this->vocabulary->governs($tag)) {
            throw $this->error('RZX151', 'Unknown element <' . $tag . '> (not in the registered vocabulary)', $node->offset);
        }

        $out = [];
        foreach ($node->attributes as $attr) {
            $name = $attr->name;
            if (\in_array($name, $this->vocabulary->forbidden, true)) {
                throw $this->error('RZX152', 'The attribute ' . $name . ' is internal to the UI kit and never allowed', $attr->offset);
            }
            if ($profile !== null && (\in_array($name, $profile->forbiddenAttributes, true) || ($name === 'hooks' && !$profile->hooks))) {
                throw $this->error('RZX153', $name . ' is not allowed in a ' . $profile->name . ' file', $attr->offset);
            }
            $mapping = $this->vocabulary->mappings[$name] ?? null;
            if ($mapping !== null) {
                $out[] = $this->map($attr, $mapping);
                continue;
            }
            if ($known !== null && $name !== 'hooks' && $this->vocabulary->governsAttribute($name)) {
                $spec = $known[$name] ?? null;
                if ($spec === null) {
                    throw $this->error('RZX154', 'Unknown attribute ' . $name . ' on <' . $tag . '>', $attr->offset);
                }
                $this->checkValue($tag, $attr, $spec);
            }
            $out[] = $attr;
        }

        return $out;
    }

    /**
     * @param list<array{\Razy\Rzx\Expr\Expr, \Razy\Rzx\Expr\Expr}> $entries
     */
    public function hooks(array $entries): void
    {
        if ($this->vocabulary->hooks === null) {
            return;
        }
        foreach ($entries as [$key]) {
            if ($key instanceof Literal && !\in_array($key->value, $this->vocabulary->hooks, true)) {
                throw $this->error('RZX157', 'Unknown hook ' . $key->value . ' (not a published custom property)', $key->offset);
            }
        }
    }

    /**
     * @param array{attribute: string, values: list<string>} $mapping
     */
    private function map(AttributeNode $attr, array $mapping): AttributeNode
    {
        if ($this->profile !== null && !$this->profile->mappings) {
            throw $this->error('RZX153', $attr->name . ' is not allowed in a ' . $this->profile->name . ' file', $attr->offset);
        }
        if ($attr->literal === null) {
            throw $this->error('RZX156', $attr->name . ' takes a literal value, e.g. ' . $attr->name . '="' . ($mapping['values'][0] ?? 'name') . '"', $attr->offset);
        }
        if ($mapping['values'] !== [] && !\in_array($attr->literal, $mapping['values'], true)) {
            throw $this->error('RZX155', $attr->name . '="' . $attr->literal . '" is not one of ' . \implode(', ', $mapping['values']), $attr->offset);
        }

        return new AttributeNode($attr->offset, $mapping['attribute'], $attr->literal);
    }

    /**
     * @param string|list<string> $spec
     */
    private function checkValue(string $tag, AttributeNode $attr, string|array $spec): void
    {
        $literal = $attr->literal ?? ($attr->expr instanceof Literal && \is_string($attr->expr->value) ? $attr->expr->value : null);
        if (\is_array($spec)) {
            if ($literal !== null && !\in_array($literal, $spec, true)) {
                throw $this->error('RZX155', $attr->name . '="' . $literal . '" on <' . $tag . '> is not one of ' . \implode(', ', $spec), $attr->offset);
            }

            return;
        }
        if ($spec === 'boolean' && $attr->literal !== null) {
            throw $this->error('RZX155', $attr->name . ' on <' . $tag . '> is a boolean: write ' . $attr->name . ' or ' . $attr->name . '={…}', $attr->offset);
        }
    }

    private function error(string $code, string $reason, int $offset): CompileError
    {
        return new CompileError($code, $reason, $this->source, $offset);
    }
}
