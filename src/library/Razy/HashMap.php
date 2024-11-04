<?php

namespace Razy;

use ArrayAccess;
use Countable;
use Iterator;

class HashMap implements Iterator, ArrayAccess, Countable
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

    public function current(): mixed
    {
        if (isset($this->hashOrder[$this->position])) {
            return $this->hashMap[$this->hashOrder[$this->position]]['value'] ?? null;
        }
        return null;
    }

    public function next(): void
    {
        ++$this->position;
    }

    public function key(): mixed
    {
       return $this->position;
    }

    public function valid(): bool
    {
        return isset($this->hashOrder[$this->position]) && isset($this->hashMap[$this->hashOrder[$this->position]]);
    }

    public function rewind(): void
    {
        $this->position = 0;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->hashMap[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->hashMap[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->push($value, $offset ?? '');
    }

    public function offsetUnset(mixed $offset): void
    {
        if (isset($this->hashMap[$offset])) {
            unset($this->hashOrder[$this->hashMap[$offset]['index']]);
            unset($this->hashMap[$offset]);
        }
    }

    public function count(): int
    {
        return count($this->hashMap);
    }
}