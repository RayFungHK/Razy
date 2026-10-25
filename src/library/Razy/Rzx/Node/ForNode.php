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
 * `<for each={rows} as="row" key="id" index="i">…<empty>…</empty></for>`.
 */
final class ForNode extends Node
{
    /**
     * @param list<Node> $children the loop body
     * @param list<Node>|null $empty the `<empty>` body, rendered when the list is empty
     */
    public function __construct(
        int $offset,
        public readonly Expr $each,
        public readonly string $as,
        public readonly ?string $key,
        public readonly ?string $index,
        public readonly array $children,
        public readonly ?array $empty,
    ) {
        parent::__construct($offset);
    }
}
