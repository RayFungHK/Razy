# Razy\Route

## Summary
- Wrapper for a routed closure path and optional data payload.

## Construction
- `new Route($closurePath)`.

## Key methods
- `contain($data)`: attach data to the route.
- `getData()`: retrieve attached data.
- `getClosurePath()`: normalized path.

## Usage notes
- Used with `Agent::addRoute()` to carry extra data into controller.
