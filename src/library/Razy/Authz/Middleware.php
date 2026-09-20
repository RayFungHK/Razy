<?php

/**
 * This file is part of Razy v0.5.
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

use Closure;
use Razy\Contract\MiddlewareInterface;

/**
 * Global middleware enforcing route-declared abilities (AUTHZ-RAIL).
 *
 * Reads the ability NAME the dispatcher copied into routed context
 * (`$context['authz']`, same travel shape as csrf_exempt) and asks the
 * dist's Gate. Routes without a declaration pass through untouched —
 * mounting this middleware is inert on every site that never calls
 * `Route::can()`, which keeps it permanently mounted (zero-BC doors,
 * the CSRF rail's posture).
 *
 * Unlike CSRF this guards EVERY verb — authorization is about who may
 * access, not about replay: a GET page view is exactly what view-
 * permissions protect. Fail-closed: an undefined ability denies (the
 * Denial names that misconfiguration honestly rather than waving the
 * request past a guard that was never installed).
 */
class Middleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Gate $gate,
        private readonly Closure $denial,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function handle(array $context, Closure $next): mixed
    {
        $ability = $context['authz'] ?? null;

        if ($ability === null || !\is_string($ability) || $ability === '') {
            return $next($context);
        }

        $verdict = $this->gate->check($ability, $context);

        if ($verdict === true) {
            return $next($context);
        }

        return ($this->denial)($context, $ability, $verdict === null);
    }
}
