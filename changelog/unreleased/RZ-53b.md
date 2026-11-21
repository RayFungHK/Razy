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
  failing stderr is skipped without a warning. A throwing API command, the CLI `bridge` reply and a stdio server keep
  the trace off stdout. That is all it covers. It does **not** cover:
  - `ErrorRenderer::show404()`, which still prints its 404 text to stdout in CLI mode;
  - PHP warnings, notices and deprecations: with `display_errors=1` they still go to stdout (`display_errors=0` or
    `stderr` keeps them off it, as before);
  - an uncaught throwable before the bootstrap's `set_exception_handler()`, or that handler's own fallback when
    `showException()` itself throws: PHP's display rules apply;
  - anything the command itself prints, or an `__onError()` / `__onAPICall()` override that echoes.
- Web mode keeps its page: `showException()` renders the error page whenever the process is web. Before the mode
  constants exist (an exception thrown from `config.inc.php`) only SAPI `cli` writes to stderr; any other SAPI (for
  example `php -S`) answers with the standard 500 error page (HTTP 500, error id, no message or trace in the body)
  instead of an empty 200 or, as before this change, a 200 whose body held the trace.
- `addAPICommand()` (and `Agent::addAPICommand()`, including the `#` form) and `addBridgeCommand()` (on
  `CommandRegistry`, `Module`, `Agent`, and the compiled-declaration replay) throw `ModuleException` for a name that is
  a public method of `Razy\Controller` (`render`, `view`, `api`, `xhr`, `fork`, `resolve`, `trigger`, ...). Such a
  command used to run the controller method with the caller's arguments and never the closure. The list is read from
  the class, case-insensitively. A method declared on your own controller subclass keeps winning over the closure
  file, as before.

## Deprecated
- `Module::executeInternalCommand()` still returns the value or `null`, exactly as before, but it has no gate, so `null`
  means unknown, failed or a real `null` (never denied). Use `executeInternalChecked()`. `Module::execute()` and
  `api('vendor/mod')->cmd()` keep their mixed-result contract; there `null` can also mean the `__onAPICall` gate
  denied the call, so use `executeChecked()` to tell.

## Compatibility
- A module that registered an API or bridge command named like a `Controller` method now fails at boot with a clear
  message; rename it (the closure never ran under that name).
- CLI scripts that parsed an exception from stdout must read stderr.
- On a non-`cli` SAPI, an exception thrown before the mode constants exist now answers the 500 error page.
