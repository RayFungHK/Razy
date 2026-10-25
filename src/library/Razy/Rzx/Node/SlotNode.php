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

use Razy\Rzx\Expr\Expr;

/**
 * `<Slot name accepts max>fallback</Slot>` inside a composition.
 * `$name` is null for the default slot, a string, or an Expr for a dynamic name.
 */
final class SlotNode extends Node
{
    /**
     * @param list<string> $accepts tag or component names allowed as direct children of a fill; empty = any
     * @param list<Node> $fallback rendered when the slot is not filled
     */
    public function __construct(
        int $offset,
        public readonly string|Expr|null $name,
        public readonly array $accepts,
        public readonly ?int $max,
        public readonly array $fallback,
    ) {
        parent::__construct($offset);
    }
}
