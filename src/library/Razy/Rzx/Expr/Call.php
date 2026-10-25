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
 * `name(arg, …)`: a call to a registered expression function. Only a bare
 * name can be called; `a.b()` is a parse error.
 */
final class Call extends Expr
{
    /**
     * @param list<Expr> $arguments
     */
    public function __construct(int $offset, public readonly string $name, public readonly array $arguments)
    {
        parent::__construct($offset);
    }
}
