# system/helper_module — API

Owner: Razy Framework (see `module.php`). This file is the contract: only what is listed here is public;
change it through the owner (docs/STYLE.md §0).

This is a shipped demo module: its surface exists to teach the pattern, not for production callers.

## Commands

Call API commands with `$this->api('system/helper_module')->name(...)`.

| Name | Kind | Signature | Summary |
|---|---|---|---|
| `registerClient` | API command | `(string $moduleCode): bool` | Register Client API Closure |
| `getClients` | API command | `(): array` | Get Clients API Closure |
| `isReady` | API command | `(): bool` | Is Ready API Closure |
