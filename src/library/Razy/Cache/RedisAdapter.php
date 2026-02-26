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

namespace Razy\Cache;

use DateInterval;
use DateTime;
use Redis;

/**
 * Redis-backed PSR-16 cache adapter.
 *
 * Usage:
 * ```php
 * $redis = new \Redis();
 * $redis->connect('127.0.0.1', 6379);
 *
 * $cache = new RedisAdapter($redis, 'myapp:');
 *
 * $cache->set('user:42', ['name' => 'Alice'], 3600);
 * $user = $cache->get('user:42');
 * ```
 *
 * @package Razy\Cache
 */
class RedisAdapter implements CacheInterface
{
    /**
     * Redis connection instance.
     */
    private Redis $redis;

    /**
     * Key prefix applied to all cache keys.
     */
    private string $prefix;

    /**
     * Create a new RedisAdapter.
     *
     * @param Redis $redis Connected Redis instance
     * @param string $prefix Key prefix (default: 'razy_')
     *
     * @throws InvalidArgumentException If Redis is not connected
     */
    public function __construct(Redis $redis, string $prefix = 'razy_')
    {
        $this->redis = $redis;
        $this->prefix = $prefix;
    }

    /**
     * Get the Redis instance.
     */
    public function getRedis(): Redis
    {
        return $this->redis;
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);

        $value = $this->redis->get($this->prefix . $key);

        if ($value === false) {
            return $default;
        }

        return $this->unserializeValue($value);
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->validateKey($key);

        $seconds = $this->ttlToSeconds($ttl);

        if ($seconds !== null && $seconds <= 0) {
            return $this->delete($key);
        }

        $serialized = $this->serializeValue($value);
        $prefixedKey = $this->prefix . $key;

        if ($seconds !== null) {
            return $this->redis->setex($prefixedKey, $seconds, $serialized);
        }

        return $this->redis->set($prefixedKey, $serialized);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key): bool
    {
        $this->validateKey($key);
        $this->redis->del($this->prefix . $key);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): bool
    {
        if ($this->prefix === '') {
            $this->redis->flushDB();

            return true;
        }

        // Delete only keys with our prefix
        $iterator = null;
        $pattern = $this->prefix . '*';

        while ($keys = $this->redis->scan($iterator, $pattern, 100)) {
            $this->redis->del(...$keys);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $keyList = [];
        foreach ($keys as $key) {
            $this->validateKey($key);
            $keyList[] = $key;
        }

        if (empty($keyList)) {
            return [];
        }

        $prefixedKeys = \array_map(fn (string $k) => $this->prefix . $k, $keyList);
        $values = $this->redis->mget($prefixedKeys);

        $result = [];
        foreach ($keyList as $i => $key) {
            $result[$key] = $values[$i] === false
                ? $default
                : $this->unserializeValue($values[$i]);
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $seconds = $this->ttlToSeconds($ttl);

        if ($seconds !== null && $seconds <= 0) {
            $keys = [];
            foreach ($values as $key => $value) {
                $keys[] = $key;
            }

            return $this->deleteMultiple($keys);
        }

        $success = true;

        foreach ($values as $key => $value) {
            if (!$this->set((string) $key, $value, $ttl)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->validateKey($key);
            $this->redis->del($this->prefix . $key);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function has(string $key): bool
    {
        $this->validateKey($key);

        return (bool) $this->redis->exists($this->prefix . $key);
    }

    // ═══════════════════════════════════════════════════════════════
    // Internal helpers
    // ═══════════════════════════════════════════════════════════════

    /**
     * Convert TTL to integer seconds.
     *
     * @param int|DateInterval|null $ttl
     *
     * @return int|null Seconds, or null for no expiry
     */
    private function ttlToSeconds(null|int|DateInterval $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }

        if ($ttl instanceof DateInterval) {
            $now = new DateTime();
            $future = (clone $now)->add($ttl);

            return $future->getTimestamp() - $now->getTimestamp();
        }

        return $ttl;
    }

    /**
     * Validate a cache key per PSR-16 rules.
     *
     * @param string $key
     *
     * @throws InvalidArgumentException
     */
    private function validateKey(string $key): void
    {
        if ($key === '') {
            throw new InvalidArgumentException('Cache key must not be empty.');
        }

        if (\preg_match('/[{}()\/\\\\@:]/', $key)) {
            throw new InvalidArgumentException(
                "Cache key '{$key}' contains reserved characters: {}()/\\@:",
            );
        }
    }

    /**
     * Serialize a value for Redis storage.
     *
     * @param mixed $value
     *
     * @return string
     */
    private function serializeValue(mixed $value): string
    {
        return \serialize($value);
    }

    /**
     * Unserialize a value from Redis storage.
     *
     * @param string $value
     *
     * @return mixed
     */
    private function unserializeValue(string $value): mixed
    {
        return \unserialize($value);
    }
}
