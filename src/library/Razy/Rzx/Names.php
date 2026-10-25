<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Razy\Rzx;

/**
 * Tag and component name rules, checked with linear scans instead of a
 * regex with a repeated group (which hits the PCRE JIT stack limit on a long
 * enough name and would then read as "no match").
 */
final class Names
{
    private const UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private const LOWER = 'abcdefghijklmnopqrstuvwxyz';

    private const DIGITS = '0123456789';

    private const ALNUM = self::UPPER . self::LOWER . self::DIGITS;

    /**
     * Length of the tag name at $pos: a letter, then letters and digits, then
     * any number of `.`, `-` or `:` each followed by letters or digits. 0 = none.
     */
    public static function scanTag(string $text, int $pos): int
    {
        $length = \strlen($text);
        if ($pos >= $length || !\str_contains(self::UPPER . self::LOWER, $text[$pos])) {
            return 0;
        }
        $end = $pos + 1 + \strspn($text, self::ALNUM, $pos + 1);
        while ($end + 1 < $length && \str_contains('.-:', $text[$end])) {
            $run = \strspn($text, self::ALNUM, $end + 1);
            if ($run === 0) {
                break;
            }
            $end += 1 + $run;
        }

        return $end - $pos;
    }

    /** `Card`, `Kit.Card`: dot-separated PascalCase segments. */
    public static function isComponent(string $name): bool
    {
        foreach (\explode('.', $name) as $segment) {
            if ($segment === '' || !\str_contains(self::UPPER, $segment[0]) || \strspn($segment, self::ALNUM) !== \strlen($segment)) {
                return false;
            }
        }

        return true;
    }

    /** `p`, `x-card`, `h1`: lowercase, dash-separated, starting with a letter. */
    public static function isElement(string $name): bool
    {
        foreach (\explode('-', $name) as $i => $segment) {
            $chars = self::LOWER . self::DIGITS;
            if ($segment === '' || \strspn($segment, $chars) !== \strlen($segment) || ($i === 0 && !\str_contains(self::LOWER, $segment[0]))) {
                return false;
            }
        }

        return true;
    }
}
