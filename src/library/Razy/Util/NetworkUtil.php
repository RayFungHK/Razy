<?php

/**
 * This file is part of Razy.
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

use Razy\Http\Request;
use Razy\Http\TrustedProxies;

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
     * @deprecated since RZ-21; removed in v1.3. Tenant naming is application policy: keep the list in
     *             the app and apply it in a request resolver ({@see \Razy\Agent::resolveRequest()}).
     *
     * @var list<string>
     */
    public const RESERVED_LEFT_LABELS = ['www', 'console', 'admin', 'api', 'stage'];

    /** @var bool Whether the deprecated untrusted X-Forwarded-Proto fallback was logged */
    private static bool $legacyProtoWarned = false;

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
        // DNS matching is case-insensitive; lowercase so Console.example.com hits exact keys.
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
     *
     * @deprecated since RZ-21; removed in v1.3. Compare {@see leftLabel()} with the app's own list.
     */
    public static function isReservedLeftLabel(string $hostOrLabel): bool
    {
        return \in_array(self::leftLabel($hostOrLabel), self::RESERVED_LEFT_LABELS, true);
    }

    /**
     * A tenant slug is exactly one DNS label and is not a reserved left-label.
     *
     * @deprecated since RZ-21; removed in v1.3. Tenant slug rules belong in the app's request resolver.
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
     *
     * @deprecated since RZ-21; removed in v1.3. Application::updateSites() no longer filters
     *             wildcard bindings: a `*.apex` entry in sites.inc.php registers as written.
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
     * Reads HTTPS / REQUEST_SCHEME / SERVER_PORT (compared as an integer:
     * $_SERVER holds it as a string), and X-Forwarded-Proto only from a
     * trusted proxy (RZ-04, see Razy\Http\TrustedProxies).
     *
     * Deprecated fallback: while no trusted proxy is configured, an
     * `X-Forwarded-Proto: https` header from any peer is still honoured, as
     * before RZ-04, and logged once per process. Configure
     * `RAZY_TRUSTED_PROXIES` (or config.inc.php 'trusted_proxies') to switch to
     * the strict behaviour; the fallback will be removed in the next major.
     *
     * @param array<string, mixed>|null $server Server variables (default: $_SERVER; RZ-18)
     *
     * @return bool True if the connection is using SSL/HTTPS
     */
    public static function isSsl(?array $server = null): bool
    {
        $server ??= $_SERVER;
        if ((new Request($server))->isSecure()) {
            return true;
        }

        if (!TrustedProxies::current()->isConfigured()
            && 'https' === \strtolower(\trim((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '')))) {
            if (!self::$legacyProtoWarned) {
                self::$legacyProtoWarned = true;
                \error_log('[Razy] Deprecated: X-Forwarded-Proto trusted from an unconfigured peer. '
                    . 'Set RAZY_TRUSTED_PROXIES to your proxy CIDRs; untrusted forwarded headers will be ignored in the next major.');
            }

            return true;
        }

        return false;
    }

    /**
     * Get the visitor IP.
     *
     * Returns REMOTE_ADDR, or — when REMOTE_ADDR is a trusted proxy (RZ-04,
     * see Razy\Http\TrustedProxies) — the first untrusted hop of
     * X-Forwarded-For. Forwarded headers from any other peer are ignored.
     *
     * @return string The IP address
     */
    public static function getIP(): string
    {
        return Request::fromGlobals()->clientIp();
    }

    /**
     * Check if the IP is in the CIDR range. Supports IPv4 and IPv6; a bare
     * address is a single-host range. Addresses of different families never match.
     *
     * @param string $ip The IP address to check
     * @param string $cidr The CIDR notation range (e.g. '192.168.1.0/24', '2001:db8::/32')
     *
     * @return bool True if the IP is within the CIDR range
     */
    public static function ipInRange(string $ip, string $cidr): bool
    {
        [$range, $bits] = \array_pad(\explode('/', \trim($cidr), 2), 2, null);
        $ipBin = false !== \filter_var($ip, FILTER_VALIDATE_IP) ? \inet_pton($ip) : false;
        $rangeBin = false !== \filter_var($range, FILTER_VALIDATE_IP) ? \inet_pton((string) $range) : false;
        if (false === $ipBin || false === $rangeBin || \strlen($ipBin) !== \strlen($rangeBin)) {
            return false;
        }

        $maxBits = \strlen($ipBin) * 8;
        if (null === $bits) {
            $bits = $maxBits;
        } elseif (1 !== \preg_match('/^\d{1,3}$/', $bits) || (int) $bits > $maxBits) {
            return false;
        }
        $bits = (int) $bits;

        $fullBytes = \intdiv($bits, 8);
        if (\substr($ipBin, 0, $fullBytes) !== \substr($rangeBin, 0, $fullBytes)) {
            return false;
        }
        $remainder = $bits % 8;
        if (0 === $remainder) {
            return true;
        }
        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (\ord($ipBin[$fullBytes]) & $mask) === (\ord($rangeBin[$fullBytes]) & $mask);
    }
}
