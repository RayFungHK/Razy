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

/**
 * `<Anchor name="head.styles" />` in a layout: where pushes to that anchor
 * are emitted (`Razy\View\Anchors::marker()`, RZ-27).
 */
final class AnchorNode extends Node
{
    public function __construct(int $offset, public readonly string $name)
    {
        parent::__construct($offset);
    }
}
