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

/**
 * HTML that trusted code built (a markdown renderer, a sanitiser), the only
 * value `{html(x)}` prints unescaped. A plain string never is.
 *
 * Build one only from output you already escaped or sanitised; never wrap
 * request input.
 */
final class SafeHtml
{
    public function __construct(public readonly string $html)
    {
    }

    public function __toString(): string
    {
        return $this->html;
    }
}
