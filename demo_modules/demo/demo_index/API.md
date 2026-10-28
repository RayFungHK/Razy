# demo/demo_index — API

Owner: Razy Framework (see `module.php`). This file is the contract: only what is listed here is public;
change it through the owner (docs/STYLE.md §0).

This is a shipped demo module: its surface exists to teach the pattern, not for production callers.

## Commands

Call API commands with `$this->api('demo/demo_index')->name(...)`.

| Name | Kind | Signature | Summary |
|---|---|---|---|
| `header` | API command | `(string $title, string $subtitle = '', bool $showBack = true): string` | Header API - Returns shared header HTML |
| `styles` | API command | `(): string` | Styles API - Returns shared CSS styles |
| `footer` | API command | `(): string` | Footer API - Returns shared footer HTML |

## Events

- `register_demo` — fired as `demo/demo_index:register_demo`; listen with `$agent->listen('demo/demo_index:register_demo', ...)`.
