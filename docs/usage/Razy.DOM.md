# Razy\DOM

## Summary
- Lightweight DOM builder for HTML output.

## Construction
- `new DOM($name = '', $id = '')`.

## Key methods
- `setTag($tag)`, `setText($text)`, `setVoidElement($enable)`.
- `setAttribute()`, `removeAttribute()`, `hasAttribute()`.
- `setDataset()`, `addClass()`, `removeClass()`.
- `append($dom)`, `prepend($dom)`.
- `saveHTML()`: render to HTML.

## Usage notes
- `__toString()` calls `saveHTML()`.
