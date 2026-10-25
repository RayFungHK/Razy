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
 * A lowercase HTML or custom element: `<p>`, `<rui-button label="Save" />`.
 */
final class ElementNode extends Node
{
    /**
     * @param list<AttributeNode> $attributes
     * @param list<Node> $children
     * @param bool $void an HTML void element (`<br>`, `<meta>`, …), which has no end tag
     */
    public function __construct(
        int $offset,
        public readonly string $tag,
        public readonly array $attributes,
        public readonly array $children,
        public readonly bool $void = false,
    ) {
        parent::__construct($offset);
    }
}
