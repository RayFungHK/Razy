<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 *
 * @license MIT
 */

namespace Razy\Authz;

/**
 * RZ-06: who the current request acts for. The framework knows only an id
 * and an opaque attribute bag; the owning module decides what they mean.
 */
final class Principal
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public readonly string $id,
        public readonly array $attributes = [],
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }
}
