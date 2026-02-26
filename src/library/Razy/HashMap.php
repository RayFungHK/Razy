<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Provides a hash-based ordered map that supports iteration,
 * array access, and counting. Elements can be referenced by
 * custom string keys, object identity hashes, or auto-generated IDs.
 *
 * @package Razy
 *
 * @license MIT
 */

namespace Razy;

use ArrayAccess;
use Countable;
use Generator;
use Iterator;
use Razy\Util\StringUtil;

/**
 * An ordered hash map that maps values to unique hash keys.
 *
 * Supports three hashing strategies:
 * - Custom key: prefixed with "c:" when a string hash is explicitly provided
 * - Object identity: prefixed with "o:" using spl_object_hash for objects
 * - Auto-generated: prefixed with "i:" using a random GUID for scalar values
 *
 * Implements ArrayAccess, Iterator, and Countable for seamless
 * integration with PHP's array-like operations and foreach loops.
 *
 * @class HashMap
 */
class HashMap implements ArrayAccess, Iterator, Countable
{
    /** @var array<int, string> Ordered list of hash keys preserving insertion order */
    private array $hashOrder = [];

    /** @var array<string, array{value: mixed, index: int}> Map of hash key to value and its position index */
    private array $hashMap = [];

    /** @var int Current iterator position for the Iterator interface */
    private int $position = 0;

    /**
     * HashMap constructor.
     *
     * Initializes the map by pushing each key-value pair from the given array.
     *
     * @param array $hashMap Initial key-value pairs to populate the map
     */
    public function __construct(array $hashMap = [])
    {
        foreach ($hashMap as $hash => $value) {
            $this->push($value, (string) $hash);
        }
    }

    /**
     * Push a value into the hash map with an optional custom key.
     *
     * If a custom hash is provided, it is prefixed with "c:". For objects
     * without a custom hash, the spl_object_hash is used (prefix "o:").
     * For scalar values without a hash, a random GUID is generated (prefix "i:").
     *
     * @param mixed $object The value to store in the map
     * @param string $hash Optional custom key; auto-generated if empty
     *
     * @return static
     */
    public function push(mixed $object, string $hash = ''): static
    {
        if ($hash) {
            // Custom key provided — prefix with "c:" to denote a custom hash
            $hash = 'c:' . $hash;
        } else {
            if (\is_object($object)) {
                // Use PHP's unique object identifier as the hash key
                $hash = 'o:' . \spl_object_hash($object);
            } else {
                // Generate a random 6-char GUID for non-object values
                $hash = 'i:' . StringUtil::guid(6);
            }
        }

        // Append the hash to the ordered list and store value with its index
        $this->hashOrder[] = $hash;
        $this->hashMap[$hash] = [
            'value' => $object,
            'index' => \count($this->hashOrder) - 1,
        ];
        return $this;
    }

    /**
     * Return a generator that yields each value in insertion order.
     *
     * Useful for lazy iteration without loading all elements into memory.
     * The loop index is preserved across yields.
     *
     * @return Generator
     */
    public function getGenerator(): Generator
    {
        for ($i = 0; $i < \count($this->hashOrder); $i++) {
            // Yield the value at the current position; $i is preserved between yields
            yield $this->hashMap[$this->hashOrder[$i]]['value'] ?? null;
        }
    }

    /**
     * Remove an element by its key or object reference.
     *
     * For objects, the spl_object_hash is used to derive the internal key.
     * For other values, the offset is used directly.
     *
     * @param mixed $offset A string key or object reference to remove
     */
    public function remove(mixed $offset): void
    {
        if (\is_object($offset)) {
            // Derive the internal hash from the object's identity
            $hash = 'o:' . \spl_object_hash($offset);
            $this->offsetUnset($hash);
        } else {
            $this->offsetUnset($offset);
        }
    }

    /**
     * Check whether a given key or object exists in the map.
     *
     * For objects, derives the internal hash via spl_object_hash.
     * For string keys, delegates to offsetExists.
     *
     * @param mixed $offset A string key or object reference to check
     *
     * @return bool True if the element exists in the map
     */
    public function has(mixed $offset): bool
    {
        if (\is_object($offset)) {
            $hash = 'o:' . \spl_object_hash($offset);
            return isset($this->hashMap[$hash]);
        }
        return $this->offsetExists($offset);
    }

    /**
     * Return the value at the current iterator position.
     *
     * Part of the Iterator interface.
     *
     * @return mixed The current value, or null if position is invalid
     */
    public function current(): mixed
    {
        if (isset($this->hashOrder[$this->position])) {
            return $this->hashMap[$this->hashOrder[$this->position]]['value'] ?? null;
        }
        return null;
    }

    /**
     * Advance the iterator to the next position.
     *
     * Part of the Iterator interface.
     */
    public function next(): void
    {
        ++$this->position;
    }

    /**
     * Return the current iterator position index.
     *
     * Part of the Iterator interface.
     *
     * @return int The current numeric position
     */
    public function key(): int
    {
        return $this->position;
    }

    /**
     * Check whether the current iterator position is valid.
     *
     * Validates that both the ordered index and the hash map entry exist.
     * Part of the Iterator interface.
     *
     * @return bool True if the current position points to a valid entry
     */
    public function valid(): bool
    {
        return isset($this->hashOrder[$this->position]) && isset($this->hashMap[$this->hashOrder[$this->position]]);
    }

    /**
     * Reset the iterator to the first position.
     *
     * Part of the Iterator interface.
     */
    public function rewind(): void
    {
        $this->position = 0;
    }

    /**
     * Check whether a hash key exists in the map.
     *
     * Part of the ArrayAccess interface.
     *
     * @param mixed $offset The hash key to check
     *
     * @return bool True if the key exists
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->hashMap[$offset]);
    }

    /**
     * Retrieve a value by its hash key.
     *
     * Part of the ArrayAccess interface.
     *
     * @param mixed $offset The hash key to look up
     *
     * @return mixed The stored value, or null if not found
     */
    public function offsetGet(mixed $offset): mixed
    {
        return (isset($this->hashMap[$offset])) ? $this->hashMap[$offset]['value'] : null;
    }

    /**
     * Set a value by hash key, delegating to push().
     *
     * Part of the ArrayAccess interface.
     *
     * @param mixed $offset The hash key (or null for auto-generated)
     * @param mixed $value The value to store
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->push($value, $offset ?? '');
    }

    /**
     * Remove an element by its hash key.
     *
     * Removes both the ordered index entry and the hash map entry.
     * Part of the ArrayAccess interface.
     *
     * @param mixed $offset The hash key to remove
     */
    public function offsetUnset(mixed $offset): void
    {
        if (isset($this->hashMap[$offset])) {
            // Remove from both the ordered index and the key-value map
            unset($this->hashOrder[$this->hashMap[$offset]['index']], $this->hashMap[$offset]);
        }
    }

    /**
     * Return the number of elements in the map.
     *
     * Part of the Countable interface.
     *
     * @return int The element count
     */
    public function count(): int
    {
        return \count($this->hashMap);
    }
}
