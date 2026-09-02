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
     * Left-labels that are never a one-label tenant slug.
     *
     * @var list<string>
     */
    public const RESERVED_LEFT_LABELS = ['www', 'console', 'admin', 'api', 'stage'];

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
     * Format the FQDN string: trim whitespace, strip leading dots, lowercase (DNS is case-insensitive).
     *
     * @param string $domain The FQDN string to be formatted
     *
     * @return string The formatted FQDN string
     */
    public static function formatFqdn(string $domain): string
    {
        // DNS matching is case-insensitive; lowercase so Console.oaao.ai hits exact keys.
        return \strtolower(\trim(\ltrim($domain, '.')));
    }

    /**
     * Leftmost DNS label of a host (one-label only). Port suffixes are ignored.
     */
    public static function leftLabel(string $host): string
    {
        $host = self::formatFqdn($host);
        [$host] = \explode(':', $host . ':', 2);

        [$label] = \explode('.', $host, 2);
        return $label;
    }

    /**
     * Whether the host's leftmost label is reserved (www, console, admin, api, stage).
     * Only the first label is considered.
     */
    public static function isReservedLeftLabel(string $hostOrLabel): bool
    {
        return \in_array(self::leftLabel($hostOrLabel), self::RESERVED_LEFT_LABELS, true);
    }

    /**
     * A tenant slug is exactly one DNS label and is not a reserved left-label.
     */
    public static function isOneLabelSlug(string $label): bool
    {
        $label = self::formatFqdn($label);
        [$label] = \explode(':', $label . ':', 2);
        if ('' === $label || \str_contains($label, '.') || '*' === $label) {
            return false;
        }
        if (self::isReservedLeftLabel($label)) {
            return false;
        }

        return 1 === \preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label);
    }

    /**
     * Localhost / loopback / default catch-all hosts (not a public apex).
     */
    public static function isLocalOrDefaultHost(string $host): bool
    {
        $host = self::formatFqdn($host);
        [$host] = \explode(':', $host . ':', 2);

        return \in_array($host, ['*', 'localhost', '127.0.0.1', '::1', '0.0.0.0'], true)
            || \str_ends_with($host, '.localhost')
            || \str_ends_with($host, '.local');
    }

    /**
     * Whether a sites binding of `*.{apex}` may be seeded for this tenant.
     *
     * Do not seed *.{apex} onto personal tenants, localhost, or the default `*` catch-all.
     */
    public static function allowsApexWildcardSeed(string $apex, bool $personalOrDefaultTenant = false): bool
    {
        if ($personalOrDefaultTenant) {
            return false;
        }

        $apex = self::formatFqdn($apex);
        [$apex] = \explode(':', $apex . ':', 2);
        if ('' === $apex || '*' === $apex || self::isLocalOrDefaultHost($apex)) {
            return false;
        }

        return true;
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
     * Returns REMOTE_ADDR by default. Forwarded headers (X-Forwarded-For,
     * etc.) are NOT trusted because they can be spoofed by any client.
     * If you are behind a trusted reverse proxy, extract the real IP from
     * the forwarded header in your application layer after verifying
     * REMOTE_ADDR is your proxy.
     *
     * @return string The IP address
     */
    public static function getIP(): string
    {
        if (isset($_SERVER['REMOTE_ADDR'])) {
            return $_SERVER['REMOTE_ADDR'];
        }

        return 'UNKNOWN';
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
