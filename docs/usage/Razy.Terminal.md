# Razy\Terminal

## Summary
- CLI output helper with color/style tags and logging.

## Construction
- `new Terminal($code, ?Terminal $parent)`.

## Key methods
- `run($callback, $args, $parameters)`.
- `writeLineLogging($message, $resetStyle, $format)`.
- `saveLog($path)`: save logs.
- `getScreenWidth()`: terminal width detection.
- `Format($message)`: parse {@...} styling tags.

## Usage notes
- Used by CLI command closures in `system/terminal`.
