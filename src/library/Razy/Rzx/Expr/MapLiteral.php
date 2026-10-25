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
 * `{ key: value, "quoted key": value, [computed]: value }`.
 *
 * Each entry key is a Literal (plain or quoted key) or any Expr (computed key).
 */
final class MapLiteral extends Expr
{
    /**
     * @param list<array{Expr, Expr}> $entries [key, value] pairs, in source order
     */
    public function __construct(int $offset, public readonly array $entries)
    {
        parent::__construct($offset);
    }
}
