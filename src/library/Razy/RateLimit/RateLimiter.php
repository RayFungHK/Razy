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

use Closure;
use Razy\Contract\RateLimitStoreInterface;

/**
 * Core rate limiter — manages hit counters and named limit definitions.
 *
 * Provides a fixed-window rate limiting algorithm backed by a pluggable store.
 * Supports named limiters for reuse across multiple middleware instances.
 *
 * **Fixed-window algorithm:** Each key tracks a hit count and a reset timestamp.
 * When the window expires, the counter resets automatically on the next hit.
 *
 * Usage:
 * ```php
 * $limiter = new RateLimiter(new ArrayStore());
 *
 * // Register named limiters
 * $limiter->for('api', fn(array $context) =>
 *     Limit::perMinute(60)->by($context['ip'] ?? 'unknown')
 * );
 *
 * // Direct usage
 * if ($limiter->tooManyAttempts('login:user@example.com', 5)) {
 *     echo 'Too many login attempts. Try again in ' . $limiter->availableIn('login:user@example.com') . 's';
 * } else {
 *     $limiter->hit('login:user@example.com', 60);
 * }
 * ```
 */
class RateLimiter
{
    /**
     * Registry of named limiter callbacks.
     * Keys are limiter names, values are closures that accept
     * `(array $context)` and return a `Limit` instance.
     *
     * @var array<string, Closure>
     */
    private array $limiters = [];

    /**
     * Optional clock override for testing.
     * When set, this value is used instead of `time()`.
     */
    private ?int $currentTime = null;

    /**
     * @param RateLimitStoreInterface $store The storage backend for hit counters.
     */
    public function __construct(
        private readonly RateLimitStoreInterface $store,
    ) {
    }

    /**
     * Register a named rate limiter.
     *
     * The callback receives the middleware context array and must return
     * a `Limit` instance defining the rate limit for that request.
     *
     * @param string $name Unique limiter name (e.g., 'api', 'login').
     * @param Closure $callback Receives `(array $context)`, returns `Limit`.
     */
    public function for(string $name, Closure $callback): void
    {
        $this->limiters[$name] = $callback;
    }

    /**
     * Retrieve a registered named limiter callback.
     *
     * @param string $name The limiter name.
     *
     * @return Closure|null The callback, or null if not registered.
     */
    public function limiter(string $name): ?Closure
    {
        return $this->limiters[$name] ?? null;
    }

    /**
     * Check if a named limiter has been registered.
     *
     * @param string $name The limiter name.
     *
     * @return bool True if the limiter exists.
     */
    public function hasLimiter(string $name): bool
    {
        return isset($this->limiters[$name]);
    }

    /**
     * Attempt an action, recording a hit if within the limit.
     *
     * Returns `true` if the attempt is allowed (within limit), `false` if
     * the limit has been exceeded. When allowed, the hit counter is
     * automatically incremented.
     *
     * @param string $key The rate limit bucket key.
     * @param int $maxAttempts Maximum attempts within the window.
     * @param int $decaySeconds Duration of the window in seconds.
     *
     * @return bool True if the attempt is permitted.
     */
    public function attempt(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        if ($this->tooManyAttempts($key, $maxAttempts)) {
            return false;
        }

        $this->hit($key, $decaySeconds);

        return true;
    }

    /**
     * Check whether the maximum number of attempts has been exceeded for a key.
     *
     * Does NOT increment the counter — this is a read-only check.
     *
     * @param string $key The rate limit bucket key.
     * @param int $maxAttempts Maximum attempts allowed.
     *
     * @return bool True if the limit has been exceeded.
     */
    public function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        $record = $this->store->get($key);

        if ($record === null) {
            return false;
        }

        // Window expired — clear stale record
        if ($record['resetAt'] <= $this->now()) {
            $this->store->delete($key);

            return false;
        }

        return $record['hits'] >= $maxAttempts;
    }

    /**
     * Record a hit for a key, starting a new window if needed.
     *
     * If no active window exists for the key (or the previous window has
     * expired), a new window is created with `resetAt = now + decaySeconds`.
     *
     * @param string $key The rate limit bucket key.
     * @param int $decaySeconds Duration of the window in seconds.
     *
     * @return int The total number of hits in the current window (after this hit).
     */
    public function hit(string $key, int $decaySeconds): int
    {
        $record = $this->store->get($key);
        $now = $this->now();

        // Start a new window if no record exists or window has expired
        if ($record === null || $record['resetAt'] <= $now) {
            $this->store->set($key, 1, $now + $decaySeconds);

            return 1;
        }

        // Increment within the existing window
        $hits = $record['hits'] + 1;
        $this->store->set($key, $hits, $record['resetAt']);

        return $hits;
    }

    /**
     * Get the number of remaining attempts for a key.
     *
     * @param string $key The rate limit bucket key.
     * @param int $maxAttempts Maximum attempts allowed.
     *
     * @return int Remaining attempts (0 if limit reached, never negative).
     */
    public function remaining(string $key, int $maxAttempts): int
    {
        $record = $this->store->get($key);

        if ($record === null || $record['resetAt'] <= $this->now()) {
            return $maxAttempts;
        }

        return \max(0, $maxAttempts - $record['hits']);
    }

    /**
     * Get the number of seconds until the rate limit window resets.
     *
     * @param string $key The rate limit bucket key.
     *
     * @return int Seconds until reset (0 if no active window).
     */
    public function availableIn(string $key): int
    {
        $record = $this->store->get($key);

        if ($record === null) {
            return 0;
        }

        $seconds = $record['resetAt'] - $this->now();

        return \max(0, $seconds);
    }

    /**
     * Get the Unix timestamp at which the current window resets.
     *
     * @param string $key The rate limit bucket key.
     *
     * @return int Reset timestamp (0 if no active window).
     */
    public function resetAt(string $key): int
    {
        $record = $this->store->get($key);

        if ($record === null) {
            return 0;
        }

        return $record['resetAt'];
    }

    /**
     * Get the current number of hits recorded for a key.
     *
     * @param string $key The rate limit bucket key.
     *
     * @return int Current hits (0 if no active window).
     */
    public function attempts(string $key): int
    {
        $record = $this->store->get($key);

        if ($record === null || $record['resetAt'] <= $this->now()) {
            return 0;
        }

        return $record['hits'];
    }

    /**
     * Clear the hit counter for a key, resetting the rate limit.
     *
     * @param string $key The rate limit bucket key.
     */
    public function clear(string $key): void
    {
        $this->store->delete($key);
    }

    /**
     * Resolve a named limiter for a given context.
     *
     * Invokes the registered callback with the provided context to produce
     * a `Limit` instance. Returns `null` if the limiter is not registered.
     *
     * @param string $name The limiter name.
     * @param array $context The middleware context (route info, IP, etc.).
     *
     * @return Limit|null The resolved Limit, or null if no limiter registered.
     */
    public function resolve(string $name, array $context = []): ?Limit
    {
        $callback = $this->limiter($name);

        if ($callback === null) {
            return null;
        }

        return $callback($context);
    }

    /**
     * Get the underlying store instance.
     *
     * @return RateLimitStoreInterface
     */
    public function getStore(): RateLimitStoreInterface
    {
        return $this->store;
    }

    /**
     * Override the clock for deterministic testing.
     *
     * When set, all time-based calculations use this value instead of
     * the real system clock. Pass `null` to revert to real time.
     *
     * @param int|null $timestamp Unix timestamp, or null to use real time.
     */
    public function setCurrentTime(?int $timestamp): void
    {
        $this->currentTime = $timestamp;
    }

    /**
     * Get the current time — uses override if set, otherwise real time.
     *
     * @return int
     */
    private function now(): int
    {
        return $this->currentTime ?? \time();
    }
}
