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

namespace Razy\RateLimit\Store;

use Razy\Cache\CacheInterface;
use Razy\Contract\RateLimitStoreInterface;

/**
 * Cache-backed rate limit store — for production use.
 *
 * Wraps any PSR-16 compatible `CacheInterface` implementation (FileAdapter,
 * ApcuAdapter, etc.) to persist hit counters across requests.
 *
 * Cache entries are stored with a TTL matching the rate limit decay window,
 * ensuring automatic cleanup of expired entries.
 *
 * Keys are prefixed with a configurable namespace to avoid collisions
 * with other cache entries.
 *
 * ```php
 * $cache = new FileAdapter('/tmp/cache');
 * $store = new CacheStore($cache);
 * $limiter = new RateLimiter($store);
 * ```
 *
 * @package Razy\RateLimit\Store
 */
class CacheStore implements RateLimitStoreInterface
{
    /**
     * @param CacheInterface $cache The PSR-16 cache adapter.
     * @param string $prefix Key prefix for rate limit entries.
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly string $prefix = 'ratelimit_',
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $key): ?array
    {
        $record = $this->cache->get($this->prefixKey($key));

        if ($record === null || !\is_array($record)) {
            return null;
        }

        // Validate record structure
        if (!isset($record['hits'], $record['resetAt'])) {
            return null;
        }

        // Expired — let the cache TTL handle cleanup, but don't return stale data
        if ($record['resetAt'] <= \time()) {
            $this->cache->delete($this->prefixKey($key));

            return null;
        }

        return $record;
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $key, int $hits, int $resetAt): void
    {
        // TTL = seconds until the window expires (minimum 1 second)
        $ttl = \max(1, $resetAt - \time());

        $this->cache->set($this->prefixKey($key), [
            'hits' => $hits,
            'resetAt' => $resetAt,
        ], $ttl);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key): void
    {
        $this->cache->delete($this->prefixKey($key));
    }

    /**
     * Get the underlying cache adapter.
     *
     * @return CacheInterface
     */
    public function getCache(): CacheInterface
    {
        return $this->cache;
    }

    /**
     * Get the key prefix used for rate limit entries.
     *
     * @return string
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * Apply the prefix to a cache key.
     *
     * @param string $key The raw rate limit key.
     *
     * @return string The prefixed cache key.
     */
    private function prefixKey(string $key): string
    {
        return $this->prefix . $key;
    }
}
