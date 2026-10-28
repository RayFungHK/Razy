# {$module_code} — API

Owner: {$author}. This file is the contract: only what is listed here is public;
change it through the owner (docs/STYLE.md §0).

## Commands

<!-- START BLOCK: api_section -->
Call with `$this->api('{$module_code}')->hello($name)`.

| Name | Signature | Returns |
|---|---|---|
| `hello` | `(string $name = 'World'): string` | `'Hello, <name>!'` |
<!-- END BLOCK: api_section -->
<!-- START BLOCK: no_api_section -->
None.
<!-- END BLOCK: no_api_section -->

## Events

None.
<!-- START BLOCK: event_section -->

## Consumes

- The `app/events:on_ready` event (listened to in `__onInit`).
<!-- END BLOCK: event_section -->
