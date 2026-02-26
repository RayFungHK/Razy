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

/**
 * Value object representing a rate limit configuration.
 *
 * Defines the maximum number of attempts allowed within a time window (decay period).
 * Supports fluent construction via static factory methods:
 *
 * ```php
 * // 60 requests per minute, keyed by IP
 * $limit = Limit::perMinute(60)->by($ip);
 *
 * // 1000 requests per hour with custom key
 * $limit = Limit::perHour(1000)->by('user:' . $userId);
 *
 * // Custom: 10 requests every 30 seconds
 * $limit = Limit::every(30, 10)->by($key);
 *
 * // No limit (unlimited access)
 * $limit = Limit::none();
 * ```
 */
class Limit
{
    /**
     * The key used to identify this rate limit bucket.
     * Different keys track separate hit counters.
     */
    private string $key = '';

    /**
     * Whether this limit represents "unlimited" (no throttling).
     */
    private bool $unlimited = false;

    /**
     * @param int $maxAttempts Maximum number of attempts allowed within the decay window.
     * @param int $decaySeconds Duration of the time window in seconds before the counter resets.
     */
    private function __construct(
        private readonly int $maxAttempts,
        private readonly int $decaySeconds,
    ) {
    }

    /**
     * Create a limit allowing $maxAttempts per minute.
     *
     * @param int $maxAttempts Maximum attempts allowed per 60-second window.
     *
     * @return static
     */
    public static function perMinute(int $maxAttempts): static
    {
        return new static($maxAttempts, 60);
    }

    /**
     * Create a limit allowing $maxAttempts per hour.
     *
     * @param int $maxAttempts Maximum attempts allowed per 3600-second window.
     *
     * @return static
     */
    public static function perHour(int $maxAttempts): static
    {
        return new static($maxAttempts, 3600);
    }

    /**
     * Create a limit allowing $maxAttempts per day.
     *
     * @param int $maxAttempts Maximum attempts allowed per 86400-second window.
     *
     * @return static
     */
    public static function perDay(int $maxAttempts): static
    {
        return new static($maxAttempts, 86400);
    }

    /**
     * Create a limit allowing $maxAttempts within a custom decay period.
     *
     * @param int $decaySeconds Duration of the time window in seconds.
     * @param int $maxAttempts Maximum attempts allowed within the window.
     *
     * @return static
     */
    public static function every(int $decaySeconds, int $maxAttempts): static
    {
        return new static($maxAttempts, $decaySeconds);
    }

    /**
     * Create an unlimited limit (no throttling applied).
     *
     * When resolved in the RateLimiter, requests with a `none()` limit
     * are always permitted without tracking.
     *
     * @return static
     */
    public static function none(): static
    {
        $limit = new static(PHP_INT_MAX, 0);
        $limit->unlimited = true;

        return $limit;
    }

    /**
     * Set the key for this rate limit bucket.
     *
     * The key determines which counter is used. Typically derived from
     * the request IP, user ID, or a combination of route + identifier.
     *
     * @param string $key The bucket identifier.
     *
     * @return static Fluent return for chaining.
     */
    public function by(string $key): static
    {
        $this->key = $key;

        return $this;
    }

    /**
     * Get the maximum number of attempts allowed within the decay window.
     *
     * @return int
     */
    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    /**
     * Get the decay window duration in seconds.
     *
     * @return int
     */
    public function getDecaySeconds(): int
    {
        return $this->decaySeconds;
    }

    /**
     * Get the bucket key for this limit.
     *
     * @return string
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * Check whether this limit represents "unlimited" (no throttling).
     *
     * @return bool True if this is a `Limit::none()` instance.
     */
    public function isUnlimited(): bool
    {
        return $this->unlimited;
    }
}
