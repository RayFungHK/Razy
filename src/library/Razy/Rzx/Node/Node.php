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
 * Base of the `.rzx` markup AST. `$offset` is the byte offset of the node
 * (the `<` of a tag, the `{` of an expression, the first byte of text) in its
 * Source; Source::position() turns it into line:col.
 */
abstract class Node
{
    public function __construct(public readonly int $offset)
    {
    }
}
