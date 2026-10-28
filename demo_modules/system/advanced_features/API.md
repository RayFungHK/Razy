# system/advanced_features — API

Owner: Razy Framework (see `module.php`). This file is the contract: only what is listed here is public;
change it through the owner (docs/STYLE.md §0).

This is a shipped demo module: its surface exists to teach the pattern, not for production callers.

## Commands

Call API commands with `$this->api('system/advanced_features')->name(...)`.

| Name | Kind | Signature | Summary |
|---|---|---|---|
| `validateInput` | API command (also bound internally: `#validateInput`) | `(array $data): bool` | Internal Validate Closure |
| `formatOutput` | API command (also bound internally: `#formatOutput`) | `(array $data): array` | Internal Format Closure |
| `logAction` | API command (also bound internally: `#logAction`) | `(string $action, array $context = []): void` | Internal Logger Closure |
| `getStatus` | API command | `(): array` | API Status Closure (Public API only) |
