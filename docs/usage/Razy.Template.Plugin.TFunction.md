# Razy\Template\Plugin\TFunction

## Summary
- Base class for template function plugins with parsed parameters.

## Construction
- Instantiated by plugin loader, bound to `Controller`.

## Key methods
- `parse(Entity $entity, string $syntax, string $wrappedText)`.
- `isEncloseContent()`: whether plugin wraps content.
- `setName()`.

## Usage notes
- Override `processor()` to implement tag behavior.
- Supports argument parsing and allowed parameters.
