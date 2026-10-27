<?php

/**
 * razymod/apps — Values from a flat map keyed by dotted path.
 *
 *   new ArrayValues(['amount' => 1200, 'stage' => 'won', 'stage.probability' => 1])
 */

declare(strict_types=1);

namespace razymod\apps\Expression;

final class ArrayValues implements Values
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(private readonly array $values)
    {
    }

    public function get(array $path): mixed
    {
        return $this->values[\implode('.', $path)] ?? null;
    }
}
