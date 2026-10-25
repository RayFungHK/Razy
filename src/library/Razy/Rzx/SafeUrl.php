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
 * A URL built by trusted code, such as `asset()` for a module's own file,
 * which may be absolute (a CDN). On resource sinks (`<script src>`,
 * `<iframe src>`, …) only a SafeUrl or a same-origin path is accepted; its
 * scheme is still checked.
 */
final class SafeUrl
{
    public function __construct(public readonly string $url)
    {
    }

    public function __toString(): string
    {
        return $this->url;
    }
}
