# Razy\Module

## Summary
- Runtime module instance loaded by a `Distributor`.
- Owns controller instance, routing, API commands, and event listeners.

## Construction
- Created by `Distributor` during module scan.

## Key methods
- `initialize()`: load controller and run `__onInit`.
- `prepare()`: run `__onLoad` and set status.
- `validate()`: run `__onRequire` and dependency validation.
- `addRoute()`, `addLazyRoute()`, `addScript()`, `addShadowRoute()`.
- `addAPICommand()`: use `#` prefix for internal binding (callable as `$this->methodName()`).
- `execute()`: execute API command.
- `bind($method, $path)`: manually bind closure to method.
- `getBinding($method)`: get bound closure path.
- `listen($event, $path): bool`: register event listener. Returns `true` if target module loaded.
- `createEmitter($event): EventEmitter`: create emitter to fire events.
- `fireEvent()`: dispatch event to listeners.
- `getEmitter()`: API access to other modules.
- `getClosure()`: resolve controller closures by path.
- `getModuleInfo()`, `getModuleURL()`, `getSiteURL()`.

## Usage notes
- Controller closures live under `controller/` with naming conventions.
- Module status drives routing and API availability.
- Use `Controller::trigger()` instead of `Module::createEmitter()` from controllers.
- See [ADVANCED-FEATURES-EXAMPLES.md](ADVANCED-FEATURES-EXAMPLES.md) for `#` prefix and binding examples.
