<?php

/**
 * This file is part of Razy v0.5.
 *
 * File-based cache adapter implementing the PSR-16 CacheInterface.
 * Stores each cache entry as a serialized file with TTL metadata,
 * using directory sharding (first 2 chars of hash) for performance.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * @package Razy
 *
 * @license MIT
 */

namespace Razy\Cache;

use DateInterval;
use DateTime;
use DirectoryIterator;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * File-based cache adapter for the Razy framework.
 *
 * Each cache entry is stored as a serialized file in a sharded directory
 * structure. File format stores an expiry timestamp and the serialized
 * data payload. Expired entries are cleaned up on access (lazy purge).
 *
 * Directory structure:
 *   {cacheDir}/{xx}/{hash}.cache
 *
 * Where {xx} is the first two characters of the MD5 hash for directory sharding.
 *
 * @class FileAdapter
 */
class FileAdapter implements CacheInterface
{
    /** @var string The root cache directory path */
    private string $directory;

    /**
     * FileAdapter constructor.
     *
     * @param string $directory The root directory for cache file storage.
     *                          Will be created if it does not exist.
     *
     * @throws InvalidArgumentException If the directory cannot be created or is not writable.
     */
    public function __construct(string $directory)
    {
        $this->directory = \rtrim($directory, '/\\');

        if (!\is_dir($this->directory)) {
            if (!@\mkdir($this->directory, 0o775, true) && !\is_dir($this->directory)) {
                throw new InvalidArgumentException("Cache directory '{$this->directory}' could not be created.");
            }
        }

        if (!\is_writable($this->directory)) {
            throw new InvalidArgumentException("Cache directory '{$this->directory}' is not writable.");
        }
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);

        $path = $this->getFilePath($key);

        if (!\is_file($path)) {
            return $default;
        }

        $data = $this->readFile($path);
        if ($data === false) {
            return $default;
        }

        // Check expiry: 0 means no expiry
        if ($data['e'] > 0 && $data['e'] < \time()) {
            @\unlink($path);
            return $default;
        }

        return $data['d'];
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->validateKey($key);

        $expiry = $this->calculateExpiry($ttl);

        // A TTL of 0 or negative means the item should be deleted
        if ($ttl !== null && $expiry <= \time() && $expiry > 0) {
            return $this->delete($key);
        }

        $path = $this->getFilePath($key);
        $dir = \dirname($path);

        if (!\is_dir($dir)) {
            if (!@\mkdir($dir, 0o775, true) && !\is_dir($dir)) {
                return false;
            }
        }

        $data = \serialize(['e' => $expiry, 'd' => $value]);

        // Write atomically: write to temp file, then rename
        $tmp = $path . '.' . \uniqid('', true) . '.tmp';
        if (@\file_put_contents($tmp, $data, LOCK_EX) === false) {
            @\unlink($tmp);
            return false;
        }

        // On Windows, rename() fails if destination exists — remove first
        if (PHP_OS_FAMILY === 'Windows' && \is_file($path)) {
            @\unlink($path);
        }

        if (!@\rename($tmp, $path)) {
            @\unlink($tmp);
            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key): bool
    {
        $this->validateKey($key);

        $path = $this->getFilePath($key);

        if (\is_file($path)) {
            return @\unlink($path);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): bool
    {
        return $this->deleteDirectory($this->directory);
    }

    /**
     * {@inheritdoc}
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $success = true;
        foreach ($values as $key => $value) {
            if (!$this->set($key, $value, $ttl)) {
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
        $success = true;
        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * {@inheritdoc}
     */
    public function has(string $key): bool
    {
        $this->validateKey($key);

        $path = $this->getFilePath($key);

        if (!\is_file($path)) {
            return false;
        }

        $data = $this->readFile($path);
        if ($data === false) {
            return false;
        }

        if ($data['e'] > 0 && $data['e'] < \time()) {
            @\unlink($path);
            return false;
        }

        return true;
    }

    /**
     * Get cache statistics.
     *
     * @return array{directory: string, files: int, size: int} Cache statistics
     */
    public function getStats(): array
    {
        $files = 0;
        $size = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'cache') {
                $files++;
                $size += $file->getSize();
            }
        }

        return [
            'directory' => $this->directory,
            'files' => $files,
            'size' => $size,
        ];
    }

    /**
     * Remove expired cache entries (garbage collection).
     *
     * @return int Number of expired entries removed
     */
    public function gc(): int
    {
        $removed = 0;
        $now = \time();

        if (!\is_dir($this->directory)) {
            return 0;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'cache') {
                $data = $this->readFile($file->getPathname());
                if ($data !== false && $data['e'] > 0 && $data['e'] < $now) {
                    @\unlink($file->getPathname());
                    $removed++;
                }
            }
        }

        // Clean up empty shard directories
        foreach (new DirectoryIterator($this->directory) as $dir) {
            if ($dir->isDot() || !$dir->isDir()) {
                continue;
            }

            $isEmpty = true;
            foreach (new DirectoryIterator($dir->getPathname()) as $child) {
                if (!$child->isDot()) {
                    $isEmpty = false;
                    break;
                }
            }

            if ($isEmpty) {
                @\rmdir($dir->getPathname());
            }
        }

        return $removed;
    }

    /**
     * Generate the file path for a cache key.
     *
     * @param string $key The cache key
     *
     * @return string The full file path
     */
    private function getFilePath(string $key): string
    {
        $hash = \md5($key);
        $shard = \substr($hash, 0, 2);

        return $this->directory . DIRECTORY_SEPARATOR . $shard . DIRECTORY_SEPARATOR . $hash . '.cache';
    }

    /**
     * Read and unserialize a cache file.
     *
     * @param string $path The file path
     *
     * @return array{e: int, d: mixed}|false The cache data or false on failure
     */
    private function readFile(string $path): array|false
    {
        $content = @\file_get_contents($path);
        if ($content === false) {
            return false;
        }

        $data = @\unserialize($content);
        if (!\is_array($data) || !\array_key_exists('e', $data) || !\array_key_exists('d', $data)) {
            // Corrupted cache file — remove it
            @\unlink($path);
            return false;
        }

        return $data;
    }

    /**
     * Calculate the expiry timestamp from a TTL value.
     *
     * @param int|DateInterval|null $ttl The TTL value
     *
     * @return int Unix timestamp of expiry, or 0 for no expiry
     */
    private function calculateExpiry(null|int|DateInterval $ttl): int
    {
        if ($ttl === null) {
            return 0; // No expiry
        }

        if ($ttl instanceof DateInterval) {
            $now = new DateTime();
            $future = (clone $now)->add($ttl);
            return $future->getTimestamp();
        }

        if ($ttl <= 0) {
            return \time(); // Already expired
        }

        return \time() + $ttl;
    }

    /**
     * Validate a cache key.
     *
     * @param string $key The cache key to validate
     *
     * @throws InvalidArgumentException If the key is invalid
     */
    private function validateKey(string $key): void
    {
        if ($key === '') {
            throw new InvalidArgumentException('Cache key must not be empty.');
        }

        // PSR-16 reserved characters: {}()/\@:
        if (\preg_match('/[{}()\/\\\\@:]/', $key)) {
            throw new InvalidArgumentException("Cache key '{$key}' contains reserved characters: {}()/\\@:");
        }
    }

    /**
     * Recursively delete all files in a directory while preserving the root directory.
     *
     * @param string $directory The directory to clear
     *
     * @return bool True on success
     */
    private function deleteDirectory(string $directory): bool
    {
        if (!\is_dir($directory)) {
            return true;
        }

        foreach (\scandir($directory) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;

            if (\is_dir($path)) {
                $this->deleteDirectory($path);
                @\rmdir($path);
            } else {
                @\unlink($path);
            }
        }

        return true;
    }
}
