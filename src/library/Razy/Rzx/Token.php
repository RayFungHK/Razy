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

namespace Razy\Rzx;

use Razy\Rzx\Expr\ExprToken;

/**
 * One markup token produced by Lexer.
 *
 * | type       | value            | extra                                   |
 * |------------|------------------|-----------------------------------------|
 * | TEXT       | raw text         |                                         |
 * | EXPR       | ''               | $expr = expression tokens               |
 * | OPEN       | tag name         | (`<name`)                               |
 * | ATTR       | attribute name   | $literal or $expr, or neither (boolean) |
 * | OPEN_END   | '>'              |                                         |
 * | SELF_CLOSE | '/>'             |                                         |
 * | CLOSE      | tag name         | (`</name>`)                             |
 * | PROPS      | raw PHP body     | $bodyOffset                             |
 * | EOF        | ''               |                                         |
 */
final class Token
{
    public const TEXT = 'text';

    public const EXPR = 'expr';

    public const OPEN = 'open';

    public const ATTR = 'attr';

    public const OPEN_END = 'open_end';

    public const SELF_CLOSE = 'self_close';

    public const CLOSE = 'close';

    public const PROPS = 'props';

    public const EOF = 'eof';

    /**
     * @param list<ExprToken>|null $expr
     */
    public function __construct(
        public readonly string $type,
        public readonly string $value,
        public readonly int $offset,
        public readonly ?string $literal = null,
        public readonly ?array $expr = null,
        public readonly int $bodyOffset = 0,
    ) {
    }
}
