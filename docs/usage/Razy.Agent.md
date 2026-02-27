# Razy\Agent

## Summary
- Controller helper to register routes, scripts, API commands, and events.
- Acts as the module's configuration API during `__onInit` and `__onLoad`.

## Construction
- Created internally by `Module` and passed to controller hooks.

## Key methods
- `addAPICommand($command, ?string $path)`: register API callable(s). Use `#` prefix for internal binding.
- `listen($event, $path): bool|array`: register event listeners. Returns `true` if target module loaded, `false` otherwise. For array of events, returns array of bools.
- `addLazyRoute($route, $path)`: nested array or string lazy routes. Use `@self` for current level. Supports HTTP method prefixes on keys (e.g. `'POST api'`); parent method is inherited by children unless overridden.
- `addRoute($route, $path)`: regex routes, `Route` objects supported.
- `addScript($route, $path)`: CLI script routes.
- `addShadowRoute($route, $moduleCode, $path)`: route proxy to other module. Cannot shadow to self.
- `await($moduleCode, callable $caller)`: defer until modules are ready.

## Usage notes
- Use inside `Controller::__onInit()` to configure module behavior.
- `addRoute()` supports tokens like `:a`, `:d`, `:w`, etc.
- `listen()` always registers the listener regardless of return value.
- See [ADVANCED-FEATURES-EXAMPLES.md](ADVANCED-FEATURES-EXAMPLES.md) for detailed examples.

