# Razy\Distributor

## Summary
- Loads modules for a site/distributor and performs routing.
- Registers module API endpoints, events, and CLI scripts.

## Construction
- Created by `Domain::matchQuery()`.

## Key methods
- `initialize($initialOnly)`: scan modules and run lifecycle phases.
- `matchRoute()`: resolve routes or CLI scripts and execute closures.
- `setRoute()`, `setLazyRoute()`, `setShadowRoute()`, `setScript()`.
- `createAPI()`, `createEmitter()`.
- `getLoadedModule()`, `getLoadedAPIModule()`.
- `compose(callable $closure)`: package manager integration.

## Usage notes
- Routing is driven by lazy and regex routes, sorted by path depth.
- `await` callbacks run before `__onReady()`.
