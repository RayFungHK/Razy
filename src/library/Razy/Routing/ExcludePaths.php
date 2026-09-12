<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Routing;

use Razy\Exception\ConfigurationException;

/**
 * Validates and normalizes the host-level `exclude_paths` site configuration.
 *
 * `exclude_paths` declares sibling applications sharing the same host whose URL
 * prefixes the generated `.htaccess` / `Caddyfile` must NEVER claim (Route
 * Coexistence Phase 1 — the declared-sibling-exclusion cure for FM-1, see
 * `architecture/ROUTE-COEXISTENCE.md` §3(b)/§4 and `manual/08-coexistence.md`).
 *
 * Decisions recorded here (answers to the dossier's open questions):
 *
 * - **Q1 — host-level placement.** The key lives in `sites.inc.php` beside
 *   `domains`/`alias`, NOT in `dist.php`: an exclusion is a statement about
 *   sibling apps on the shared host, not a per-distributor claim, and one
 *   declaration must cover every distributor on the host. Read path:
 *   `Application::loadSiteConfig()` → forwarded to both compilers by
 *   `Application::updateRewriteRules()` / `Application::updateCaddyfile()`.
 * - **Q2 — denylist stays a pure loop-guard.** The internal-file denylist in
 *   the fallback rule remains an `[L]`-loop breaker only; this declared list is
 *   the coexistence contract instead of relying on accidental exemptions (FM-4).
 * - **Q3 — no `reverse_proxy` emission.** The generated Caddyfile only declines
 *   to claim excluded paths (`php_server` matcher); emitting proxy directives
 *   for third-party upstreams would make Razy's machine-owned file the app-graph
 *   owner. Upstream wiring stays in the edge operator's config.
 *
 * Values are host-absolute URI prefixes (e.g. `/api-py`) compared against the
 * FULL request URI (Apache `%{REQUEST_URI}` / Caddy `path`), so a sub-directory
 * install must write the prefix including the install sub-path.
 */
final class ExcludePaths
{
    /**
     * Whitelist: '/'-separated segments of letters, digits, '.', '_' and '-'.
     *
     * Deliberately stricter than the URL grammar: the value is interpolated
     * into generated web-server matching rules, so any character with regex or
     * glob meaning (`* ? ( ) [ ] + : ... `) is rejected outright rather than
     * silently neutralized.
     */
    private const ENTRY_PATTERN = '~^/[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)*$~';

    /**
     * First segments that are Razy's own entry points / internal URL space.
     *
     * Excluding them would disable Razy itself (same names the loop-guard
     * denylist protects in the generated fallback rule, `htaccess.tpl:40`).
     */
    private const RESERVED_SEGMENTS = [
        'index.php',
        'sites',
        'sites.inc.php',
        'config.inc.php',
        'repository.inc.php',
        'system',
        'shared',
        'plugins',
        'asset',
        'data',
        'webassets',
    ];

    /**
     * Validate and normalize a raw `exclude_paths` config value.
     *
     * A single tolerated trailing slash is trimmed (`/api-py/` behaves as
     * `/api-py`); duplicates collapse keeping first occurrence order.
     *
     * @param mixed $paths Raw config value (expected: list of string prefixes)
     *
     * @return list<string> Validated, tidy'd, de-duplicated prefixes ([] when unset)
     *
     * @throws ConfigurationException When the value is not a list of valid prefixes
     */
    public static function normalize(mixed $paths): array
    {
        if (null === $paths || [] === $paths) {
            return [];
        }

        if (!\is_array($paths)) {
            throw new ConfigurationException(
                'The site configuration key \'exclude_paths\' must be an array of path prefix strings.',
            );
        }

        $normalized = [];
        $seen = [];

        foreach ($paths as $path) {
            if (!\is_string($path)) {
                throw new ConfigurationException(
                    'Each \'exclude_paths\' entry must be a string path prefix, got ' . \gettype($path) . '.',
                );
            }

            // Tolerate surrounding whitespace and one trailing slash.
            $entry = \rtrim(\trim($path), '/');

            if ('' === $entry) {
                throw new ConfigurationException(
                    'Invalid \'exclude_paths\' entry \'' . $path . '\': the prefix must be a host-absolute path such as \'/api-py\' (\'/\' alone would exclude the whole host).',
                );
            }

            if (!\preg_match(self::ENTRY_PATTERN, $entry)) {
                throw new ConfigurationException(
                    'Invalid \'exclude_paths\' entry \'' . $path . '\': path prefixes must start with \'/\' and use only letters, digits, \'.\', \'_\' and \'-\' in each segment — regex/glob syntax is rejected because the value is emitted verbatim into generated web-server rules.',
                );
            }

            $firstSegment = \explode('/', \substr($entry, 1))[0];

            if (\in_array($firstSegment, self::RESERVED_SEGMENTS, true)) {
                throw new ConfigurationException(
                    'Invalid \'exclude_paths\' entry \'' . $path . '\': \'/' . $firstSegment . '/\' is part of Razy\'s own URL space and cannot be declared as an exclusion.',
                );
            }

            if (!isset($seen[$entry])) {
                $seen[$entry] = true;
                $normalized[] = $entry;
            }
        }

        return $normalized;
    }

    /**
     * Escape a validated prefix for use inside an Apache `RewriteCond` regex.
     *
     * Apache evaluates RewriteCond patterns with APR regex (not PCRE), and
     * `normalize()` leaves `.` as the only character with regex meaning — so
     * escaping the dot is sufficient and avoids emitting escapes APR could
     * interpret differently.
     */
    public static function apachePattern(string $prefix): string
    {
        return \str_replace('.', '\.', $prefix);
    }

    /**
     * Build the space-separated Caddy `path` matcher pattern list.
     *
     * Caddy path matchers are exact unless a `*` wildcard is appended, so each
     * prefix contributes both `/prefix` (bare path) and `/prefix/*` (everything
     * below it). Caddy path patterns are globs, not regex — dots stay literal.
     *
     * @param list<string> $prefixes Normalized prefixes
     */
    public static function caddyPaths(array $prefixes): string
    {
        $patterns = [];

        foreach ($prefixes as $prefix) {
            $patterns[] = $prefix;
            $patterns[] = $prefix . '/*';
        }

        return \implode(' ', $patterns);
    }
}
