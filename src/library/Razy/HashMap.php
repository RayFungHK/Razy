<?php

namespace Razy;

use ArrayAccess;
use ArrayObject;
use Countable;
use Generator;
use Iterator;

class HashMap implements ArrayAccess, Iterator, Countable
{
    private array $hashOrder = [];
    private array $hashMap = [];
    private int $position = 0;

    public function __construct(array $hashMap = [])
    {
        foreach ($hashMap as $hash => $value) {
            $this->push($value, $hash ?? '');
        }
    }

    /**
     * @param mixed $object
     * @param string $hash
     * @return $this
     */
    public function push(mixed $object, string $hash = ''): static
    {
        if ($hash) {
            $hash = 'c:' . $hash;
        } else {
            if (is_object($object)) {
                $hash = 'o:' . spl_object_hash($object);
            } else {
                $hash = 'i:' . guid(6);
            }
        }

        $this->hashOrder[] = $hash;
        $this->hashMap[$hash] = [
            'value' => $object,
            'index' => count($this->hashOrder) - 1,
        ];
        return $this;
    }

    /**
     * @return Generator
     */
    public function getGenerator(): Generator
    {
        for ($i = 0; $i < count($this->hashOrder); $i++) {
            // Note that $i is preserved between yields.
            yield $this->hashMap[$this->hashOrder[$i]]['value'] ?? null;
        }
    }

    /**
     * @param mixed $offset
     * @return void
     */
    public function remove(mixed $offset): void
    {
        if (is_object($offset)) {
            $hash = 'o:' . spl_object_hash($offset);
            $this->offsetUnset($hash);
        } else {
            $this->offsetUnset($offset);
        }
    }

    /**
     * @param mixed $offset
     * @return bool
     */
    public function has(mixed $offset): bool
    {
        if (is_object($offset)) {
            $hash = 'o:' . spl_object_hash($offset);
            return isset($this->hashMap[$hash]);
        } else {
            return $this->offsetExists($offset);
        }
    }

    /**
     * @return mixed
     */
    public function current(): mixed
    {
        if (isset($this->hashOrder[$this->position])) {
            return $this->hashMap[$this->hashOrder[$this->position]]['value'] ?? null;
        }
        return null;
    }

    /**
     * @return void
     */
    public function next(): void
    {
        ++$this->position;
    }

    /**
     * @return int
     */
    public function key(): int
    {
       return $this->position;
    }

    /**
     * @return bool
     */
    public function valid(): bool
    {
        return isset($this->hashOrder[$this->position]) && isset($this->hashMap[$this->hashOrder[$this->position]]);
    }

    /**
     * @return void
     */
    public function rewind(): void
    {
        $this->position = 0;
    }

    /**
     * @param mixed $offset
     * @return bool
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->hashMap[$offset]);
    }

    /**
     * @param mixed $offset
     * @return mixed
     */
    public function offsetGet(mixed $offset): mixed
    {
        return (isset($this->hashMap[$offset])) ? $this->hashMap[$offset]['value'] : null;
    }

    /**
     * @param mixed $offset
     * @param mixed $value
     * @return void
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->push($value, $offset ?? '');
    }

    /**
     * @param mixed $offset
     * @return void
     */
    public function offsetUnset(mixed $offset): void
    {
        if (isset($this->hashMap[$offset])) {
            unset($this->hashOrder[$this->hashMap[$offset]['index']]);
            unset($this->hashMap[$offset]);
        }
    }

    /**
     * @return int
     */
    public function count(): int
    {
        return count($this->hashMap);
    }
}