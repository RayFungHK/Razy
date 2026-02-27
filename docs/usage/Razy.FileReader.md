# Razy\FileReader

## Summary
- Sequential file reader with support for prepend/append.

## Construction
- `new FileReader($filepath)`.

## Key methods
- `fetch()`: get next line across queued files.
- `append($filepath)`, `prepend($filepath)`.

## Usage notes
- Used by the template engine to handle `INCLUDE`.
