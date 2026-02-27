# Razy\Template\Plugin\TFunctionCustom

## Summary
- Base class for function plugins that handle raw syntax.

## Construction
- Instantiated by plugin loader, bound to `Controller`.

## Key methods
- `parse(Entity $entity, string $syntax, string $wrappedText)`.
- `isEncloseContent()`.
- `setName()`.

## Usage notes
- Override `processor()` to parse syntax yourself.
