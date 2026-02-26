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
 * @package Razy
 * @license MIT
 */

namespace Razy\PackageManager;

use Closure;
use Exception;
use Razy\Contract\PackageTransportInterface;
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
        private readonly string $baseUrl = 'https://repo.packagist.org'
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function fetchMetadata(string $packageName): ?array
    {
        $packageName = strtolower($packageName);
        $url = PathUtil::append($this->baseUrl, 'p2', $packageName . '.json');

        $context = stream_context_create([
            'http' => [
                'header' => "User-Agent: Razy-Package-Manager\r\n",
                'timeout' => 30,
            ],
        ]);

        $content = @file_get_contents($url, false, $context);
        if (false === $content) {
            return null;
        }

        // Verify HTTP 200 OK
        if (isset($http_response_header) && !$this->isResponseOk($http_response_header)) {
            return null;
        }

        try {
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
        $targetFile = fopen($destinationPath, 'w');
        if (false === $targetFile) {
            return false;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_FILE, $targetFile);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: Razy-Package-Manager',
            'Accept-Encoding: gzip, deflate',
        ]);

        if (null !== $progressCallback) {
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function ($resource, $downloadSize, $downloaded) use ($progressCallback) {
                if ($downloadSize > 0) {
                    $progressCallback($downloadSize, $downloaded);
                }
            });
        }

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($targetFile);

        return false !== $result && $httpCode >= 200 && $httpCode < 400;
    }

    /**
     * {@inheritDoc}
     */
    public function getScheme(): string
    {
        return 'https';
    }

    /**
     * Check if the HTTP response header indicates success (2xx).
     *
     * @param array $headers The $http_response_header array
     *
     * @return bool
     */
    private function isResponseOk(array $headers): bool
    {
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/[\d.]+\s+(\d+)/', $header, $matches)) {
                $code = (int) $matches[1];

                return $code >= 200 && $code < 300;
            }
        }

        return false;
    }
}
