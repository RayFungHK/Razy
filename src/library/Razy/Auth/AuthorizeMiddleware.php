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
 * @license MIT
 */

namespace Razy\Auth;

use Closure;
use Razy\Contract\MiddlewareInterface;

/**
 * Authorization middleware — guards routes against unauthorized access.
 *
 * Checks if the authenticated user has the specified ability via the Gate.
 * Short-circuits the pipeline with a 403 status if the user is denied.
 *
 * Usage:
 * ```php
 * // Check a simple ability
 * $route->middleware(new AuthorizeMiddleware($gate, 'manage-users'));
 *
 * // Check with arguments (extracted from context at runtime)
 * $route->middleware(new AuthorizeMiddleware($gate, 'edit-post', function (array $ctx) {
 *     return [Post::find($ctx['arguments'][0])];
 * }));
 *
 * // With custom rejection handler
 * $route->middleware(new AuthorizeMiddleware($gate, 'admin', onForbidden: function (array $ctx) {
 *     http_response_code(403);
 *     echo json_encode(['error' => 'Forbidden']);
 *     return null;
 * }));
 * ```
 *
 * @package Razy\Auth
 */
class AuthorizeMiddleware implements MiddlewareInterface
{
    /**
     * @param Gate         $gate             The authorization gate
     * @param string       $ability          The ability to check
     * @param Closure|null $argumentResolver Optional closure to extract gate arguments
     *                                       from the route context: fn(array $ctx): array
     * @param Closure|null $onForbidden      Optional handler when authorization fails.
     *                                       Receives (array $context) and should return mixed.
     *                                       If null, sets HTTP 403 and returns null.
     */
    public function __construct(
        private readonly Gate $gate,
        private readonly string $ability,
        private readonly ?Closure $argumentResolver = null,
        private readonly ?Closure $onForbidden = null,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function handle(array $context, Closure $next): mixed
    {
        $arguments = [];

        if ($this->argumentResolver !== null) {
            $arguments = ($this->argumentResolver)($context);
            if (!is_array($arguments)) {
                $arguments = [$arguments];
            }
        }

        if ($this->gate->denies($this->ability, ...$arguments)) {
            if ($this->onForbidden !== null) {
                return ($this->onForbidden)($context);
            }

            http_response_code(403);

            return null;
        }

        return $next($context);
    }
}
