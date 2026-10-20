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
 *
 * RZ-06: with an identity resolver registered, a signed-out request on an
 * ability route is refused as 401 before any ability runs, and ability
 * predicates receive `$context['principal']`.
 */
class Middleware implements MiddlewareInterface
{
    /**
     * @param Closure $denial fn(array $context, string $ability, bool $undefined): mixed;
     *                        a signed-out refusal has `$context['unauthenticated'] = true` (RZ-06)
     * @param Identity|null $identity RZ-06: the dist's request-scoped principal (null = none)
     */
    public function __construct(
        private readonly Gate $gate,
        private readonly Closure $denial,
        private readonly ?Identity $identity = null,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function handle(array $context, Closure $next): mixed
    {
        // RZ-06: a new request never inherits the last one's principal.
        $this->identity?->reset();

        $ability = $context['authz'] ?? null;

        if ($ability === null || !\is_string($ability) || $ability === '') {
            return $next($context);
        }

        if ($this->identity?->hasResolver()) {
            $principal = $this->identity->principal($context);
            if ($principal === null) {
                // Signed out: 401 (sign in), not 403. The ability is never asked.
                return ($this->denial)(['unauthenticated' => true] + $context, $ability, false);
            }
            $context['principal'] = $principal;
        }

        $verdict = $this->gate->check($ability, $context);

        if ($verdict === true) {
            return $next($context);
        }

        return ($this->denial)($context, $ability, $verdict === null);
    }
}
