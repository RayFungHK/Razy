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
 * Provides static utility methods for path manipulation and normalization.
 *
 * @package Razy
 *
 * @license MIT
 */

namespace Razy\Util;

/**
 * Path manipulation utilities.
 *
 * Provides methods for tidying, appending, resolving relative paths,
 * fixing path traversals, and detecting directory paths.
 */
class PathUtil
{
    /**
     * Tidy the path, remove duplicated slash or backslash.
     *
     * @param string $path The original path
     * @param bool $ending Add a directory separator at the end of the path
     * @param string $separator The separator will be replaced in, default as directory separator
     *
     * @return string The tidied path
     */
    public static function tidy(string $path, bool $ending = false, string $separator = DIRECTORY_SEPARATOR): string
    {
        return \preg_replace('/(^\w+:\/\/\/?(*SKIP)(*FAIL))|[\/\\\\]+/', $separator, $path . ($ending ? $separator : ''));
    }

    /**
     * Append additional path.
     *
     * @param string $path The original path
     * @param mixed ...$extra Additional path segments to append
     *
     * @return string The path appended extra path
     */
    public static function append(string $path, ...$extra): string
    {
        $separator = DIRECTORY_SEPARATOR;
        $protocol = '';
        if (\preg_match('/^(https?:\/\/)(.*)/', $path, $matches)) {
            $protocol = $matches[1];
            $path = $matches[2];
            $separator = '/';
        }

        foreach ($extra as $pathToAppend) {
            if (\is_array($pathToAppend) && \count($pathToAppend)) {
                $path .= $separator . \implode($separator, $pathToAppend);
            } elseif (\is_scalar($pathToAppend) && \strlen($pathToAppend)) {
                $path .= $separator . $pathToAppend;
            }
        }

        return $protocol . self::tidy($path, false, $separator);
    }

    /**
     * Return the relative path between two paths.
     *
     * @param string $path The full path
     * @param string $root The root path to strip
     *
     * @return string The relative path
     */
    public static function getRelativePath(string $path, string $root): string
    {
        $path = self::tidy($path);
        $root = self::tidy($root);

        $relativePath = \preg_replace('/^' . \preg_quote($root, '/\\') . '/', '', $path);
        return $relativePath ?? '';
    }

    /**
     * Fix the string of the relative path.
     *
     * @param string $path The path to fix
     * @param string $separator The path separator
     * @param bool $relative If true, return false when the path is not relative
     *
     * @return bool|string Return the fixed path or false if the path is not a relative path if the parameter is given
     */
    public static function fixPath(string $path, string $separator = DIRECTORY_SEPARATOR, bool $relative = false): bool|string
    {
        $path = \trim($path);
        $isDirectory = false;
        if (self::isDirPath($path)) {
            // If the path ending is a slash or backslash
            $isDirectory = true;
        } elseif (\preg_match('/^\.\.?$/', $path)) {
            // If the path is a `.` or `..` only
            $isDirectory = true;
        }

        $clips = \explode($separator, \rtrim(self::tidy($path, false, $separator), $separator));
        $pathAry = [];
        foreach ($clips as $index => $clip) {
            if ($index > 0) {
                if ('..' == $clip) {
                    if ('..' == \end($pathAry)) {
                        $pathAry[] = '..';
                    } elseif ('.' == \end($pathAry)) {
                        $pathAry[0] = '..';
                    } else {
                        \array_pop($pathAry);
                    }
                } elseif ('.' != $clip) {
                    $pathAry[] = $clip;
                }
            } else {
                $pathAry[] = $clip;
            }
        }

        $fixedPath = \implode($separator, $pathAry) . ($isDirectory ? $separator : '');

        if ($relative && !\str_starts_with($fixedPath, $path)) {
            return false;
        }

        return $fixedPath;
    }

    /**
     * Check if the path ends with a directory separator.
     *
     * @param string $path The path to check
     *
     * @return bool True if the path ends with a slash or backslash
     */
    public static function isDirPath(string $path): bool
    {
        return $path && \preg_match('/[\\\\\/]/', \substr($path, -1));
    }
}
