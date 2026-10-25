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

namespace Razy\Rzx\Expr;

/**
 * `[a, b, c]`.
 */
final class ListLiteral extends Expr
{
    /**
     * @param list<Expr> $items
     */
    public function __construct(int $offset, public readonly array $items)
    {
        parent::__construct($offset);
    }
}
