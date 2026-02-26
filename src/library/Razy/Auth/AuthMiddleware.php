<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * @package Razy
 *
 * @license MIT
 */

namespace Razy\Auth;

use Closure;
use Razy\Contract\MiddlewareInterface;

/**
 * Authentication middleware — guards routes against unauthenticated access.
 *
 * Short-circuits the middleware pipeline if the user is not authenticated
 * via the specified guard. Returns a 401 status or delegates to a
 * custom rejection handler.
 *
 * Usage:
 * ```php
 * // Simple usage with default guard
 * $route->middleware(new AuthMiddleware($authManager));
 *
 * // With specific guard
 * $route->middleware(new AuthMiddleware($authManager, 'api'));
 *
 * // With custom rejection handler
 * $route->middleware(new AuthMiddleware($authManager, null, function (array $context) {
 *     header('Location: /login');
 *     return null;
 * }));
 * ```
 *
 * @package Razy\Auth
 */
class AuthMiddleware implements MiddlewareInterface
{
    /**
     * @param AuthManager $auth The auth manager
     * @param string|null $guard Guard name (null for default)
     * @param Closure|null $onUnauthorized Optional handler when auth fails.
     *                                     Receives (array $context) and should return mixed.
     *                                     If null, sets HTTP 401 and returns null.
     */
    public function __construct(
        private readonly AuthManager $auth,
        private readonly ?string $guard = null,
        private readonly ?Closure $onUnauthorized = null,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function handle(array $context, Closure $next): mixed
    {
        if (!$this->auth->guard($this->guard)->check()) {
            if ($this->onUnauthorized !== null) {
                return ($this->onUnauthorized)($context);
            }

            \http_response_code(401);

            return null;
        }

        return $next($context);
    }
}
