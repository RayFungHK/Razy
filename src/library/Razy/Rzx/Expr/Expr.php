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
 * Base of the `.rzx` expression AST (the `{…}` grammar).
 *
 * `$offset` is the byte offset of the first character of the expression in
 * its Source, for error mapping.
 */
abstract class Expr
{
    public function __construct(public readonly int $offset)
    {
    }
}
