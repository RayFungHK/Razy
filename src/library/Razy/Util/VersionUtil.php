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
        $requirement = \preg_replace('/\s*\|\|\s*/', '|', $requirement);
        $requirement = \preg_replace('/\s*-\s*(*SKIP)(*FAIL)|\s*,\s*|\s+/', ',', $requirement);

        $clips = SimpleSyntax::parseSyntax($requirement);
        $parser = function (array &$extracted) use (&$parser, $version) {
            $result = false;

            while ($clip = \array_shift($extracted)) {
                if (\is_array($clip)) {
                    $result = $parser($clip);
                } else {
                    $clip = \trim($clip);
                    if (\preg_match('/^((\d+)(?:\.\d+){0,3})\s*-\s*((\d+)(?:\.\d+){0,3})$/', $clip, $matches)) {
                        // Version Range
                        $min = self::standardize($matches[1]);
                        $max = self::standardize($matches[3]);
                        $result = \version_compare($version, $min, '>=') && \version_compare($version, $max, '<');
                    } elseif (\preg_match('/^(!=?|~|\^|>=?|<=?)((\d+)(?:\.\d+){0,3})$/', $clip, $matches)) {
                        $major = (int) $matches[3];
                        $constraint = $matches[1];
                        $vs = self::standardize($matches[2]);

                        if ('^' == $constraint) {
                            // Caret Version Range
                            if (0 == $major) {
                                $splits = \explode('.', $vs);
                                $compare = '0.' . $splits[1] . '.' . $splits[2] . '.' . $splits[3];
                            } else {
                                $compare = ($major + 1) . '.0.0.0';
                            }
                            $result = \version_compare($version, $vs, '>=') && \version_compare($version, $compare, '<');
                        } elseif ('~' == $constraint) {
                            // Tilde Version Range
                            $splits = \explode('.', $vs);
                            while (\count($splits) && 0 == \end($splits)) {
                                unset($splits[\count($splits) - 1]);
                            }
                            if (\count($splits) <= 1) {
                                return false;
                            }
                            unset($splits[\count($splits) - 1]);

                            if (1 == \count($splits)) {
                                $compare = ($major + 1) . '.0.0.0';
                            } else {
                                ++$splits[\count($splits) - 1];
                                $compare = self::standardize(\implode('.', $splits));
                            }
                            $result = \version_compare($version, $vs, '>=') && \version_compare($version, $compare, '<');
                        } else {
                            // Common version compare
                            if ('!' == $constraint || '!=' == $constraint) {
                                $operator = '<>';
                            } else {
                                $operator = $matches[1];
                            }
                            $result = \version_compare($version, $vs, $operator);
                        }

                        // Check if logical character is existing
                        if (\count($extracted)) {
                            $logical = \array_shift($extracted);
                            if (!\preg_match('/^[|,]$/', $logical)) {
                                return false;
                            }

                            if ('|' == $logical && $result) {
                                return true;
                            }
                            if (',' == $logical && !$result) {
                                return false;
                            }
                        }
                    } elseif (\preg_match('/^((\d+)(?:\.(?:\d+|\*)){0,3})$/', $clip, $matches)) {
                        $compare = self::standardize($clip, true);
                        if (\str_contains($compare, '*')) {
                            $compare = \str_replace(['*', '.'], ['\d+', '\.'], $compare);
                            $result = \preg_match('/^' . $compare . '$/', $version);
                        } else {
                            $result = $compare == $version;
                        }
                    } else {
                        return false;
                    }
                }
            }

            return $result;
        };

        return $parser($clips);
    }
}
