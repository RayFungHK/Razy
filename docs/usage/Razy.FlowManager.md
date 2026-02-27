# Razy\FlowManager

## Summary
- Flow orchestration container with plugin-created `Flow` nodes.
- Provides storage and broadcast transmitters.

## Construction
- `new FlowManager()`.

## Key methods
- `start($type, ...$args)`: create and connect a Flow.
- `append(Flow $flow)`: attach an existing Flow.
- `resolve(...$args)`: resolve all flows.
- `getTransmitter()`: broadcast method calls to all flows.
- `setStorage()`, `getStorage()`.

## Usage notes
- Flow plugins are loaded via `FlowManager::AddPluginFolder()`.
