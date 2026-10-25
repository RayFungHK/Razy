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

use Razy\Rzx\Source;

/**
 * A whole `.rzx` file: optional `<props>`, exactly one root, then zero or more
 * `<push>` blocks (each a PushNode, or an IfNode whose branches hold only pushes).
 */
final class DocumentNode extends Node
{
    /**
     * @param list<PushNode|IfNode> $pushes
     */
    public function __construct(
        public readonly Source $source,
        public readonly ?PropsNode $props,
        public readonly Node $root,
        public readonly array $pushes,
    ) {
        parent::__construct(0);
    }
}
