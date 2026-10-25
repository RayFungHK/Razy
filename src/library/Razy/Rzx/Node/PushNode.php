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
 * `<push to="anchor" key="…" order={10}>…</push>`: content pushed into a
 * layout anchor at render time (`Razy\View\Anchors::push()`, RZ-27).
 */
final class PushNode extends Node
{
    /**
     * @param list<Node> $children
     */
    public function __construct(
        int $offset,
        public readonly string $to,
        public readonly ?string $key,
        public readonly ?Expr $order,
        public readonly array $children,
    ) {
        parent::__construct($offset);
    }
}
