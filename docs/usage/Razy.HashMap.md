# Razy\HashMap

## Summary
- Ordered map that supports object keys via hashes.
- Implements ArrayAccess, Iterator, Countable.

## Construction
- `new HashMap($items = [])`.

## Key methods
- `push($object, $hash)`, `remove($offset)`, `has($offset)`.
- `getGenerator()`: yield values in insertion order.

## Usage notes
- Object keys are stored with `spl_object_hash()`.
