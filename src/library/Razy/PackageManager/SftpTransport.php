<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * SFTP (SSH) transport for fetching and downloading packages from
 * an SFTP mirror that follows the Composer repository structure.
 *
 * Requires PHP ext-ssh2.
 *
 *
 * @license MIT
 */

namespace Razy\PackageManager;

use Closure;
use Exception;
use Razy\Contract\PackageTransportInterface;
use Razy\SFTPClient;

/**
 * SFTP (SSH2) package transport.
 *
 * Connects to an SFTP server that mirrors the Composer repository layout.
 * Supports password authentication and public-key authentication.
 *
 * Expected mirror structure:
 *   /p2/vendor/package.json     ← metadata (same format as Packagist)
 *   /dist/vendor/package/x.y.z/ ← distribution zip archives
 *
 * @class SftpTransport
 */
class SftpTransport implements PackageTransportInterface
{
    private ?SFTPClient $client = null;

    /**
     * @param string $host SFTP hostname
     * @param string $username SSH username
     * @param string $password Password (for password auth) or passphrase (for key auth)
     * @param int $port SSH port (default 22)
     * @param string $basePath Root path on the server where the repo structure lives
     * @param string|null $privateKey Path to the private key file (for key-based auth)
     * @param string|null $publicKey Path to the public key file (for key-based auth)
     */
    public function __construct(
        private readonly string $host,
        private readonly string $username,
        private readonly string $password = '',
        private readonly int $port = 22,
        private readonly string $basePath = '/',
        private readonly ?string $privateKey = null,
        private readonly ?string $publicKey = null,
    ) {
    }

    /**
     * Disconnect the SFTP client when the transport is destroyed.
     */
    public function __destruct()
    {
        if (null !== $this->client) {
            $this->client->disconnect();
        }
    }

    /**
     * {@inheritDoc}
     */
    public function fetchMetadata(string $packageName): ?array
    {
        $client = $this->getClient();
        if (null === $client) {
            return null;
        }

        $packageName = \strtolower($packageName);
        $remotePath = \rtrim($this->basePath, '/') . '/p2/' . $packageName . '.json';

        try {
            $content = $client->downloadString($remotePath);
            if (false === $content || '' === $content) {
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
        $client = $this->getClient();
        if (null === $client) {
            return false;
        }

        $remotePath = $this->resolveRemotePath($url);

        try {
            $client->download($remotePath, $destinationPath);

            // Report completion via progress callback (SFTP does not support incremental progress)
            if (null !== $progressCallback) {
                $size = \filesize($destinationPath) ?: 0;
                $progressCallback($size, $size);
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
        return 'sftp';
    }

    /**
     * Lazily connect and authenticate the SFTP client.
     *
     * @return SFTPClient|null
     */
    private function getClient(): ?SFTPClient
    {
        if (null !== $this->client) {
            return $this->client;
        }

        try {
            $this->client = new SFTPClient($this->host, $this->port);

            // Use key-based auth if a private key is provided, otherwise password auth
            if (null !== $this->privateKey) {
                $this->client->loginWithKey($this->username, $this->publicKey ?? '', $this->privateKey, $this->password);
            } else {
                $this->client->loginWithPassword($this->username, $this->password);
            }

            return $this->client;
        } catch (Exception) {
            $this->client = null;

            return null;
        }
    }

    /**
     * Extract the file path from a URL or return it as-is if already a path.
     *
     * @param string $url
     *
     * @return string
     */
    private function resolveRemotePath(string $url): string
    {
        $parsed = \parse_url($url);
        if (isset($parsed['path'])) {
            return $parsed['path'];
        }

        return $url;
    }
}
