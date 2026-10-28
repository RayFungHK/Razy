<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Util;

/**
 * RZ-39: make LC_CTYPE UTF-8 aware without touching the locale on every request.
 *
 * escapeshellarg() and other C-locale dependent functions silently strip
 * non-ASCII bytes (IDN hostnames, UTF-8 file paths) under LC_CTYPE=C, so the
 * bootstrap wants a UTF-8 LC_CTYPE. Calling setlocale() on every request is
 * unsafe under a threaded SAPI (FrankenPHP, any ZTS server): the locale is
 * process-wide, and a request that called setlocale() makes PHP reset the
 * locale at request end (LC_ALL=C, then LC_CTYPE=C.UTF-8), so another thread
 * can see LC_CTYPE=C in between.
 *
 * So this changes the locale only when it is not already the target:
 * - PHP 8.2+ starts every request in LC_CTYPE=C.UTF-8 where the system has
 *   that locale, and with the default candidates any UTF-8 LC_CTYPE counts as
 *   the target, so no setlocale() runs and PHP has nothing to reset.
 * - A configured candidate list makes only those names the target.
 * - Once applied, a process (a persistent worker, or a second bootstrap in the
 *   same request) does not apply it again.
 *
 * Configuration: RAZY_LOCALE_CTYPE (env) wins over config.inc.php
 * 'locale_ctype'. Unset = DEFAULT_CANDIDATES. A comma list = those
 * candidates, tried in order ('env' = the environment's locale, setlocale's
 * ''). 'off' = never touch the locale.
 *
 * @license MIT
 */
final class Locale
{
    public const ENV = 'RAZY_LOCALE_CTYPE';

    public const CONFIG_KEY = 'locale_ctype';

    /** Tried in order; '' is the environment's locale. */
    public const DEFAULT_CANDIDATES = ['C.UTF-8', 'en_US.UTF-8', 'UTF-8', ''];

    private const OFF = ['off', 'none', 'false', '0'];

    /** LC_CTYPE once applied in this process; null before. */
    private static ?string $applied = null;

    /**
     * The candidates from the env value or the config value (env wins).
     *
     * @param array<string, mixed> $config config.inc.php
     *
     * @return list<string>|null null = off
     */
    public static function candidates(string|false $env, array $config = []): ?array
    {
        $raw = (false !== $env && '' !== \trim($env)) ? $env : ($config[self::CONFIG_KEY] ?? null);
        if (null === $raw || [] === $raw || '' === $raw) {
            return self::DEFAULT_CANDIDATES;
        }
        if (\is_string($raw)) {
            if (\in_array(\strtolower(\trim($raw)), self::OFF, true)) {
                return null;
            }
            $raw = \explode(',', $raw);
        }
        if (!\is_array($raw)) {
            return self::DEFAULT_CANDIDATES;
        }
        $list = [];
        foreach ($raw as $name) {
            if (!\is_string($name)) {
                continue;
            }
            $name = \trim($name);
            if ('' === $name) {
                continue;
            }
            $list[] = 'env' === \strtolower($name) ? '' : $name;
        }

        return [] === $list ? self::DEFAULT_CANDIDATES : $list;
    }

    /**
     * Whether $current already satisfies $candidates.
     *
     * With the defaults, any UTF-8 LC_CTYPE does. With a configured list, only a
     * listed name does (codeset spelling ignored: C.utf8 = C.UTF-8).
     *
     * @param list<string> $candidates
     */
    public static function isTarget(string|false $current, array $candidates): bool
    {
        if (false === $current || '' === $current) {
            return false;
        }
        if (self::DEFAULT_CANDIDATES === $candidates) {
            return 1 === \preg_match('/utf-?8/i', $current);
        }
        $want = self::normalise($current);
        foreach ($candidates as $name) {
            if ('' !== $name && self::normalise($name) === $want) {
                return true;
            }
        }

        return false;
    }

    /**
     * Make LC_CTYPE one of the candidates unless it already is. Returns the
     * LC_CTYPE in effect, or false when off or when no candidate is available.
     *
     * @param array<string, mixed> $config config.inc.php
     * @param (callable(int, string...): (string|false))|null $setlocale test seam; defaults to \setlocale
     */
    public static function ensureUtf8Ctype(string|false $env, array $config = [], ?callable $setlocale = null): string|false
    {
        if (null !== self::$applied) {
            return self::$applied;
        }
        $candidates = self::candidates($env, $config);
        if (null === $candidates) {
            return false;
        }
        $set = $setlocale ?? '\setlocale';
        // A query ('0') does not count as a change: PHP will not reset it.
        $current = $set(\LC_CTYPE, '0');
        if (self::isTarget($current, $candidates)) {
            return self::$applied = (string) $current;
        }
        $result = $set(\LC_CTYPE, ...$candidates);
        if (false === $result) {
            return false;
        }

        return self::$applied = $result;
    }

    /**
     * Forget the once-per-process state (tests only).
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$applied = null;
    }

    private static function normalise(string $name): string
    {
        return \strtolower(\str_replace('-', '', $name));
    }
}
