# Razy\Collection

## Summary
- ArrayObject wrapper with selector-based filtering and processors.

## Construction
- `new Collection($data)`.

## Key methods
- `__invoke($filter)`: filter and return `Collection\Processor`.
- `array()`: export as plain array.
- `loadPlugin($type, $name)`: filter/processor plugin loader.

## Usage notes
- Filter syntax supports selectors and `:filter()` functions.
