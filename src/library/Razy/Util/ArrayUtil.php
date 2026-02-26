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
 * Provides static utility methods for array manipulation.
 *
 * @package Razy
 *
 * @license MIT
 */

namespace Razy\Util;

use Razy\Collection;

/**
 * Array manipulation utilities.
 *
 * Provides methods for constructing arrays from structures, refactoring
 * datasets, comparing values, and wrapping data in Collection objects.
 */
class ArrayUtil
{
    /**
     * Merge one or more arrays recursively by following the structure.
     *
     * @param array $structure The structure template
     * @param array ...$sources Source arrays to merge into the structure
     *
     * @return array The merged result
     */
    public static function construct(array $structure, array ...$sources): array
    {
        $recursive = function ($source, $structure) use (&$recursive) {
            foreach ($structure as $key => $value) {
                if (\is_array($value) && !empty($value)) {
                    $structure[$key] = $recursive($source[$key], $value);
                } else {
                    $structure[$key] = $source[$key] ?? $value;
                }
            }

            return $structure;
        };

        foreach ($sources as $source) {
            $structure = $recursive($structure, $source);
        }

        return $structure;
    }

    /**
     * Refactor an array of data into a new data set by given key set.
     *
     * @param array $source An array of data
     * @param string ...$keys An array of key to extract
     *
     * @return array An array of refactored data set
     */
    public static function refactor(array $source, string ...$keys): array
    {
        $result = [];
        $kvp = \array_keys($source);
        if (\count($keys)) {
            $kvp = \array_intersect($kvp, $keys);
        }

        while ($key = \array_shift($kvp)) {
            foreach ($source[$key] as $index => $value) {
                if (!isset($result[$index])) {
                    $result[$index] = [];
                }
                $result[$index][$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Refactor an array of data into a new data set by given key set using a user-defined processor function.
     *
     * @param array $source An array of data (modified by reference)
     * @param callable $callback The processor function
     * @param string ...$keys An array of key to extract
     *
     * @return array An array of refactored data set
     */
    public static function urefactor(array &$source, callable $callback, string ...$keys): array
    {
        $result = [];
        $kvp = \array_keys($source);
        if (\count($keys)) {
            $kvp = \array_intersect($kvp, $keys);
        }

        foreach ($kvp as $key) {
            foreach ($source[$key] as $index => $value) {
                if (!isset($result[$index])) {
                    $result[$index] = [];
                }
                $result[$index][$key] = &$source[$key][$index];
            }
        }

        $remove = [];
        foreach ($result as $key => $value) {
            if (!$callback($key, $value)) {
                $remove[] = $key;
            }
        }

        foreach ($kvp as $key) {
            foreach ($remove as $keyToRemove) {
                unset($source[$key][$keyToRemove]);
            }
        }

        return $result;
    }

    /**
     * Compare two values by provided comparison operator.
     *
     * Supported operators: =, !=, >, >=, <, <=, |= (in array),
     * ^= (starts with), $= (ends with), *= (contains).
     *
     * @param mixed|null $valueA The value of A
     * @param mixed|null $valueB The value of B
     * @param string $operator The comparison operator
     * @param bool $strict If true, also check the types of both values
     *
     * @return bool Return the comparison result
     */
    public static function comparison(mixed $valueA = null, mixed $valueB = null, string $operator = '=', bool $strict = false): bool
    {
        if (!$strict) {
            $valueA = (\is_scalar($valueA)) ? (string) $valueA : $valueA;
            $valueB = (\is_scalar($valueB)) ? (string) $valueB : $valueB;
        }

        // Equal
        if ('=' === $operator) {
            return $valueA === $valueB;
        }

        // Not equal
        if ('!=' === $operator) {
            return $valueA !== $valueB;
        }

        // Greater than
        if ('>' === $operator) {
            return $valueA > $valueB;
        }

        // Greater than and equal with
        if ('>=' === $operator) {
            return $valueA >= $valueB;
        }

        // Less than
        if ('<' === $operator) {
            return $valueA < $valueB;
        }

        // Less than and equal with
        if ('<=' === $operator) {
            return $valueA <= $valueB;
        }

        // Includes in
        if ('|=' === $operator) {
            if (!\is_scalar($valueA) || !\is_array($valueB)) {
                return false;
            }

            return \in_array($valueA, $valueB, true);
        }

        if ('^=' === $operator) {
            // Beginning with
            $valueB = '/^.*' . \preg_quote($valueB) . '/';
        } elseif ('$=' === $operator) {
            // End with
            $valueB = '/' . \preg_quote($valueB) . '.*$/';
        } elseif ('*=' === $operator) {
            // Include
            $valueB = '/' . \preg_quote($valueB) . '/';
        }

        return (bool) \preg_match($valueB, $valueA);
    }

    /**
     * Convert the data into a Collection object.
     *
     * @param mixed $data The data to wrap
     *
     * @return Collection The Collection instance
     */
    public static function collect($data): Collection
    {
        return new Collection($data);
    }
}
