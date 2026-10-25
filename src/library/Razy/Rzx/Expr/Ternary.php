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
 * `test ? then : else`.
 */
final class Ternary extends Expr
{
    public function __construct(
        int $offset,
        public readonly Expr $test,
        public readonly Expr $then,
        public readonly Expr $else,
    ) {
        parent::__construct($offset);
    }
}
