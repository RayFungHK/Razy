<?php

/**
 * razymod/apps — a cheap size check on caller input, before any real work.
 *
 * API commands receive PHP arrays from other modules (already decoded), so
 * the cap is on the value itself: an iterative walk that adds up an
 * approximate JSON size and nesting depth and stops as soon as either is
 * over its limit. Objects and resources are refused: definitions are plain
 * arrays and scalars.
 */

declare(strict_types=1);

namespace razymod\apps;

final class InputGuard
{
    /**
     * Whether $value is plain data of at most $limit bytes (approximate JSON
     * size) and $maxDepth levels.
     */
    public static function within(mixed $value, int $limit, int $maxDepth = 64): bool
    {
        $size = 0;
        $stack = [[$value, 0]];
        while ($stack !== []) {
            [$v, $depth] = \array_pop($stack);
            if (\is_array($v)) {
                if ($depth >= $maxDepth) {
                    return false;
                }
                $size += 2;
                foreach ($v as $k => $item) {
                    $size += (\is_string($k) ? \strlen($k) + 3 : 0) + 1;
                    $stack[] = [$item, $depth + 1];
                    if ($size > $limit) {
                        return false;
                    }
                }
            } elseif (\is_string($v)) {
                $size += \strlen($v) + 2;
            } elseif (\is_int($v) || \is_float($v) || \is_bool($v) || $v === null) {
                $size += 8;
            } else {
                return false;
            }
            if ($size > $limit) {
                return false;
            }
        }

        return true;
    }
}
