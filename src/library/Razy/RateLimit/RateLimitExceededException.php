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

namespace Razy\RateLimit;

use RuntimeException;

/**
 * Exception thrown when a rate limit is exceeded.
 *
 * Contains metadata about the limit that was hit, including the maximum
 * allowed attempts, seconds until the window resets, and the number of
 * remaining retries (always 0 when thrown).
 *
 * ```php
 * try {
 *     $rateLimiter->attempt('api:192.168.1.1', 60, 60);
 * } catch (RateLimitExceededException $e) {
 *     // $e->getMaxAttempts()  → 60
 *     // $e->getRetryAfter()   → seconds until reset
 *     // $e->getKey()          → 'api:192.168.1.1'
 * }
 * ```
 */
class RateLimitExceededException extends RuntimeException
{
    /**
     * @param string $key The rate limit bucket key that was exceeded.
     * @param int $maxAttempts The maximum attempts allowed within the window.
     * @param int $retryAfter Seconds remaining until the window resets.
     */
    public function __construct(
        private readonly string $key,
        private readonly int $maxAttempts,
        private readonly int $retryAfter,
    ) {
        parent::__construct(
            \sprintf(
                'Rate limit exceeded for key "%s": %d attempts allowed, retry after %d seconds.',
                $key,
                $maxAttempts,
                $retryAfter,
            ),
            429,
        );
    }

    /**
     * Get the rate limit bucket key that was exceeded.
     *
     * @return string
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * Get the maximum number of attempts allowed within the window.
     *
     * @return int
     */
    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    /**
     * Get the number of seconds until the rate limit window resets.
     *
     * @return int
     */
    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }
}
