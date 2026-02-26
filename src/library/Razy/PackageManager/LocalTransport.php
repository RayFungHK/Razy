<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Local filesystem transport for fetching and downloading packages
 * from a directory that follows the Composer repository structure.
 * Useful for air-gapped environments, CI systems, or local mirrors.
 *
 * @package Razy
 *
 * @license MIT
 */

namespace Razy\PackageManager;

use Closure;
use Exception;
use Razy\Contract\PackageTransportInterface;

/**
 * Local filesystem package transport.
 *
 * Reads package metadata and distribution archives from a local
 * directory (or mounted network drive) that follows the standard
 * Composer repository layout.
 *
 * Expected directory structure:
 *   /path/to/repo/p2/vendor/package.json     ← metadata
 *   /path/to/repo/dist/vendor/package/x.y.z/ ← distribution archives
 *
 * @class LocalTransport
 */
class LocalTransport implements PackageTransportInterface
{
    /**
     * @param string $basePath Absolute path to the local repository root
     */
    public function __construct(
        private readonly string $basePath,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function fetchMetadata(string $packageName): ?array
    {
        $packageName = \strtolower($packageName);
        $metadataPath = $this->basePath . DIRECTORY_SEPARATOR
            . 'p2' . DIRECTORY_SEPARATOR
            . \str_replace('/', DIRECTORY_SEPARATOR, $packageName) . '.json';

        if (!\is_file($metadataPath)) {
            return null;
        }

        try {
            $content = \file_get_contents($metadataPath);
            if (false === $content) {
                return null;
            }

            $data = \json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            return \is_array($data) ? $data : null;
        } catch (Exception) {
            return null;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function download(string $url, string $destinationPath, ?Closure $progressCallback = null): bool
    {
        // Resolve the source — may be an absolute path or relative to the repo root
        $sourcePath = $this->resolveSourcePath($url);

        if (!\is_file($sourcePath)) {
            return false;
        }

        $result = \copy($sourcePath, $destinationPath);

        if ($result && null !== $progressCallback) {
            $size = \filesize($destinationPath) ?: 0;
            $progressCallback($size, $size);
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function getScheme(): string
    {
        return 'file';
    }

    /**
     * Resolve a distribution path relative to the repo root.
     *
     * @param string $url
     *
     * @return string
     */
    private function resolveSourcePath(string $url): string
    {
        // file:// URI — extract path
        if (\str_starts_with($url, 'file://')) {
            return \substr($url, 7);
        }

        // Already an absolute path
        if (\is_file($url)) {
            return $url;
        }

        // Treat as relative to the repo root
        return $this->basePath . DIRECTORY_SEPARATOR . \ltrim($url, '/\\');
    }
}
