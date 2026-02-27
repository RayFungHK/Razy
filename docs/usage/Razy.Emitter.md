# Razy\Emitter

## Summary
- Dynamic API proxy for calling module API commands.

## Construction
- Returned by `API::request()` or `Controller::api()`.

## Key methods
- `__call($method, $args)`: invoke module API command.

## Usage notes
- Returns `null` if API module is not available.
