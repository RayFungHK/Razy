# Razy\XHR

## Summary
- JSON response builder with CORS and CORP headers.

## Construction
- `new XHR($returnAsArray = false)`.

## Key methods
- `data($dataset)`, `set($name, $value)`.
- `allowOrigin($origin)`, `corp($type)`.
- `send($success, $message)`: output or return array.
- `onComplete($closure)`: callback after output.

## Usage notes
- `send()` exits after output when not returning array.
