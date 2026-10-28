# razymod/oauth — API

Owner: Razy Framework (see `module.php`). This file is the contract: only what is listed here is public;
change it through the owner (docs/STYLE.md §0). A breaking change to anything below is a new major
version folder (RZ-012).

## Gate

`__onAPICall` admits only the command below.

## Commands

Call with `$this->api('razymod/oauth')->providers()`.

| Name | Signature | Returns |
|---|---|---|
| `providers` | `(): array` | The providers this distributor enabled, each `['provider' => name, 'login_url' => ...]`. Never client ids or secrets |

## Events

- `social.user_resolved` — fired as `razymod/oauth:social.user_resolved` after a successful callback, payload
  `['provider' => string, 'user' => ..., 'tokens' => ['access_token', 'refresh_token', 'expires_at'],
  'id_token_claims' => ...]`. The app persists identity from this payload; this module keeps
  no users table.
