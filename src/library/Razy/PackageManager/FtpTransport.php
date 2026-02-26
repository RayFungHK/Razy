<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * FTP/FTPS transport for fetching and downloading packages from
 * an FTP mirror that follows the Composer repository structure.
 *
 * Requires PHP ext-ftp.
 *
 * @package Razy
 * @license MIT
 */

namespace Razy\PackageManager;

use Closure;
use Exception;
use Razy\Contract\PackageTransportInterface;
use Razy\FTPClient;

/**
 * FTP/FTPS package transport.
 *
 * Connects to an FTP server that mirrors the Composer repository layout
 * (`/p2/{vendor}/{package}.json` for metadata, distribution zips at their
 * declared paths). Supports both plain FTP and FTPS (TLS).
 *
 * Expected mirror structure:
 *   /p2/vendor/package.json     ← metadata (same format as Packagist)
 *   /dist/vendor/package/x.y.z/ ← distribution zip archives
 *
 * @class FtpTransport
 */
class FtpTransport implements PackageTransportInterface
{
    private ?FTPClient $client = null;

    /**
     * @param string      $host     FTP hostname
     * @param string      $username FTP username
     * @param string      $password FTP password
     * @param int         $port     FTP port (default 21)
     * @param bool        $secure   Use FTPS (TLS) connection
     * @param string      $basePath Root path on the FTP server where the repo structure lives
     * @param bool        $passive  Use passive mode (default true)
     */
    public function __construct(
        private readonly string $host,
        private readonly string $username,
        private readonly string $password,
        private readonly int $port = 21,
        private readonly bool $secure = false,
        private readonly string $basePath = '/',
        private readonly bool $passive = true,
    ) {
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

        $packageName = strtolower($packageName);
        $remotePath = rtrim($this->basePath, '/') . '/p2/' . $packageName . '.json';

        try {
            $content = $client->downloadString($remotePath);
            if (false === $content || '' === $content) {
                return null;
            }

            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            return is_array($data) ? $data : null;
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

        // The $url from package metadata may be:
        //   - An absolute FTP path on this server
        //   - A full ftp:// URI (extract the path component)
        $remotePath = $this->resolveRemotePath($url);

        try {
            $client->download($remotePath, $destinationPath);

            // Report completion via progress callback
            if (null !== $progressCallback) {
                $size = $client->size($remotePath);
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
        return $this->secure ? 'ftps' : 'ftp';
    }

    /**
     * Disconnect the FTP client when the transport is destroyed.
     */
    public function __destruct()
    {
        if (null !== $this->client && $this->client->isConnected()) {
            $this->client->disconnect();
        }
    }

    /**
     * Lazily connect and authenticate the FTP client.
     *
     * @return FTPClient|null
     */
    private function getClient(): ?FTPClient
    {
        if (null !== $this->client && $this->client->isConnected()) {
            return $this->client;
        }

        try {
            $this->client = new FTPClient($this->host, $this->port, $this->secure);
            $this->client->setPassive($this->passive);
            $this->client->login($this->username, $this->password);

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
        // If it looks like a full ftp:// URI, extract the path
        $parsed = parse_url($url);
        if (isset($parsed['path'])) {
            return $parsed['path'];
        }

        return $url;
    }
}
