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

namespace Razy\RateLimit;

use Closure;
use Razy\Contract\MiddlewareInterface;

/**
 * Rate limiting middleware — throttles requests using named rate limiters.
 *
 * Integrates the `RateLimiter` into the middleware pipeline. Resolves the
 * named limiter for each request, tracks hits, and short-circuits with
 * HTTP 429 (Too Many Requests) when the limit is exceeded.
 *
 * Adds standard rate limit headers to the response:
 * - `X-RateLimit-Limit`     — Maximum attempts allowed
 * - `X-RateLimit-Remaining` — Remaining attempts in the current window
 * - `X-RateLimit-Reset`     — Unix timestamp when the window resets
 * - `Retry-After`           — Seconds until the limit resets (only on 429)
 *
 * Usage:
 * ```php
 * $limiter = new RateLimiter(new ArrayStore());
 * $limiter->for('api', fn(array $ctx) =>
 *     Limit::perMinute(60)->by($ctx['ip'] ?? 'unknown')
 * );
 *
 * // Attach to route or global middleware
 * $route->middleware(new RateLimitMiddleware($limiter, 'api'));
 * ```
 *
 * Custom key resolver (override the Limit's key):
 * ```php
 * new RateLimitMiddleware($limiter, 'api', function (array $context) {
 *     return 'custom:' . ($context['user_id'] ?? 'guest');
 * });
 * ```
 *
 * Custom rejection handler:
 * ```php
 * new RateLimitMiddleware($limiter, 'api', null, function (array $context, Limit $limit) {
 *     return ['error' => 'Rate limit exceeded', 'retry_after' => ...];
 * });
 * ```
 *
 * @package Razy\RateLimit
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    /**
     * @param RateLimiter $limiter The rate limiter instance.
     * @param string $name The name of the registered limiter to use.
     * @param Closure|null $keyResolver Optional. Override the key from the Limit.
     *                                  Receives `(array $context)`, returns `string`.
     * @param Closure|null $onLimitExceeded Optional. Custom handler when limit is exceeded.
     *                                      Receives `(array $context, Limit $limit)`, returns mixed.
     *                                      If null, sets HTTP 429 and returns null.
     * @param bool $sendHeaders Whether to send rate limit response headers.
     */
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly string $name,
        private readonly ?Closure $keyResolver = null,
        private readonly ?Closure $onLimitExceeded = null,
        private readonly bool $sendHeaders = true,
    ) {
    }

    /**
     * Handle the request through the rate limiter.
     *
     * Resolves the named limiter, checks the current hit count, and either
     * allows the request through (with rate limit headers) or short-circuits
     * with a 429 response.
     *
     * {@inheritdoc}
     */
    public function handle(array $context, Closure $next): mixed
    {
        $limit = $this->limiter->resolve($this->name, $context);

        // No limiter registered for this name — pass through
        if ($limit === null) {
            return $next($context);
        }

        // Unlimited — pass through without tracking
        if ($limit->isUnlimited()) {
            return $next($context);
        }

        // Resolve the bucket key
        $key = $this->resolveKey($limit, $context);
        $maxAttempts = $limit->getMaxAttempts();
        $decaySeconds = $limit->getDecaySeconds();

        // Check if limit is already exceeded
        if ($this->limiter->tooManyAttempts($key, $maxAttempts)) {
            return $this->handleLimitExceeded($context, $limit, $key, $maxAttempts);
        }

        // Record the hit
        $this->limiter->hit($key, $decaySeconds);

        // Send rate limit headers
        $remaining = $this->limiter->remaining($key, $maxAttempts);
        $resetAt = $this->limiter->resetAt($key);

        if ($this->sendHeaders) {
            $this->sendRateLimitHeaders($maxAttempts, $remaining, $resetAt);
        }

        // Pass through to next middleware / handler
        return $next($context);
    }

    /**
     * Get the rate limiter instance.
     *
     * @return RateLimiter
     */
    public function getRateLimiter(): RateLimiter
    {
        return $this->limiter;
    }

    /**
     * Get the limiter name used by this middleware.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Resolve the rate limit bucket key.
     *
     * Uses the custom key resolver if provided, otherwise falls back
     * to the key defined on the Limit object. Prepends the limiter
     * name as a namespace.
     *
     * @param Limit $limit The resolved limit configuration.
     * @param array $context The middleware context.
     *
     * @return string The fully-qualified bucket key.
     */
    private function resolveKey(Limit $limit, array $context): string
    {
        if ($this->keyResolver !== null) {
            $customKey = ($this->keyResolver)($context);

            return $this->name . ':' . $customKey;
        }

        $limitKey = $limit->getKey();

        if ($limitKey !== '') {
            return $this->name . ':' . $limitKey;
        }

        // Fallback: use the route pattern as key
        return $this->name . ':' . ($context['route'] ?? 'global');
    }

    /**
     * Handle a rate limit exceeded condition.
     *
     * Either delegates to the custom rejection handler or sets HTTP 429
     * with Retry-After header and returns null.
     *
     * @param array $context The middleware context.
     * @param Limit $limit The resolved limit configuration.
     * @param string $key The bucket key that was exceeded.
     * @param int $maxAttempts The maximum attempts allowed.
     *
     * @return mixed Result from the rejection handler, or null for default 429.
     */
    private function handleLimitExceeded(array $context, Limit $limit, string $key, int $maxAttempts): mixed
    {
        $retryAfter = $this->limiter->availableIn($key);
        $resetAt = $this->limiter->resetAt($key);

        if ($this->sendHeaders) {
            $this->sendRateLimitHeaders($maxAttempts, 0, $resetAt);
            $this->sendRetryAfterHeader($retryAfter);
        }

        if ($this->onLimitExceeded !== null) {
            return ($this->onLimitExceeded)($context, $limit, $retryAfter);
        }

        \http_response_code(429);

        return null;
    }

    /**
     * Send standard rate limit response headers.
     *
     * @param int $maxAttempts Maximum attempts allowed.
     * @param int $remaining Remaining attempts in the current window.
     * @param int $resetAt Unix timestamp when the window resets.
     */
    private function sendRateLimitHeaders(int $maxAttempts, int $remaining, int $resetAt): void
    {
        if (!\headers_sent()) {
            \header('X-RateLimit-Limit: ' . $maxAttempts);
            \header('X-RateLimit-Remaining: ' . $remaining);
            \header('X-RateLimit-Reset: ' . $resetAt);
        }
    }

    /**
     * Send the Retry-After header for 429 responses.
     *
     * @param int $retryAfter Seconds until the rate limit window resets.
     */
    private function sendRetryAfterHeader(int $retryAfter): void
    {
        if (!\headers_sent()) {
            \header('Retry-After: ' . $retryAfter);
        }
    }
}
