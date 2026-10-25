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

use Razy\Rzx\Runtime\AnchorSink;
use Razy\View\Anchors;

/**
 * `<push>` and `<Anchor>` of compiled `.rzx` go to the Template's per-request
 * Razy\View\Anchors (RZ-27), so `.rzx` and `.tpl` share one registry, with its
 * nonce injection, inline-script check and dedupe.
 */
final class TemplateAnchors implements AnchorSink
{
    public function __construct(private readonly Anchors $anchors)
    {
    }

    public function push(string $anchor, string $html, ?string $key = null, int $order = 0): void
    {
        $this->anchors->push($anchor, $html, $key, $order);
    }

    public function marker(string $name): string
    {
        return $this->anchors->marker($name);
    }
}
