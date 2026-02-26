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
 * @license MIT
 */

namespace Razy\Contract;

use Closure;

/**
 * Transport interface for fetching and downloading packages.
 *
 * Implementations provide protocol-specific logic (HTTP, FTP, SFTP, SMB,
 * local filesystem, etc.) while PackageManager stays transport-agnostic.
 */
interface PackageTransportInterface
{
    /**
     * Fetch remote package metadata (JSON) for a given package name.
     *
     * The transport must locate and retrieve the metadata file that
     * matches the expected repository structure for the given package.
     *
     * @param string $packageName Composer-style package name (vendor/package)
     *
     * @return array{packages: array}|null Decoded JSON metadata, or null on failure
     */
    public function fetchMetadata(string $packageName): ?array;

    /**
     * Download a distribution archive to a local temporary file.
     *
     * @param string       $url              The distribution URL or path from package metadata
     * @param string       $destinationPath  Absolute path to write the downloaded file
     * @param Closure|null $progressCallback Optional callback: fn(int $downloadSize, int $downloaded) => void
     *
     * @return bool True on success, false on failure
     */
    public function download(string $url, string $destinationPath, ?Closure $progressCallback = null): bool;

    /**
     * Return the transport scheme identifier (e.g. 'https', 'ftp', 'sftp', 'smb', 'file').
     *
     * @return string
     */
    public function getScheme(): string;
}
