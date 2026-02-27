# Razy\Error

## Summary
- Framework exception with web/CLI rendering helpers.
- Supports debug console output and templated error pages.

## Construction
- `new Error($message, $statusCode, $heading, $debugMessage)`.

## Key methods
- `Show404()`: output 404 page/CLI message and exit.
- `ShowException(Throwable $e)`: render templated error page or echo.
- `SetDebug(bool $enable)`: toggle debug output.
- `DebugConsoleWrite(string $message)`: append debug console output.

## Usage notes
- In web mode, renders templates under `asset/exception/`.
