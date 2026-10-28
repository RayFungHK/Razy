# bridge/provider — API

Owner: Razy Framework (see `module.php`). This file is the contract: only what is listed here is public;
change it through the owner (docs/STYLE.md §0).

This is a shipped demo module: its surface exists to teach the pattern, not for production callers.

## Gate

`__onBridgeCall` admits every distributor and every command: this demo shows the shape only. A real bridge
provider must allow-list callers and commands there (RZ-002).

## Commands

Bridge commands serve other distributors through the gated bridge (RZ-002).

| Name | Kind | Signature | Returns |
|---|---|---|---|
| `getData` | bridge command | `(string $category = 'general'): array` | `['success' => true, 'source', 'category', 'data', 'timestamp']`; `data` is the static demo set for `general`, `products` or `users` (unknown → `general`) |
| `getConfig` | bridge command | `(): array` | `['success' => true, 'distributor' => ['code', 'tag', 'identifier'], 'module' => ['code', 'version'], 'environment' => [...]]` |
| `calculate` | bridge command | `(string $operation, float $a, float $b): array` | `['success' => bool, 'source', 'operation', 'operands' => ['a', 'b'], 'result' => ?float, 'error' => ?string, 'timestamp']`; `success` is false for an unknown operation or a division by zero |
