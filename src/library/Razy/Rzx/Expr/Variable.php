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
 * A bare name: a prop, a loop variable, or a slot-scope name (`row`).
 */
final class Variable extends Expr
{
    public function __construct(int $offset, public readonly string $name)
    {
        parent::__construct($offset);
    }
}
