# bridge/provider — API

Owner: Razy Framework (see `module.php`). This file is the contract: only what is listed here is public;
change it through the owner (docs/STYLE.md §0).

This is a shipped demo module: its surface exists to teach the pattern, not for production callers.

## Commands

Bridge commands serve other distributors through the gated bridge (RZ-002).

| Name | Kind | Signature | Summary |
|---|---|---|---|
| `getData` | bridge command | `(string $category = 'general'): array` | getData API Command |
| `getConfig` | bridge command | `(): array` | getConfig API Command |
| `calculate` | bridge command | `(string $operation, float $a, float $b): array` | calculate API Command |
