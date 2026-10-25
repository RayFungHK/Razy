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
 * `<Fill slot="…">…</Fill>` at a component use site.
 */
final class FillNode extends Node
{
    /**
     * @param list<Node> $children
     */
    public function __construct(int $offset, public readonly string|Expr $slot, public readonly array $children)
    {
        parent::__construct($offset);
    }
}
