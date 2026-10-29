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
 * Provides static utility methods for semantic version comparison.
 *
 *
 * @license MIT
 */

namespace Razy\Util;

use Razy\SimpleSyntax;

/**
 * Version comparison utilities.
 *
 * Provides methods for standardizing version strings and performing
 * semantic version comparisons with support for ranges, tilde, caret,
 * and logical operators (AND/OR).
 */
class VersionUtil
{
    /**
     * Standardize the version code to a 4-segment format (e.g. '1.2.0.0').
     *
     * @param string $version The version string
     * @param bool $wildcard Whether to allow wildcard (*) segments
     *
     * @return false|string The standardized version or false if invalid
     */
    public static function standardize(string $version, bool $wildcard = false): false|string
    {
        $pattern = ($wildcard) ? '/^(\d+)(?:\.(?:\d+|\*)){0,3}$/' : '/^(\d+)(?:\.\d+){0,3}$/';
        if (!\preg_match($pattern, $version)) {
            return false;
        }

        $versions = [];
        $clips = \explode('.', $version);
        for ($i = 0; $i < 4; ++$i) {
            $clip = $clips[$i] ?? 0;
            $versions[] = ('*' == $clip) ? $clip : (int) $clip;
        }

        return \implode('.', $versions);
    }

    /**
     * Compare a version against a requirement string.
     *
     * Supports composer-style constraints: exact match, ranges (1.0 - 2.0),
     * caret (^1.2), tilde (~1.2), comparison operators (>=, <=, >, <, !=),
     * wildcard (1.2.*), logical OR (||) and AND (,).
     *
     * @param string $requirement A string of required version constraint
     * @param string $version The version number to check
     *
     * @return bool Return true if the version meets the requirement
     */
    public static function vc(string $requirement, string $version): bool
    {
        $version = \trim($version);
        if (($version = self::standardize($version)) === false) {
            return false;
        }

        // Standardize the logical OR/AND character, support composer version
        $requirement = \trim($requirement);
        if ('*' === $requirement) {
            // Any version (Composer's bare wildcard)
            return true;
        }
        $requirement = \preg_replace('/\s*\|\|\s*/', '|', $requirement);
        $requirement = \preg_replace('/\s*-\s*(*SKIP)(*FAIL)|\s*,\s*|\s+/', ',', $requirement);

        $clips = SimpleSyntax::parseSyntax($requirement);

        return self::matchAll($clips, $version);
    }

    /**
     * Every clip, then the combinator that follows it. A "," or "|" used to be
     * read only after a constraint, so `0|>=1.0` and `(>=1.0),(<2.0)` were
     * always false (RZ-53). "0" is a clip, not the end of the list (RZ-52).
     *
     * @param array<int, mixed> $clips
     */
    private static function matchAll(array $clips, string $version): bool
    {
        $result = false;

        while (null !== ($clip = \array_shift($clips))) {
            if (\is_array($clip)) {
                $result = self::matchAll($clip, $version);
            } else {
                $matched = self::matchClip(\trim((string) $clip), $version);
                if (null === $matched) {
                    return false;
                }
                $result = $matched;
            }

            $combined = self::combine($clips, $result);
            if (null !== $combined) {
                return $combined;
            }
        }

        return $result;
    }

    /**
     * Consume a following "," (and) or "|" (or). Null means keep walking.
     * True or false is the whole requirement. Anything else after a clip is
     * not a requirement (a constraint already rejected that; a group used to
     * ignore it, or throw when the next clip was an array).
     *
     * @param array<int, mixed> $clips
     */
    private static function combine(array &$clips, bool $result): ?bool
    {
        if ([] === $clips) {
            return null;
        }

        $logical = $clips[0];
        if (!\is_string($logical) || 1 !== \preg_match('/^[|,]$/', \trim($logical))) {
            return false;
        }

        \array_shift($clips);
        $logical = \trim($logical);
        if ('|' === $logical && $result) {
            return true;
        }
        if (',' === $logical && !$result) {
            return false;
        }

        return null;
    }

    /**
     * One clip: a hyphen range, a constraint, or an exact / wildcard version.
     * Null means the clip is not a version expression.
     */
    private static function matchClip(string $clip, string $version): ?bool
    {
        if (\preg_match('/^((\d+)(?:\.\d+){0,3})\s*-\s*((\d+)(?:\.\d+){0,3})$/', $clip, $matches)) {
            $min = self::standardize($matches[1]);
            $max = self::standardize($matches[3]);
            if (false === $min || false === $max) {
                return false;
            }

            return \version_compare($version, $min, '>=') && \version_compare($version, $max, '<');
        }

        if (\preg_match('/^(!=?|~|\^|>=?|<=?)((\d+)(?:\.\d+){0,3})$/', $clip, $matches)) {
            return self::matchConstraint($matches[1], $matches[2], $version);
        }

        if (\preg_match('/^((\d+)(?:\.(?:\d+|\*)){0,3})$/', $clip)) {
            return self::matchExact($clip, $version);
        }

        return null;
    }

    /**
     * Caret, tilde, or a comparison. $writtenText keeps the segment count ^ and ~ use.
     */
    private static function matchConstraint(string $constraint, string $writtenText, string $version): bool
    {
        $vs = self::standardize($writtenText);
        if (false === $vs) {
            return false;
        }
        $written = \array_map('intval', \explode('.', $writtenText));

        if ('^' === $constraint) {
            // Composer: the first non-zero written segment may not change.
            // ^1.2 < 2.0, ^0.3 < 0.4, ^0.0.3 < 0.0.4
            $bump = \count($written) - 1;
            foreach ($written as $index => $segment) {
                if (0 !== $segment) {
                    $bump = $index;
                    break;
                }
            }
            $compare = self::upperBound($written, $bump);

            return \version_compare($version, $vs, '>=') && \version_compare($version, $compare, '<');
        }

        if ('~' === $constraint) {
            // Composer: the last written segment may grow.
            // ~1.2 < 2.0, ~1.2.3 < 1.3.0, ~1 < 2.0
            $compare = self::upperBound($written, \max(0, \count($written) - 2));

            return \version_compare($version, $vs, '>=') && \version_compare($version, $compare, '<');
        }

        $operator = ('!' === $constraint || '!=' === $constraint) ? '<>' : $constraint;

        return \version_compare($version, $vs, $operator);
    }

    /**
     * An exact version, or a wildcard. From the first '*' on, every segment matches.
     */
    private static function matchExact(string $clip, string $version): bool
    {
        $compare = self::standardize($clip, true);
        if (false === $compare) {
            return false;
        }
        if (!\str_contains($compare, '*')) {
            return $compare === $version;
        }

        // 2.* = 2.x.y.z (before RZ-35 it padded to 2.*.0.0)
        $pattern = [];
        $any = false;
        foreach (\explode('.', $compare) as $segment) {
            $any = $any || '*' === $segment;
            $pattern[] = $any ? '\d+' : $segment;
        }

        return 1 === \preg_match('/^' . \implode('\.', $pattern) . '$/', $version);
    }

    /**
     * The exclusive upper bound for a caret or tilde range: keep the written
     * segments before $bump, add one to the segment at $bump, zero the rest.
     *
     * @param list<int> $written The segments as written in the constraint
     * @param int $bump Index of the segment to increment
     *
     * @return string A 4-segment version
     */
    private static function upperBound(array $written, int $bump): string
    {
        $upper = [];
        for ($i = 0; $i < 4; ++$i) {
            if ($i < $bump) {
                $upper[] = $written[$i] ?? 0;
            } elseif ($i === $bump) {
                $upper[] = ($written[$i] ?? 0) + 1;
            } else {
                $upper[] = 0;
            }
        }

        return \implode('.', $upper);
    }
}
