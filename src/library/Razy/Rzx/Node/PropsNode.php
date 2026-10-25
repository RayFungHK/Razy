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
 * The `<props>` block. `$php` is its raw body, a PHP `return [ … Prop:: … ];`
 * that the compiler checks and evaluates once at compile time (RZ-28b), and
 * `$bodyOffset` is where that body starts in the Source.
 */
final class PropsNode extends Node
{
    public function __construct(int $offset, public readonly string $php, public readonly int $bodyOffset)
    {
        parent::__construct($offset);
    }
}
