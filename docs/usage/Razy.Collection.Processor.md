# Razy\Collection\Processor

## Summary
- Applies processor plugins to filtered collection values.

## Construction
- Created by `Collection::__invoke()`.

## Key methods
- `__call($method, $args)`: apply processor plugin to each item.
- `get()`: return new `Collection` with processed values.
- `getArray()`: return array of processed values.
