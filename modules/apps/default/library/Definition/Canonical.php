<?php

/**
 * razymod/apps — canonical JSON and the content hash of a definition.
 *
 * One definition has exactly one byte string, whatever order its keys were
 * written in, so the hash identifies content (registry sync, cache keys,
 * "has this changed"):
 *
 * - object keys sorted by byte value; list order kept
 * - null values, empty lists and empty objects inside objects are dropped
 *   (absent and empty mean the same in schema v1, and PHP cannot tell `{}`
 *   from `[]`)
 * - integral floats within ±2^53 are written as integers (1.0 → 1); other
 *   floats use PHP's shortest round-trip form; NaN / infinity are refused
 * - strings must be valid UTF-8 and are written unescaped (no \uXXXX for
 *   non-ASCII, no escaped slashes); no Unicode normalisation is applied
 * - no whitespace; nesting deeper than 64 levels is refused
 *
 * hash() is "sha256:" + hex SHA-256 of that string.
 */

declare(strict_types=1);

namespace razymod\apps\Definition;

use InvalidArgumentException;
use stdClass;

final class Canonical
{
    private const MAX_DEPTH = 64;

    private const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /**
     * @param array<mixed> $doc
     *
     * @throws InvalidArgumentException when the document cannot be canonicalised
     */
    public static function json(array $doc): string
    {
        return \json_encode(self::normalize($doc, 0), self::FLAGS);
    }

    /**
     * @param array<mixed> $doc
     */
    public static function hash(array $doc): string
    {
        return 'sha256:' . \hash('sha256', self::json($doc));
    }

    private static function normalize(mixed $v, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException('Definition is nested deeper than ' . self::MAX_DEPTH . ' levels');
        }
        if (\is_array($v)) {
            if (\array_is_list($v)) {
                $out = [];
                foreach ($v as $item) {
                    $out[] = self::normalize($item, $depth + 1);
                }

                return $out;
            }
            /** @var array<int|string, mixed> $out numeric-string keys become ints in PHP */
            $out = [];
            foreach ($v as $k => $item) {
                $item = self::normalize($item, $depth + 1);
                if ($item === null || $item === [] || ($item instanceof stdClass && (array) $item === [])) {
                    continue;
                }
                $out[(string) $k] = $item;
            }
            \ksort($out, SORT_STRING);

            // an object whose members were all dropped is still an object, and
            // so is one whose keys happen to be 0..n-1 after sorting
            return ($out === [] || \array_is_list($out)) ? (object) $out : $out;
        }
        if (\is_float($v)) {
            if (!\is_finite($v)) {
                throw new InvalidArgumentException('Definition contains NaN or infinity');
            }
            if (\floor($v) === $v && \abs($v) <= 9007199254740992.0) {
                return (int) $v;
            }

            return $v;
        }
        if (\is_string($v) && \preg_match('//u', $v) !== 1) {
            throw new InvalidArgumentException('Definition contains a string that is not valid UTF-8');
        }
        if (\is_object($v)) {
            throw new InvalidArgumentException('Definition must be plain arrays and scalars');
        }

        return $v;
    }
}
