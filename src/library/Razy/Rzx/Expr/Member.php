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
 * `object.name`: reads an array key or a public property, never a method.
 */
final class Member extends Expr
{
    public function __construct(int $offset, public readonly Expr $object, public readonly string $name)
    {
        parent::__construct($offset);
    }
}
