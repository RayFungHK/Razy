<?php

/**
 * This file is part of Razy v0.5.
 *
 * Cache facade for the Razy framework. Provides a static interface to
 * the underlying PSR-16 cache adapter, with auto-initialization using
 * the file-based adapter by default. Supports adapter swapping for
 * APCu, Redis, or custom implementations.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 *
 * @license MIT
 */

namespace Razy;

use DateInterval;
use Razy\Cache\CacheInterface;
use Razy\Cache\FileAdapter;
use Razy\Cache\InvalidArgumentException;
use Razy\Cache\NullAdapter;
use Throwable;

/**
 * Static cache facade for the Razy framework.
 *
 * Provides a simple static API for caching operations. The facade delegates
 * to a configured PSR-16 adapter (FileAdapter by default). When not initialized,
 * all operations gracefully return defaults (no-op behavior).
 *
 * Usage:
 *   Cache::initialize('/path/to/cache');
 *   Cache::set('my.key', $data, 3600);
 *   $value = Cache::get('my.key');
 *
 * File-based caching with automatic mtime validation:
 *   $value = Cache::getValidated('config.app', '/path/to/app.json');
 *
 * @class Cache
 */
class Cache
{
    /** @var CacheInterface|null The active cache adapter */
    private static ?CacheInterface $adapter = null;

    /** @var bool Whether the cache system has been initialized */
    private static bool $initialized = false;

    /** @var bool Whether caching is enabled */
    private static bool $enabled = true;

    /**
     * Initialize the cache system with a directory path or custom adapter.
     *
     * @param string $cacheDir The root cache directory (used for FileAdapter)
     * @param CacheInterface|null $adapter Optional custom adapter. If null, FileAdapter is used.
     */
    public static function initialize(string $cacheDir = '', ?CacheInterface $adapter = null): void
    {
        if ($adapter !== null) {
            self::$adapter = $adapter;
        } elseif ($cacheDir !== '') {
            try {
                self::$adapter = new FileAdapter($cacheDir);
            } catch (InvalidArgumentException) {
                // Cannot create/write to cache dir — fall back to null adapter
                self::$adapter = new NullAdapter();
            }
        } else {
            self::$adapter = new NullAdapter();
        }

        self::$initialized = true;
    }

    /**
     * Check if the cache system has been initialized.
     *
     * @return bool
     */
    public static function isInitialized(): bool
    {
        return self::$initialized;
    }

    /**
     * Enable or disable the cache system.
     *
     * When disabled, all operations behave as if the NullAdapter is in use.
     *
     * @param bool $enabled
     */
    public static function setEnabled(bool $enabled): void
    {
        self::$enabled = $enabled;
    }

    /**
     * Check if the cache system is enabled.
     *
     * @return bool
     */
    public static function isEnabled(): bool
    {
        return self::$enabled && self::$initialized;
    }

    /**
     * Get the active cache adapter.
     *
     * @return CacheInterface
     */
    public static function getAdapter(): CacheInterface
    {
        if (!self::$initialized) {
            return new NullAdapter();
        }

        return self::$adapter;
    }

    /**
     * Set a custom cache adapter.
     *
     * @param CacheInterface $adapter The adapter to use
     */
    public static function setAdapter(CacheInterface $adapter): void
    {
        self::$adapter = $adapter;
        self::$initialized = true;
    }

    /**
     * Fetches a value from the cache.
     *
     * @param string $key The unique key of this item in the cache.
     * @param mixed $default Default value to return if the key does not exist.
     *
     * @return mixed The value of the item from the cache, or $default in case of cache miss.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (!self::isEnabled()) {
            return $default;
        }

        try {
            return self::$adapter->get($key, $default);
        } catch (Throwable) {
            return $default;
        }
    }

    /**
     * Persists data in the cache.
     *
     * @param string $key The key of the item to store.
     * @param mixed $value The value of the item to store.
     * @param int|DateInterval|null $ttl Optional TTL.
     *
     * @return bool True on success.
     */
    public static function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        if (!self::isEnabled()) {
            return false;
        }

        try {
            return self::$adapter->set($key, $value, $ttl);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Delete an item from the cache.
     *
     * @param string $key The unique cache key.
     *
     * @return bool True on success.
     */
    public static function delete(string $key): bool
    {
        if (!self::isEnabled()) {
            return false;
        }

        try {
            return self::$adapter->delete($key);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Wipe the entire cache.
     *
     * @return bool True on success.
     */
    public static function clear(): bool
    {
        if (!self::$initialized) {
            return false;
        }

        try {
            return self::$adapter->clear();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Determines whether an item is present in the cache.
     *
     * @param string $key The cache item key.
     *
     * @return bool
     */
    public static function has(string $key): bool
    {
        if (!self::isEnabled()) {
            return false;
        }

        try {
            return self::$adapter->has($key);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Obtains multiple cache items.
     *
     * @param iterable<string> $keys A list of keys.
     * @param mixed $default Default value for missing keys.
     *
     * @return iterable<string, mixed> Key => value pairs.
     */
    public static function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        if (!self::isEnabled()) {
            $result = [];
            foreach ($keys as $key) {
                $result[$key] = $default;
            }
            return $result;
        }

        try {
            return self::$adapter->getMultiple($keys, $default);
        } catch (Throwable) {
            $result = [];
            foreach ($keys as $key) {
                $result[$key] = $default;
            }
            return $result;
        }
    }

    /**
     * Persists multiple key => value pairs.
     *
     * @param iterable<string, mixed> $values Key => value pairs.
     * @param int|DateInterval|null $ttl Optional TTL.
     *
     * @return bool True on success.
     */
    public static function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        if (!self::isEnabled()) {
            return false;
        }

        try {
            return self::$adapter->setMultiple($values, $ttl);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Deletes multiple cache items.
     *
     * @param iterable<string> $keys A list of keys.
     *
     * @return bool True on success.
     */
    public static function deleteMultiple(iterable $keys): bool
    {
        if (!self::isEnabled()) {
            return false;
        }

        try {
            return self::$adapter->deleteMultiple($keys);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Get a cached value validated against a file's modification time.
     *
     * This is the primary method for caching file-based data (configs, YAML, templates).
     * The cached entry stores the file's mtime alongside the data. On retrieval,
     * the current file mtime is compared — if the file has been modified since caching,
     * the cache entry is considered stale and the default is returned.
     *
     * @param string $key The cache key
     * @param string $filePath The file path to validate against
     * @param mixed $default Default value if cache miss or stale
     *
     * @return mixed The cached data, or $default if stale/missing
     */
    public static function getValidated(string $key, string $filePath, mixed $default = null): mixed
    {
        if (!self::isEnabled()) {
            return $default;
        }

        try {
            $cached = self::$adapter->get($key);

            if (!\is_array($cached) || !isset($cached['mtime'], $cached['data'])) {
                return $default;
            }

            // Validate file modification time
            $currentMtime = @\filemtime($filePath);
            if ($currentMtime === false || $currentMtime !== $cached['mtime']) {
                // File modified or missing — cache is stale
                self::$adapter->delete($key);
                return $default;
            }

            return $cached['data'];
        } catch (Throwable) {
            return $default;
        }
    }

    /**
     * Store a value with file modification time validation metadata.
     *
     * Pairs with getValidated() to provide automatic cache invalidation
     * when the source file changes.
     *
     * @param string $key The cache key
     * @param string $filePath The file path whose mtime is tracked
     * @param mixed $data The data to cache
     * @param int|DateInterval|null $ttl Optional TTL
     *
     * @return bool True on success
     */
    public static function setValidated(string $key, string $filePath, mixed $data, null|int|DateInterval $ttl = null): bool
    {
        if (!self::isEnabled()) {
            return false;
        }

        $mtime = @\filemtime($filePath);
        if ($mtime === false) {
            return false;
        }

        try {
            return self::$adapter->set($key, [
                'mtime' => $mtime,
                'data' => $data,
            ], $ttl);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Reset the cache system to its uninitialized state.
     * Primarily used for testing.
     */
    public static function reset(): void
    {
        self::$adapter = null;
        self::$initialized = false;
        self::$enabled = true;
    }
}
