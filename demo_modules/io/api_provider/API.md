# io/api_provider — API

Owner: Razy Framework (see `module.php`). This file is the contract: only what is listed here is public;
change it through the owner (docs/STYLE.md §0).

This is a shipped demo module: its surface exists to teach the pattern, not for production callers.

## Commands

Call API commands with `$this->api('io/api_provider')->name(...)`.

| Name | Kind | Signature | Summary |
|---|---|---|---|
| `greet` | API command | `(string $name = 'Guest', string $language = 'en'): array` | Greet API Command |
| `calculate` | API command | `(string $operation, float $a, float $b): array` | Calculate API Command |
| `user` | API command | `(string $action, int $id = 0, array $data = []): array` | User API Command |
| `config` | API command | `(string $section = 'all'): array` | Config API Command |
| `transform` | API command | `(string $type, mixed $input): array` | Transform API Command |
