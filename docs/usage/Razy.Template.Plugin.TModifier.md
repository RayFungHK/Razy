# Razy\Template\Plugin\TModifier

## Summary
- Base class for template modifier plugins.

## Construction
- Instantiated by plugin loader, bound to `Controller`.

## Key methods
- `modify($value, $paramText)`: parse args and call `process()`.
- `setName()`.

## Usage notes
- Override `process($value, ...$args)` to implement transforms.
