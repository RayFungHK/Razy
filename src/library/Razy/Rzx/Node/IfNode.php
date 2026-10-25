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
 * `<if test>…</if>`, then any `<elseif test>…</elseif>` and an optional
 * `<else>…</else>` that follow it as siblings.
 */
final class IfNode extends Node
{
    /** @var list<array{Expr, list<Node>}> [test, children] per branch; the first is the `<if>` */
    public array $branches;

    /** @var list<Node>|null */
    public ?array $else = null;

    /**
     * @param list<Node> $children
     */
    public function __construct(int $offset, Expr $test, array $children)
    {
        parent::__construct($offset);
        $this->branches = [[$test, $children]];
    }
}
