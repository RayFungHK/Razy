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
 * RZ-06: what a denial renderer is told. `status` is 401 (signed out on a
 * route that needs an ability) or 403 (signed in but refused, or the ability
 * is undefined: fail closed).
 */
final class Refusal
{
    /**
     * @param array<string, mixed> $context routed context (module, route, method, …)
     */
    public function __construct(
        public readonly int $status,
        public readonly string $ability,
        public readonly bool $undefined,
        public readonly bool $unauthenticated,
        public readonly array $context,
        public readonly ?string $loginUrl,
    ) {
    }
}
