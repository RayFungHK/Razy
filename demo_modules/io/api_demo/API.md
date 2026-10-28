# io/api_demo — API

Owner: Razy Framework (see `module.php`). This file is the contract: only what is listed here is public;
change it through the owner (docs/STYLE.md §0).

This is a shipped demo module: its surface exists to teach the pattern, not for production callers.

## Surface

None. The `addAPICommand('greet', ...)` on this demo's page is a teaching snippet inside a string, not a
registration; the live `greet` command belongs to `io/api_provider` (see its API.md).
