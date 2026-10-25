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
 * One token of the expression grammar.
 */
final class ExprToken
{
    public const NUMBER = 'number';

    public const STRING = 'string';

    public const IDENT = 'ident';

    public const PUNCT = 'punct';

    public const END = 'end';

    public function __construct(
        public readonly string $type,
        public readonly string|int|float $value,
        public readonly int $offset,
    ) {
    }

    public function is(string $punct): bool
    {
        return $this->type === self::PUNCT && $this->value === $punct;
    }

    public function describe(): string
    {
        return match ($this->type) {
            self::END => 'end of expression',
            self::STRING => 'string literal',
            self::NUMBER => 'number ' . $this->value,
            default => '`' . $this->value . '`',
        };
    }
}
