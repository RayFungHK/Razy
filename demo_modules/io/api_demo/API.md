# io/api_demo — API

Owner: Razy Framework (see `module.php`). This file is the contract: only what is listed here is public;
change it through the owner (docs/STYLE.md §0).

This is a shipped demo module: its surface exists to teach the pattern, not for production callers.

## Commands

Call API commands with `$this->api('io/api_demo')->name(...)`.

| Name | Kind | Signature | Summary |
|---|---|---|---|
| `greet` | API command | `(see api/greet.php)` | — |
