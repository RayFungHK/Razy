<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * HTTP/HTTPS transport for fetching and downloading packages from
 * Packagist-compatible repositories, GitHub releases, or any HTTP
 * mirror that follows the Composer repository structure.
 *
 *
 * @license MIT
 */

namespace Razy\PackageManager;

use Closure;
use Exception;
use Razy\Contract\PackageTransportInterface;
use Razy\Http\HttpClient;
use Razy\Http\HttpTransportException;
use Razy\Util\PathUtil;

/**
 * HTTP/HTTPS package transport.
 *
 * Fetches metadata from a Packagist-compatible JSON endpoint and downloads
 * distribution archives via cURL. Works with Packagist, GitHub releases,
 * Satis, Toran Proxy, Private Packagist, or any HTTP mirror exposing the
 * standard `/p2/{vendor}/{package}.json` layout.
 *
 * @class HttpTransport
 */
class HttpTransport implements PackageTransportInterface
{
    /**
     * @param string $baseUrl Root URL of the Composer repository (e.g. 'https://repo.packagist.org')
     */
    public function __construct(
        private readonly string $baseUrl = 'https://repo.packagist.org',
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function fetchMetadata(string $packageName): ?array
    {
        $packageName = \strtolower($packageName);
        $url = PathUtil::append($this->baseUrl, 'p2', $packageName . '.json');

        // S1 migration: off the old stream-context reader onto the hardened
        // client (timeouts + HTTPS gate + one door). Disclosed diffs: a
        // redirect-chain that ends in 200 now succeeds (the old reader read
        // the FIRST status line and gave up on 3xx); the 30s connect budget
        // becomes the client's own (30s transfer / 10s connect).
        try {
            $response = HttpClient::create()
                ->userAgent('Razy-Package-Manager')
                ->get($url);
        } catch (HttpTransportException) {
            return null;
        }

        if (!$response->successful()) {
            return null;
        }

        try {
            $data = \json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);

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
        // S1 migration: same streaming sink + byte-progress contract as the
        // hand-rolled cURL it replaces (fopen-before-transfer included: the
        // destination is created even when the transfer then fails).
        $options = ['sink' => $destinationPath];
        if (null !== $progressCallback) {
            $options['progress'] = $progressCallback;
        }

        try {
            $response = HttpClient::create()
                ->userAgent('Razy-Package-Manager')
                ->withHeader('Accept-Encoding', 'gzip, deflate')
                ->send('GET', $url, $options);
        } catch (HttpTransportException) {
            return false;
        }

        return $response->status() >= 200 && $response->status() < 400;
    }

    /**
     * {@inheritDoc}
     */
    public function getScheme(): string
    {
        return 'https';
    }
}
