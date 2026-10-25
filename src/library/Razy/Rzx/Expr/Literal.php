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
 * A string, number, `true`, `false` or `null` literal.
 */
final class Literal extends Expr
{
    public function __construct(int $offset, public readonly string|int|float|bool|null $value)
    {
        parent::__construct($offset);
    }
}
