# RZ-53b: API command outcomes, a clean stdout, reserved command names

## Added
- `Module::executeChecked(ModuleInfo $module, string $command, array $args)` and
  `Module::executeInternalChecked(string $command, array $args)` (no `__onAPICall` gate, like
  `executeInternalCommand`) return `['status' => 'ok|denied|unknown|failed', 'value' => mixed, 'error' => ?Throwable]`:
  - `ok`: the handler ran; `value` is what it returned (a returned `null` is still `ok`);
  - `denied`: `__onAPICall` returned false (the handler did not run);
  - `unknown`: no such command;
  - `failed`: the handler threw (`error` is the exception; the controller's `__onError()` hook still ran first, and an
    exception the hook throws does not replace the outcome) or its closure file could not be loaded.
  The same entry points exist on `Module\CommandRegistry` (`executeChecked`, `executeInternalChecked`).
- `ErrorRenderer::useCliStream()` sends CLI exception output to a stream you pass (tests); `null` restores stderr.
- `CommandRegistry::reservedNames()` lists the names an API command cannot take.

## Changed
- In CLI mode `Error::showException()` writes the exception (and the SQL of a `QueryException`) to **stderr**, not
  stdout, and so does the diagnostic line `new Razy\Error(...)` prints in CLI mode (`ErrorRenderer::writeCli()`); a
  failing stderr is skipped without a warning. A throwing API command, the CLI `bridge` reply and any stdio server (JSON-RPC lines) keep stdout clean;
  `display_errors=stderr` no longer matters. Web mode is unchanged.
- `addAPICommand()` (and `Agent::addAPICommand()`, including the `#` form) throws `ModuleException` for a name that is a
  public method of `Razy\Controller` (`render`, `view`, `api`, `xhr`, `fork`, `resolve`, `trigger`, ...). Such a command
  used to run the controller method with the caller's arguments and never the closure. The list is read from the class,
  case-insensitively. A method declared on your own controller subclass keeps winning over the closure file, as before.

## Deprecated
- `Module::executeInternalCommand()` still returns the value or `null`, exactly as before, but `null` cannot tell
  unknown, denied, failed and a real `null` apart. Use `executeInternalChecked()`. `Module::execute()` and
  `api('vendor/mod')->cmd()` keep their mixed-result contract.

## Compatibility
- A module that registered a command named like a `Controller` method now fails at boot with a clear message; rename it
  (the closure never ran under that name).
- CLI scripts that parsed an exception from stdout must read stderr.
