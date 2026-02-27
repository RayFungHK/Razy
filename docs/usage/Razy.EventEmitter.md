# Razy\EventEmitter

## Summary
- Event dispatch helper for inter-module communication.
- Collects listener responses and optional callbacks.

## Construction
- Created by `Distributor::createEmitter()`.

## Key methods
- `resolve(...$args)`: fire event across modules.
- `getAllResponse()`: list responses from listeners.

## Usage notes
- Events are named `module:event` and registered via `Agent::listen()`.
