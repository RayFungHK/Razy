<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Routing;

use Razy\Agent;
use Razy\Route;
use Throwable;

/**
 * Registers routes on a distributor-reserved URL segment (e.g. {@code /api/*}) without
 * prepending the owning module alias ({@code /core/api/*}).
 *
 * Obtained via {@see Agent::reserve()}.
 */
class ReservedRouteRegistrar
{
    public function __construct(
        private readonly Agent $agent,
        private readonly string $segment,
    ) {
    }

    /**
     * Register standard routes under {@code /{segment}/…} (supports nested route maps).
     *
     * @param array|string $route Route key(s) or nested map (same shape as {@see Agent::addRoute()})
     * @param string|Route|null $path Closure path or nested definition when {@code $route} is a string
     *
     * @return $this
     *
     * @throws Throwable
     */
    public function addRoute(mixed $route, mixed $path = null): static
    {
        $this->agent->addReservedRoutePath($this->segment, $route, $path);

        return $this;
    }
}
