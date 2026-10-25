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
 * `left op right`, op one of `|| && ?? == != < <= > >= ~ + -`.
 *
 * `~` joins two values as strings; `+` and `-` are numeric only.
 */
final class Binary extends Expr
{
    public function __construct(
        int $offset,
        public readonly string $operator,
        public readonly Expr $left,
        public readonly Expr $right,
    ) {
        parent::__construct($offset);
    }
}
