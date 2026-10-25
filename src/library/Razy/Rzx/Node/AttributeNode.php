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
 * One attribute. Exactly one shape:
 * - boolean: `rui-ruled-header` (both $literal and $expr null);
 * - literal: `rui-style="soft"` ($literal is the raw text between the quotes);
 * - expression: `label={copy.add}` ($expr).
 */
final class AttributeNode extends Node
{
    public function __construct(
        int $offset,
        public readonly string $name,
        public readonly ?string $literal = null,
        public readonly ?Expr $expr = null,
    ) {
        parent::__construct($offset);
    }

    public function isBoolean(): bool
    {
        return $this->literal === null && $this->expr === null;
    }
}
