<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Config;

use Razy\Exception\ConfigurationException;
use Razy\Util\NetworkUtil;

/**
 * Builds the sites.inc.php `domains` table from a host list, typically the
 * `RAZY_DOMAINS` environment variable (RZ-04), so deployments do not have to
 * hardcode hosts, IPs and ports in a committed file.
 *
 * List syntax (comma or whitespace separated):
 *   app.example.com            -> mapped to the default site table
 *   *.example.com              -> one-label wildcard, default site table
 *   localhost:8193             -> host with port
 *   docs.example.com=docs@v2   -> whole host to one dist (optional @tag)
 *
 * In sites.inc.php:
 *   'domains' => \Razy\Config\DomainList::fromEnv(['/' => 'main'], 'RAZY_DOMAINS', ['localhost']),
 *
 * @license MIT
 */
final class DomainList
{
    /**
     * Read the host list from an environment variable; fall back to $fallback when it is unset or empty.
     *
     * @param array<string, string> $sites URL-path prefix => dist code, used for entries without `=dist`
     * @param string $variable Environment variable name
     * @param array<int, string> $fallback Hosts to use when the variable is unset or empty
     *
     * @return array<string, array<string, string>>
     *
     * @throws ConfigurationException On a malformed entry
     */
    public static function fromEnv(array $sites, string $variable = 'RAZY_DOMAINS', array $fallback = []): array
    {
        $value = \getenv($variable);
        if (false === $value || '' === \trim($value)) {
            return self::parse(\implode(',', $fallback), $sites);
        }

        return self::parse($value, $sites);
    }

    /**
     * Parse a host list into a `domains` table.
     *
     * @param string $list Comma/whitespace separated entries
     * @param array<string, string> $sites URL-path prefix => dist code for entries without `=dist`
     *
     * @return array<string, array<string, string>>
     *
     * @throws ConfigurationException On a malformed entry
     */
    public static function parse(string $list, array $sites): array
    {
        $domains = [];
        foreach (\preg_split('/[\s,]+/', \trim($list)) ?: [] as $entry) {
            if ('' === $entry) {
                continue;
            }
            [$host, $dist] = \array_pad(\explode('=', $entry, 2), 2, null);
            $host = NetworkUtil::formatFqdn((string) $host);
            if ('*' !== $host && !NetworkUtil::isFqdn($host, true)) {
                throw new ConfigurationException("Invalid domain '{$entry}' in domain list: expected host[:port] or host=dist.");
            }
            if (null !== $dist) {
                if (1 !== \preg_match(SiteConfig::DIST_IDENTIFIER, $dist)) {
                    throw new ConfigurationException("Invalid dist code in domain list entry '{$entry}'.");
                }
                $domains[$host] = ['/' => $dist];
            } else {
                $domains[$host] = $sites;
            }
        }

        return $domains;
    }
}
