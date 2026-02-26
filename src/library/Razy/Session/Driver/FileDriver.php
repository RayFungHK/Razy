<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Filesystem-based session driver.
 *
 * Stores each session as a serialized file on disk. Supports atomic writes
 * via temporary files, automatic directory creation, and garbage collection
 * based on file modification times.
 *
 * @package Razy
 *
 * @license MIT
 */

namespace Razy\Session\Driver;

use Razy\Contract\SessionDriverInterface;

/**
 * File-based session storage driver.
 *
 * Each session is stored as a single file named `{prefix}{id}` in the
 * configured save directory. Data is serialized with PHP's native
 * `serialize()`/`unserialize()` functions.
 *
 * @package Razy\Session\Driver
 */
class FileDriver implements SessionDriverInterface
{
    /** @var string Default file prefix for session files */
    private const DEFAULT_PREFIX = 'sess_';

    /** @var string The directory where session files are stored */
    private readonly string $savePath;

    /** @var string Filename prefix for session files */
    private readonly string $prefix;

    /** @var int Last GC deletion count (for testing) */
    private int $lastGcCount = 0;

    /**
     * FileDriver constructor.
     *
     * @param string $savePath Directory path for session file storage
     * @param string $prefix Filename prefix (default: 'sess_')
     */
    public function __construct(string $savePath, string $prefix = self::DEFAULT_PREFIX)
    {
        $this->savePath = \rtrim($savePath, '/\\');
        $this->prefix = $prefix;
    }

    /**
     * {@inheritdoc}
     *
     * Ensures the save directory exists and is writable.
     */
    public function open(): bool
    {
        if (!\is_dir($this->savePath)) {
            if (!\mkdir($this->savePath, 0o700, true)) {
                return false;
            }
        }

        return \is_writable($this->savePath);
    }

    /**
     * {@inheritdoc}
     */
    public function close(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     *
     * Returns an empty array if the session file does not exist or is unreadable.
     */
    public function read(string $id): array
    {
        $file = $this->getFilePath($id);

        if (!\is_file($file)) {
            return [];
        }

        $contents = \file_get_contents($file);

        if ($contents === false || $contents === '') {
            return [];
        }

        $data = @\unserialize($contents);

        return \is_array($data) ? $data : [];
    }

    /**
     * {@inheritdoc}
     *
     * Uses atomic write: writes to a temporary file first, then renames
     * to the target path to prevent data corruption on crashes.
     */
    public function write(string $id, array $data): bool
    {
        $file = $this->getFilePath($id);
        $tmpFile = $file . '.tmp.' . \bin2hex(\random_bytes(4));

        $serialized = \serialize($data);

        if (\file_put_contents($tmpFile, $serialized, LOCK_EX) === false) {
            return false;
        }

        // Atomic rename (on the same filesystem)
        if (!\rename($tmpFile, $file)) {
            @\unlink($tmpFile);

            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function destroy(string $id): bool
    {
        $file = $this->getFilePath($id);

        if (\is_file($file)) {
            return \unlink($file);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     *
     * Scans the save directory for session files older than `$maxLifetime`
     * seconds and deletes them.
     */
    public function gc(int $maxLifetime): int
    {
        $count = 0;
        $cutoff = \time() - $maxLifetime;
        $prefixLen = \strlen($this->prefix);

        $files = @\scandir($this->savePath);

        if ($files === false) {
            $this->lastGcCount = 0;

            return 0;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            // Only process files with the correct prefix
            if (\substr($file, 0, $prefixLen) !== $this->prefix) {
                continue;
            }

            $fullPath = $this->savePath . DIRECTORY_SEPARATOR . $file;

            if (!\is_file($fullPath)) {
                continue;
            }

            $mtime = \filemtime($fullPath);

            if ($mtime !== false && $mtime < $cutoff) {
                if (\unlink($fullPath)) {
                    ++$count;
                }
            }
        }

        $this->lastGcCount = $count;

        return $count;
    }

    // ── Accessors ─────────────────────────────────────────────

    /**
     * Get the configured save path.
     */
    public function getSavePath(): string
    {
        return $this->savePath;
    }

    /**
     * Get the filename prefix.
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * Get the last GC deletion count (for testing).
     */
    public function getLastGcCount(): int
    {
        return $this->lastGcCount;
    }

    // ── Internal ──────────────────────────────────────────────

    /**
     * Build the full file path for a session ID.
     *
     * @param string $id Session ID
     *
     * @return string Absolute file path
     */
    private function getFilePath(string $id): string
    {
        return $this->savePath . DIRECTORY_SEPARATOR . $this->prefix . $id;
    }
}
