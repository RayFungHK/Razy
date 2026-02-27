# Razy\FlowManager\Flow

## Summary
- Base class for flow nodes in a FlowManager chain.

## Construction
- Instantiated via FlowManager plugin loader.

## Key methods
- `init($flowType, $identifier)`: set flow metadata.
- `resolve(...$args)`: mark flow resolved.
- `next($type, ...$args)`: create child flow.
- `connect($parent)`, `eject()`, `join()`, `detach()`.
- `transmit(...$args)`: forward to children.

## Usage notes
- Override `request()` to control flow joining.
- Override `resolve()` to implement validation logic.
