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

namespace Razy\Csrf;

use Closure;
use Razy\Distributor;
use Razy\Session\SessionDoor;
use Razy\Session\SessionMiddleware;

/**
 * The CSRF door: one config key arms the shipped engine (CSRF-RAIL.md L1).
 *
 * `dist.php` `'csrf' => 'on'` is the WHOLE authoring surface. At Distributor
 * boot this wires, in order: the dist session (its cookie identity now
 * real since L0), the CsrfTokenManager over it, the global middleware pair
 * SessionMiddleware → CsrfMiddleware (session MUST be outermost: the token
 * validates between start() and save()), and publishes the manager on the
 * DI container so `Controller::csrfToken()/csrfField()` resolve it.
 *
 * Deliberate non-config (the door stays one key):
 * - the session is the dist's one session (RZ-05, Distributor::getSession()),
 *   configured by dist.php 'session'; without it: file driver in the system
 *   temp dir under a per-dist prefix, host-only, SameSite=Lax, HttpOnly,
 *   browser-session lifetime, Secure when the request is HTTPS (judged per
 *   request, trusted proxies included — RZ-04). arm() does no I/O: the file
 *   driver creates its folder on the first request, and database/redis
 *   drivers connect (and run create_table) on first use, not at boot.
 */
class CsrfDoor
{
    /**
     * Arm one distributor. Called by the Distributor ctor only when the
     * dist declared `'csrf' => 'on'` — this class never reads dist config.
     */
    public static function arm(Distributor $distributor): void
    {
        // RZ-05: the dist's one session (dist.php 'session'), not a private store.
        $session = $distributor->getSession();
        SessionDoor::publish($distributor, $session);

        $manager = new CsrfTokenManager($session);

        $rejection = new CsrfRejection(
            static fn (string $moduleCode) => $distributor->getRegistry()->get($moduleCode),
        );

        $csrf = new CsrfMiddleware(
            tokenManager: $manager,
            // First-class callable: the engine's onMismatch is typed
            // ?Closure (shipped signature), the answer surface is a
            // class — the (...) form bridges without widening a released API.
            onMismatch: $rejection(...),
            // The door ships the GOOD default (Q5): a validated token
            // rotates per request, shrinking the replay window.
            rotateOnSuccess: true,
        );

        // Registration order IS the onion order (pipeMany keeps array order,
        // first-piped is outermost): session wraps token validation.
        $distributor->getRouter()->addGlobalMiddleware(
            new SessionMiddleware($session),
            // L2 wrapper: a route declaring ->csrfExempt(reason) skips
            // validation via its routed context (RouteDispatcher copies the
            // declaration into `csrf_exempt` at match time). The reason is
            // enforced on the Route entity, so a bypass can never be
            // reasonless — this reads the flag, it cannot create one.
            //
            // The safe-method short-circuit HERE is load-bearing (found
            // live at L3, the form page itself 419ed): the engine checks
            // $context['method'], but the dispatcher fills that with the
            // ROUTE CONSTRAINT — an unconstrained route says '*', not the
            // actual GET. The request method is only ever true in
            // $_SERVER (worker mode refreshes it per request). Everything
            // non-safe delegates to the engine untouched.
            static function (array $context, Closure $next) use ($csrf): mixed {
                $requestMethod = \strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

                if (\in_array($requestMethod, ['GET', 'HEAD', 'OPTIONS'], true)) {
                    return $next($context);
                }

                if (($context['csrf_exempt'] ?? null) !== null) {
                    return $next($context);
                }

                return $csrf->handle($context, $next);
            },
        );

        if ($container = $distributor->getContainer()) {
            $container->instance(CsrfTokenManager::class, $manager);
        }
    }
}
