<?php

/**
 * This file is part of Razy v1.0.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 *
 * @license MIT
 */

namespace Razy\Translation;

/**
 * Selects the correct plural segment of a translation line.
 *
 * Line format (Laravel-compatible subset):
 *   "one segment|other segment"
 *   "{0} none|{1} exactly one|[2,*] many"
 *   "There is one apple|There are :count apples"
 *
 * Rules:
 *   - Explicit selectors {n} / [a,b] (b may be *) are matched first, in order.
 *   - Otherwise, positional selection: 2 segments → index 0 when $number==1, else 1;
 *     >2 segments → delegates to the locale's category callable (default: English).
 *   - CJK locales (zh/ja/ko/vi/th) have a single "other" category: with 2+ segments
 *     and no explicit selector they resolve to the LAST segment, so authors can write
 *     the count-aware variant once and it always wins for those languages.
 */
class Pluralizer
{
    /** @var list<string> Language prefixes with a single plural category */
    private const SINGLE_CATEGORY_PREFIXES = ['zh', 'ja', 'ko', 'vi', 'th'];

    /** @var array<string, callable(int|float): int> Custom per-locale category rules */
    private static array $rules = [];

    /**
     * Register a category resolver for a locale prefix (e.g. ['ru', 'uk'] complex forms).
     *
     * @param callable(int|float): int $resolver returns a 0-based segment index
     */
    public static function registerRule(string $localePrefix, callable $resolver): void
    {
        self::$rules[\strtolower($localePrefix)] = $resolver;
    }

    /**
     * Resolve the plural line to a single segment for the given number.
     */
    public static function line(string $line, int|float $number, string $locale): string
    {
        $segments = self::split($line);

        if (\count($segments) === 1) {
            return $segments[0];
        }

        // 1) explicit {n} / [a,b] selectors win, evaluated in written order.
        foreach ($segments as $segment) {
            if (self::matchesSelector($segment, $number)) {
                return self::stripSelector($segment);
            }
        }

        // 2) no explicit selector matched → positional category.
        $category = self::category($number, $locale, \count($segments));

        return $segments[\min($category, \count($segments) - 1)];
    }

    /**
     * 0-based segment index for the number under the locale's plural rules.
     */
    public static function category(int|float $number, string $locale, int $segmentCount = 2): int
    {
        $prefix = \strtolower(\explode('-', \str_replace('_', '-', $locale))[0]);

        if (isset(self::$rules[$prefix])) {
            return (int) (self::$rules[$prefix])($number);
        }

        if (\in_array($prefix, self::SINGLE_CATEGORY_PREFIXES, true)) {
            // Single "other" category → the last authored segment.
            return $segmentCount - 1;
        }

        // English/default: one vs other.
        return (int) ($number == 1 ? 0 : 1);
    }

    /**
     * Split on '|' while ignoring separators escaped as '\|'.
     *
     * @return list<string>
     */
    private static function split(string $line): array
    {
        $parts = \preg_split('/(?<!\\\)\|/', $line) ?: [$line];

        return \array_map(
            static fn (string $p): string => \str_replace('\|', '|', \trim($p)),
            $parts,
        );
    }

    /**
     * Does the segment carry an explicit selector that matches $number?
     */
    private static function matchesSelector(string $segment, int|float $number): bool
    {
        if (\preg_match('/^\{\s*(-?\d+(?:\.\d+)?)\s*\}/', $segment, $m) === 1) {
            return (float) $m[1] == (float) $number;
        }

        if (\preg_match('/^\[\s*(-?\d+|\*)\s*,\s*(-?\d+|\*)\s*\]/', $segment, $m) === 1) {
            $low = $m[1] === '*' ? null : (int) $m[1];
            $high = $m[2] === '*' ? null : (int) $m[2];

            return ($low === null || $number >= $low) && ($high === null || $number <= $high);
        }

        return false;
    }

    private static function stripSelector(string $segment): string
    {
        return \ltrim((string) \preg_replace('/^(\{[^}]*\}|\[[^\]]*\])\s*/', '', $segment));
    }
}
