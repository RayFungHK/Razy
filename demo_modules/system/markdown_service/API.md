# system/markdown_service — API

Owner: Razy Framework (see `module.php`). This file is the contract: only what is listed here is public;
change it through the owner (docs/STYLE.md §0).

This is a shipped demo module: its surface exists to teach the pattern, not for production callers.

## Commands

Call API commands with `$this->api('system/markdown_service')->name(...)`.
Calls pass this module's `__onAPICall` / `__onBridgeCall` gate first.

| Name | Kind | Signature | Summary |
|---|---|---|---|
| `parse` | API command | `(string $markdown, array $options = []): array` | Markdown Parse API |
| `parseFile` | API command | `(string $filePath, array $options = []): array` | Parse Markdown File API |
| `getInfo` | API command | `(): array` | Get Markdown Service Info API |
