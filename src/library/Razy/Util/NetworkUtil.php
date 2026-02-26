<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Extracted from bootstrap.inc.php (Phase 2.5).
 * Provides static utility methods for network-related operations.
 *
 * @package Razy
 *
 * @license MIT
 */

namespace Razy\Util;

/**
 * Network-related utilities.
 *
 * Provides methods for SSL detection, IP address retrieval,
 * IP range checking, and FQDN validation/formatting.
 */
class NetworkUtil
{
    /**
     * Check if the string is a valid FQDN.
     *
     * @param string $domain The FQDN string to be checked
     * @param bool $withPort Whether to allow an optional port suffix
     *
     * @return bool Return TRUE if the string is a FQDN
     */
    public static function isFqdn(string $domain, bool $withPort = false): bool
    {
        return 1 === \preg_match('/^(?:(?:(?:[a-z\d[\w\-*]*(?<![-_]))\.)*[a-z*]{2,}|((?:2[0-4]|1\d|[1-9])?\d|25[0-5])(?:\.(?-1)){3})' . ($withPort ? '(?::\d+)?' : '') . '$/', $domain);
    }

    /**
     * Format the FQDN string, trim whitespace and remove any dot (.) at the beginning of the string.
     *
     * @param string $domain The FQDN string to be formatted
     *
     * @return string The formatted FQDN string
     */
    public static function formatFqdn(string $domain): string
    {
        return \trim(\ltrim($domain, '.'));
    }

    /**
     * Check if SSL is used.
     *
     * @return bool True if the connection is using SSL/HTTPS
     */
    public static function isSsl(): bool
    {
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO']) {
            return true;
        }
        if (!empty($_SERVER['HTTPS']) && 'off' !== $_SERVER['HTTPS'] || 443 === $_SERVER['SERVER_PORT']) {
            return true;
        }

        return false;
    }

    /**
     * Get the visitor IP.
     *
     * @return string The IP address
     */
    public static function getIP(): string
    {
        if (isset($_SERVER['HTTP_CLIENT_IP'])) {
            $ipaddress = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ipaddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED'])) {
            $ipaddress = $_SERVER['HTTP_X_FORWARDED'];
        } elseif (isset($_SERVER['HTTP_FORWARDED_FOR'])) {
            $ipaddress = $_SERVER['HTTP_FORWARDED_FOR'];
        } elseif (isset($_SERVER['HTTP_FORWARDED'])) {
            $ipaddress = $_SERVER['HTTP_FORWARDED'];
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $ipaddress = $_SERVER['REMOTE_ADDR'];
        } else {
            $ipaddress = 'UNKNOWN';
        }

        return $ipaddress;
    }

    /**
     * Check if the IP is in the CIDR range.
     *
     * @param string $ip The IP address to check
     * @param string $cidr The CIDR notation range (e.g. '192.168.1.0/24')
     *
     * @return bool True if the IP is within the CIDR range
     */
    public static function ipInRange(string $ip, string $cidr): bool
    {
        if (!\preg_match('/^(([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.){3}([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])$/', $ip)) {
            return false;
        }

        if (!\preg_match('/^(([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.){3}([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])(\/([0-9]|[1-2][0-9]|3[0-2]))?$/', $cidr)) {
            return false;
        }

        if (!\str_contains($cidr, '/')) {
            $cidr .= '/32';
        }

        [$range, $netmask] = \explode('/', $cidr, 2);
        $rangeDecimal = \ip2long($range);
        $ipDecimal = \ip2long($ip);
        $wildcardDecimal = \pow(2, (32 - (int) $netmask)) - 1;
        $netmaskDecimal = ~$wildcardDecimal;
        return (($ipDecimal & $netmaskDecimal) === ($rangeDecimal & $netmaskDecimal));
    }
}
