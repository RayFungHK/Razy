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
 * Literal text. `$text` is whitespace-normalised the JSX way (lines trimmed,
 * blank lines dropped, line breaks become one space); entities such as
 * `&amp;` are kept as written.
 */
final class TextNode extends Node
{
    public function __construct(int $offset, public readonly string $text)
    {
        parent::__construct($offset);
    }
}
