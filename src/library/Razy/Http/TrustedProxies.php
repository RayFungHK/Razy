<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Http;

use Razy\Exception\ConfigurationException;
use Razy\Util\NetworkUtil;

/**
 * The reverse proxies whose X-Forwarded-* headers may be believed (RZ-04).
 *
 * A request's forwarded headers are honoured only when REMOTE_ADDR (the
 * peer that actually connected) is inside one of these ranges. Ranges are
 * IPv4 or IPv6 addresses or CIDR blocks; `*` trusts every connected peer
 * (only for setups where the app port is unreachable except through the
 * proxy). With `*` alone the client IP is the hop the proxy appended (the
 * rightmost X-Forwarded-For entry); list the proxy ranges to skip further hops.
 *
 * Configuration: `RAZY_TRUSTED_PROXIES` (comma/space separated) wins over
 * config.inc.php `'trusted_proxies'` (array or string).
 *
 * @license MIT
 */
final class TrustedProxies
{
    /** @var self|null Process-wide set used by Request::fromGlobals() and NetworkUtil */
    private static ?self $current = null;

    /**
     * @param array<int, string> $ranges Validated IP addresses / CIDR blocks
     * @param bool $trustAll Trust every peer (`*`)
     */
    public function __construct(private readonly array $ranges = [], private readonly bool $trustAll = false)
    {
    }

    /**
     * Parse a comma/space separated list (or array) of IPs / CIDR blocks.
     *
     * @param string|array<int, string> $list
     *
     * @return self
     *
     * @throws ConfigurationException On an entry that is not an IP or CIDR block
     */
    public static function fromList(string|array $list): self
    {
        $entries = \is_array($list) ? $list : (\preg_split('/[\s,]+/', $list) ?: []);
        $ranges = [];
        $trustAll = false;
        foreach ($entries as $entry) {
            $entry = \trim((string) $entry);
            if ('' === $entry) {
                continue;
            }
            if ('*' === $entry) {
                $trustAll = true;

                continue;
            }
            if (!self::isValidRange($entry)) {
                throw new ConfigurationException("Invalid trusted proxy '{$entry}': expected an IPv4/IPv6 address or CIDR block.");
            }
            $ranges[] = $entry;
        }

        return new self($ranges, $trustAll);
    }

    /**
     * Build from config.inc.php (`trusted_proxies`), with `RAZY_TRUSTED_PROXIES` taking precedence.
     *
     * @param array<string, mixed> $config
     *
     * @return self
     */
    public static function fromConfig(array $config): self
    {
        $env = \getenv('RAZY_TRUSTED_PROXIES');
        if (false !== $env && '' !== \trim($env)) {
            return self::fromList($env);
        }
        $configured = $config['trusted_proxies'] ?? [];
        if (\is_string($configured) || \is_array($configured)) {
            return self::fromList($configured);
        }

        throw new ConfigurationException("config.inc.php 'trusted_proxies' must be a list of IPs / CIDR blocks.");
    }

    /**
     * Set (or clear with null) the process-wide trusted proxies.
     *
     * @param self|null $proxies
     */
    public static function configure(?self $proxies): void
    {
        self::$current = $proxies;
    }

    /**
     * The process-wide trusted proxies (none when never configured).
     *
     * @return self
     */
    public static function current(): self
    {
        return self::$current ??= new self();
    }

    /**
     * Whether the string is an IPv4/IPv6 address or a CIDR block with a valid prefix length.
     *
     * @param string $range
     *
     * @return bool
     */
    private static function isValidRange(string $range): bool
    {
        [$ip, $bits] = \array_pad(\explode('/', $range, 2), 2, null);
        if (false === \filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        if (null === $bits) {
            return true;
        }
        $max = \str_contains((string) $ip, ':') ? 128 : 32;

        return 1 === \preg_match('/^\d{1,3}$/', $bits) && (int) $bits <= $max;
    }

    /**
     * Whether any proxy is trusted.
     *
     * @return bool
     */
    public function isConfigured(): bool
    {
        return $this->trustAll || [] !== $this->ranges;
    }

    /**
     * Whether the peer address belongs to a trusted proxy.
     *
     * @param string $ip
     *
     * @return bool
     */
    public function isTrusted(string $ip): bool
    {
        $ip = \trim($ip);
        if (false === \filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        if ($this->trustAll) {
            return true;
        }

        return $this->inRanges($ip);
    }

    /**
     * Resolve the client address from the connected peer and X-Forwarded-For:
     * walk the chain right to left, skipping trusted proxies; the first
     * untrusted hop is the client. Entries left of it are client-supplied and
     * ignored.
     *
     * @param string $remoteAddr The connected peer (REMOTE_ADDR)
     * @param string|null $forwardedFor The X-Forwarded-For header
     *
     * @return string
     */
    public function resolveClientIp(string $remoteAddr, ?string $forwardedFor): string
    {
        if (null === $forwardedFor || '' === \trim($forwardedFor) || !$this->isTrusted($remoteAddr)) {
            return $remoteAddr;
        }

        // Hops inside the chain are checked against the explicit ranges only:
        // `*` vouches for the connected peer, never for client-supplied entries.
        $client = $remoteAddr;
        foreach (\array_reverse(\explode(',', $forwardedFor)) as $hop) {
            $hop = \trim($hop);
            if (false === \filter_var($hop, FILTER_VALIDATE_IP)) {
                break;
            }
            $client = $hop;
            if (!$this->inRanges($hop)) {
                break;
            }
        }

        return $client;
    }

    /**
     * Whether the address is inside one of the explicit ranges (ignores `*`).
     *
     * @param string $ip
     *
     * @return bool
     */
    private function inRanges(string $ip): bool
    {
        foreach ($this->ranges as $range) {
            if (NetworkUtil::ipInRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }
}
