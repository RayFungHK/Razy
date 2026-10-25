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
 * `{expr}` in text: the value, always escaped for its context.
 */
final class OutputNode extends Node
{
    public function __construct(int $offset, public readonly Expr $expr)
    {
        parent::__construct($offset);
    }
}
