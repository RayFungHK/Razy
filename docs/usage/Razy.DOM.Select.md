# Razy\DOM\Select

## Summary
- Convenience DOM wrapper for `<select>` with option helpers.

## Construction
- `new Select($id)`.

## Key methods
- `addOption($label, $value)`: append `<option>`.
- `applyOptions($dataset, $convertor)`.
- `setValue($value)`, `getValue()`.
- `isMultiple($enable)`.

## Usage notes
- `getValue()` reads the `selected` option attribute.
