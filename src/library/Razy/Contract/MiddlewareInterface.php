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

namespace Razy\Contract;

use Closure;

/**
 * Middleware contract for HTTP request/response interception.
 *
 * Middleware classes implement this interface and are executed in a pipeline
 * around the route handler. Each middleware receives the route context and
 * a `$next` callback to pass control to the next middleware (or the final handler).
 *
 * Middleware can:
 * - Inspect/modify request state before the handler runs (pre-processing)
 * - Short-circuit the pipeline by NOT calling `$next` (e.g., auth rejection)
 * - Inspect/modify the result after the handler runs (post-processing)
 *
 * Example:
 * ```php
 * class AuthMiddleware implements MiddlewareInterface
 * {
 *     public function handle(array $context, Closure $next): mixed
 *     {
 *         if (!$this->isAuthenticated($context)) {
 *             http_response_code(401);
 *             return null; // Short-circuit — handler never executes
 *         }
 *         return $next($context); // Pass to next middleware or handler
 *     }
 * }
 * ```
 *
 * @package Razy\Contract
 */
interface MiddlewareInterface
{
    /**
     * Handle the request through this middleware.
     *
     * @param array $context Route context containing:
     *                       - 'url_query'    => string  The matched URL
     *                       - 'route'        => string  The route pattern
     *                       - 'module'       => string  The module code
     *                       - 'closure_path' => string  Path to the closure
     *                       - 'arguments'    => array   URL arguments
     *                       - 'method'       => string  HTTP method ('GET', 'POST', '*')
     *                       - 'type'         => string  Route type ('standard', 'lazy', 'script')
     *                       - 'is_shadow'    => bool    Whether this is a shadow route
     *                       - 'contains'     => mixed   (optional) Data from Route::contain()
     * @param Closure $next Call to pass to the next middleware or final handler.
     *                      Signature: function(array $context): mixed
     *
     * @return mixed The result from the handler chain (usually void for HTTP)
     */
    public function handle(array $context, Closure $next): mixed;
}
