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
 * Provides static utility methods for string generation and formatting.
 *
 * @package Razy
 * @license MIT
 */

namespace Razy\Util;

/**
 * String generation and formatting utilities.
 *
 * Provides methods for GUID generation, JSON validation,
 * file size formatting, and path-level sorting.
 */
class StringUtil
{
    /**
     * Generate the GUID by given length.
     *
     * @param int $length The length of the GUID clip, each clip has 4 characters. Default value: 4
     *
     * @return string The GUID string
     */
    public static function guid(int $length = 4): string
    {
        $length = max(1, $length);
        $pattern = '%04X';
        if ($length > 1) {
            $pattern .= str_repeat('-%04X', $length - 1);
        }

        $args = array_fill(1, $length, '');
        array_walk($args, function (&$item) {
            $item = mt_rand(0, 65535);
        });
        array_unshift($args, $pattern);

        return strtolower(call_user_func_array('sprintf', $args));
    }

    /**
     * Check if a string is valid JSON.
     *
     * @param string $string The string to check
     *
     * @return bool True if the string is valid JSON
     */
    public static function isJson(string $string): bool
    {
        if (function_exists('json_validate')) {
            return json_validate($string);
        }

        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Generate the file size with the unit.
     *
     * @param float $size The file size in bytes
     * @param int $decPoint Sets the number of decimal points
     * @param bool $upperCase Convert the unit into uppercase
     * @param string $separator The separator between the size and unit
     *
     * @return string The formatted file size
     */
    public static function getFilesizeString(float $size, int $decPoint = 2, bool $upperCase = false, string $separator = ''): string
    {
        $unitScale = ['byte', 'kb', 'mb', 'gb', 'tb', 'pb', 'eb', 'zb', 'yb'];
        $unit = 'byte';
        $scale = 0;
        $decPoint = ($decPoint < 1) ? 0 : $decPoint;

        while ($size >= 1024 && isset($unitScale[$scale + 1])) {
            $size /= 1024;
            $unit = $unitScale[++$scale];
        }

        $size = ($decPoint) ? number_format($size, $decPoint) : (int)$size;

        if ($upperCase) {
            $unit = strtoupper($unit);
        }

        return $size . $separator . $unit;
    }

    /**
     * Sort the route by its folder level, deepest is priority.
     *
     * @param array &$routes An array contains the routing path (sorted in-place by key)
     */
    public static function sortPathLevel(array &$routes): void
    {
        uksort($routes, function ($path_a, $path_b) {
            $count_a = substr_count(PathUtil::tidy($path_a, true, '/'), '/');
            $count_b = substr_count(PathUtil::tidy($path_b, true, '/'), '/');
            if ($count_a === $count_b) {
                return 0;
            }

            return ($count_a < $count_b) ? 1 : -1;
        });
    }
}
