<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * SMB/CIFS transport for fetching and downloading packages from
 * a Windows network share (or Samba) that follows the Composer
 * repository structure.
 *
 * Uses PHP stream wrappers (smb://) with smbclient integration,
 * or falls back to UNC path access on Windows.
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
 * SMB/CIFS package transport.
 *
 * Accesses a network share hosting the Composer repository layout
 * via UNC paths (Windows) or the smb:// stream wrapper.
 *
 * Expected mirror structure on the share:
 *   \\server\share\p2\vendor\package.json     ← metadata
 *   \\server\share\dist\vendor\package\x.y.z\ ← distribution archives
 *
 * @class SmbTransport
 */
class SmbTransport implements PackageTransportInterface
{
    /**
     * @param string $sharePath Root UNC path or smb:// URL (e.g. '\\\\server\\share' or 'smb://server/share')
     * @param string|null $username SMB username (null for guest/anonymous)
     * @param string|null $password SMB password
     * @param string|null $domain Windows domain / workgroup
     */
    public function __construct(
        private readonly string $sharePath,
        private readonly ?string $username = null,
        private readonly ?string $password = null,
        private readonly ?string $domain = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function fetchMetadata(string $packageName): ?array
    {
        $packageName = \strtolower($packageName);
        $metadataPath = $this->buildPath('p2', \str_replace('/', DIRECTORY_SEPARATOR, $packageName) . '.json');

        try {
            $content = $this->readFile($metadataPath);
            if (null === $content) {
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
        // Resolve the source path — may be:
        //   - An absolute UNC path (\\server\share\dist\...)
        //   - A relative path within the share
        //   - An smb:// URI
        $sourcePath = $this->resolveSourcePath($url);

        try {
            $context = $this->createStreamContext();
            $content = @\file_get_contents($sourcePath, false, $context);
            if (false === $content) {
                return false;
            }

            $written = \file_put_contents($destinationPath, $content);
            if (false === $written) {
                return false;
            }

            // Report completion
            if (null !== $progressCallback) {
                $progressCallback($written, $written);
            }

            return true;
        } catch (Exception) {
            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getScheme(): string
    {
        return 'smb';
    }

    /**
     * Build a full path under the share root.
     *
     * @param string ...$segments Path segments to append
     *
     * @return string
     */
    private function buildPath(string ...$segments): string
    {
        $root = \rtrim($this->sharePath, '/\\');

        return $root . DIRECTORY_SEPARATOR . \implode(DIRECTORY_SEPARATOR, $segments);
    }

    /**
     * Read a file from the share, supporting both UNC and smb:// paths.
     *
     * @param string $path
     *
     * @return string|null File contents or null on failure
     */
    private function readFile(string $path): ?string
    {
        $context = $this->createStreamContext();
        $content = @\file_get_contents($path, false, $context);

        return false === $content ? null : $content;
    }

    /**
     * Resolve a distribution URL to a local share path.
     *
     * @param string $url
     *
     * @return string
     */
    private function resolveSourcePath(string $url): string
    {
        // If the URL starts with the share root already, use as-is
        if (\str_starts_with($url, $this->sharePath)) {
            return $url;
        }

        // If it's a relative path, prepend the share root
        if (!\str_starts_with($url, '\\\\') && !\str_starts_with($url, 'smb://')) {
            return $this->buildPath(\ltrim($url, '/\\'));
        }

        return $url;
    }

    /**
     * Create a stream context with SMB credentials if provided.
     *
     * @return resource|null
     */
    private function createStreamContext()
    {
        $options = [];

        // SMB stream wrapper options (when using smbclient or libsmbclient)
        if (null !== $this->username || null !== $this->password) {
            $options['smb'] = [
                'username' => $this->username ?? '',
                'password' => $this->password ?? '',
            ];
            if (null !== $this->domain) {
                $options['smb']['domain'] = $this->domain;
            }
        }

        return empty($options) ? null : \stream_context_create($options);
    }
}
