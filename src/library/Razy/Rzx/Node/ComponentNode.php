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

namespace Razy\Rzx\Node;

/**
 * A use of another component: a capitalised tag, optionally namespaced with
 * dots (`<FieldGroup>`, `<Rui.ManagementList>`). Children are the default
 * slot fill; `<Fill slot>` children fill named slots.
 */
final class ComponentNode extends Node
{
    /**
     * @param list<AttributeNode> $attributes
     * @param list<Node> $children
     */
    public function __construct(
        int $offset,
        public readonly string $name,
        public readonly array $attributes,
        public readonly array $children,
    ) {
        parent::__construct($offset);
    }

    public function namespace(): ?string
    {
        $dot = \strrpos($this->name, '.');

        return $dot === false ? null : \substr($this->name, 0, $dot);
    }

    public function localName(): string
    {
        $dot = \strrpos($this->name, '.');

        return $dot === false ? $this->name : \substr($this->name, $dot + 1);
    }
}
