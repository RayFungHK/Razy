<?php

/**
 * razymod/apps — where the evaluator reads field values from.
 *
 * The record runtime (RZA-C) implements this over a loaded record and its
 * many-to-one lookups; ArrayValues serves tests and previews.
 */

declare(strict_types=1);

namespace razymod\apps\Expression;

interface Values
{
    /**
     * The raw value at a field path, or null when it is blank.
     *
     * @param list<string> $path
     */
    public function get(array $path): mixed;
}
