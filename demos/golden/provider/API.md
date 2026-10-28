# golden/provider — API

Owner: Razy Framework (see `module.php`). This file is the contract: only what is listed here is public;
change it through the owner (docs/STYLE.md §0).

This is a golden-path demo: it teaches the pattern and is linted with the same bar as first-party modules.

## Commands

Call with `$this->api('golden/provider')->findUser($id)`. `__onAPICall` admits only commands on its
`API_ALLOW` list (RZ-010).

| Name | Kind | Signature | Returns |
|---|---|---|---|
| `findUser` | API command | `(int $id = 0): array` | `['found' => bool, 'user' => ?array, 'source' => 'golden/provider::findUser']` from static demo data; no database, no superglobals |

## Events

- `userSeen` — fired as `golden/provider:userSeen` after each `findUser` call, payload
  `['user_id' => int, 'found' => bool]`. Listen with `$agent->listen('golden/provider:userSeen', ...)`.
