<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * @package Razy
 * @license MIT
 */

namespace Razy\ORM;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * A typed collection of Model instances returned from database queries.
 *
 * Provides array-like access, iteration, and functional-style helpers
 * (map, filter, pluck) for working with result sets.
 *
 * @template T of \Razy\ORM\Model
 * @implements ArrayAccess<int, T>
 * @implements IteratorAggregate<int, T>
 *
 * @package Razy\ORM
 */
class ModelCollection implements ArrayAccess, Countable, IteratorAggregate
{
    /** @var array<int, T> */
    private array $items;

    /**
     * @param array<int, T> $items Array of Model instances
     */
    public function __construct(array $items = [])
    {
        $this->items = array_values($items);
    }

    // ═══════════════════════════════════════════════════════════════
    // Access
    // ═══════════════════════════════════════════════════════════════

    /**
     * Get the first item, or null if empty.
     *
     * @return T|null
     */
    public function first(): ?Model
    {
        return $this->items[0] ?? null;
    }

    /**
     * Get the last item, or null if empty.
     *
     * @return T|null
     */
    public function last(): ?Model
    {
        return $this->items ? $this->items[array_key_last($this->items)] : null;
    }

    /**
     * Whether the collection is empty.
     */
    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    /**
     * Whether the collection is not empty.
     */
    public function isNotEmpty(): bool
    {
        return !empty($this->items);
    }

    /**
     * Get all items as a plain array of Model instances.
     *
     * @return array<int, T>
     */
    public function all(): array
    {
        return $this->items;
    }

    // ═══════════════════════════════════════════════════════════════
    // Transformation
    // ═══════════════════════════════════════════════════════════════

    /**
     * Convert all models to arrays.
     *
     * @return array<int, array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(fn(Model $model) => $model->toArray(), $this->items);
    }

    /**
     * Convert all models to a JSON string.
     *
     * @param int $options `json_encode` flags (e.g. `JSON_PRETTY_PRINT`)
     *
     * @return string
     *
     * @throws \JsonException
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->toArray(), $options | JSON_THROW_ON_ERROR);
    }

    /**
     * Extract a single attribute from each model.
     *
     * @param string      $attribute The attribute name to extract
     * @param string|null $keyBy     Optional attribute to use as array key
     *
     * @return array
     */
    public function pluck(string $attribute, ?string $keyBy = null): array
    {
        $result = [];

        foreach ($this->items as $model) {
            $value = $model->{$attribute};
            if ($keyBy !== null) {
                $result[$model->{$keyBy}] = $value;
            } else {
                $result[] = $value;
            }
        }

        return $result;
    }

    /**
     * Apply a callback to each item and return the mapped array.
     *
     * @param callable $callback fn(Model $model, int $index): mixed
     *
     * @return array
     */
    public function map(callable $callback): array
    {
        return array_map($callback, $this->items, array_keys($this->items));
    }

    /**
     * Filter items using a callback and return a new collection.
     *
     * @param callable $callback fn(Model $model): bool
     *
     * @return static
     */
    public function filter(callable $callback): static
    {
        return new static(array_values(array_filter($this->items, $callback)));
    }

    /**
     * Apply a callback to each item (no return).
     *
     * @param callable $callback fn(Model $model, int $index): void
     *
     * @return static
     */
    public function each(callable $callback): static
    {
        foreach ($this->items as $index => $item) {
            if ($callback($item, $index) === false) {
                break;
            }
        }

        return $this;
    }

    /**
     * Check if any model matches the callback.
     *
     * @param callable $callback fn(Model $model): bool
     */
    public function contains(callable $callback): bool
    {
        foreach ($this->items as $item) {
            if ($callback($item)) {
                return true;
            }
        }

        return false;
    }

    // ═══════════════════════════════════════════════════════════════
    // Aggregation & Reduction
    // ═══════════════════════════════════════════════════════════════

    /**
     * Reduce the collection to a single value.
     *
     * @param callable $callback fn(mixed $carry, Model $model): mixed
     * @param mixed    $initial  Starting accumulator value
     *
     * @return mixed
     */
    public function reduce(callable $callback, mixed $initial = null): mixed
    {
        return array_reduce($this->items, $callback, $initial);
    }

    /**
     * Sum an attribute across all models.
     *
     * @param string|callable $attribute Attribute name or callback fn(Model): numeric
     *
     * @return int|float
     */
    public function sum(string|callable $attribute): int|float
    {
        $extractor = is_callable($attribute)
            ? $attribute
            : fn(Model $m) => $m->{$attribute};

        return array_reduce($this->items, fn($carry, $m) => $carry + $extractor($m), 0);
    }

    /**
     * Calculate the average of an attribute across all models.
     *
     * @param string|callable $attribute Attribute name or callback fn(Model): numeric
     *
     * @return int|float|null Null if collection is empty
     */
    public function avg(string|callable $attribute): int|float|null
    {
        if (empty($this->items)) {
            return null;
        }

        return $this->sum($attribute) / count($this->items);
    }

    /**
     * Get the minimum value of an attribute.
     *
     * @param string|callable $attribute Attribute name or callback fn(Model): mixed
     *
     * @return mixed Null if collection is empty
     */
    public function min(string|callable $attribute): mixed
    {
        if (empty($this->items)) {
            return null;
        }

        $extractor = is_callable($attribute)
            ? $attribute
            : fn(Model $m) => $m->{$attribute};

        return min(array_map($extractor, $this->items));
    }

    /**
     * Get the maximum value of an attribute.
     *
     * @param string|callable $attribute Attribute name or callback fn(Model): mixed
     *
     * @return mixed Null if collection is empty
     */
    public function max(string|callable $attribute): mixed
    {
        if (empty($this->items)) {
            return null;
        }

        $extractor = is_callable($attribute)
            ? $attribute
            : fn(Model $m) => $m->{$attribute};

        return max(array_map($extractor, $this->items));
    }

    // ═══════════════════════════════════════════════════════════════
    // Sorting & Grouping
    // ═══════════════════════════════════════════════════════════════

    /**
     * Sort items by an attribute or callback and return a new collection.
     *
     * @param string|callable $attribute Attribute name or callback fn(Model): mixed
     * @param string          $direction 'asc' or 'desc'
     *
     * @return static
     */
    public function sortBy(string|callable $attribute, string $direction = 'asc'): static
    {
        $extractor = is_callable($attribute)
            ? $attribute
            : fn(Model $m) => $m->{$attribute};

        $items = $this->items;
        usort($items, function ($a, $b) use ($extractor, $direction) {
            $va = $extractor($a);
            $vb = $extractor($b);

            $cmp = $va <=> $vb;

            return $direction === 'desc' ? -$cmp : $cmp;
        });

        return new static($items);
    }

    /**
     * Remove duplicate models based on an attribute or callback.
     *
     * @param string|callable $attribute Attribute name or callback fn(Model): mixed
     *
     * @return static
     */
    public function unique(string|callable $attribute): static
    {
        $extractor = is_callable($attribute)
            ? $attribute
            : fn(Model $m) => $m->{$attribute};

        $seen = [];
        $result = [];

        foreach ($this->items as $item) {
            $key = $extractor($item);
            if (!in_array($key, $seen, true)) {
                $seen[] = $key;
                $result[] = $item;
            }
        }

        return new static($result);
    }

    /**
     * Group models by an attribute or callback.
     *
     * @param string|callable $attribute Attribute name or callback fn(Model): string|int
     *
     * @return array<string|int, static> Keyed by group value
     */
    public function groupBy(string|callable $attribute): array
    {
        $extractor = is_callable($attribute)
            ? $attribute
            : fn(Model $m) => $m->{$attribute};

        $groups = [];

        foreach ($this->items as $item) {
            $key = $extractor($item);
            $groups[$key][] = $item;
        }

        return array_map(fn($items) => new static($items), $groups);
    }

    /**
     * Key the collection by an attribute value.
     *
     * @param string|callable $attribute Attribute name or callback fn(Model): string|int
     *
     * @return array<string|int, Model> Keyed by attribute value
     */
    public function keyBy(string|callable $attribute): array
    {
        $extractor = is_callable($attribute)
            ? $attribute
            : fn(Model $m) => $m->{$attribute};

        $result = [];

        foreach ($this->items as $item) {
            $result[$extractor($item)] = $item;
        }

        return $result;
    }

    // ═══════════════════════════════════════════════════════════════
    // Advanced Transformation
    // ═══════════════════════════════════════════════════════════════

    /**
     * Map each item and flatten the result by one level.
     *
     * @param callable $callback fn(Model $model): array
     *
     * @return array Flattened array of results
     */
    public function flatMap(callable $callback): array
    {
        return array_merge([], ...array_map($callback, $this->items));
    }

    /**
     * Get the first model where an attribute matches a value.
     *
     * @param string $attribute Attribute name
     * @param mixed  $value     Value to match (uses loose comparison)
     *
     * @return Model|null
     */
    public function firstWhere(string $attribute, mixed $value): ?Model
    {
        foreach ($this->items as $item) {
            if ($item->{$attribute} == $value) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Split the collection into smaller collections of the given size.
     *
     * @param int $size Maximum items per chunk
     *
     * @return array<int, static> Array of chunked collections
     */
    public function chunk(int $size): array
    {
        $chunks = array_chunk($this->items, $size);

        return array_map(fn($chunk) => new static($chunk), $chunks);
    }

    // ═══════════════════════════════════════════════════════════════
    // ArrayAccess
    // ═══════════════════════════════════════════════════════════════

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->items[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
        $this->items = array_values($this->items);
    }

    // ═══════════════════════════════════════════════════════════════
    // Countable & IteratorAggregate
    // ═══════════════════════════════════════════════════════════════

    public function count(): int
    {
        return count($this->items);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }
}
