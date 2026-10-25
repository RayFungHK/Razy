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

namespace Razy\Rzx\Runtime;

/**
 * Where `<push>` content goes and what `<Anchor>` prints. Razy wires the
 * per-request `Razy\View\Anchors` registry behind this (RZ-27, RZ-28c), so
 * nonce injection, the inline-script check and dedupe stay in one place.
 */
interface AnchorSink
{
    public function push(string $anchor, string $html, ?string $key = null, int $order = 0): void;

    public function marker(string $name): string;
}
