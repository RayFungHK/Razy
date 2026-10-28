# golden/consumer — API

Owner: Razy Framework (see `module.php`). This file is the contract: only what is listed here is public;
change it through the owner (docs/STYLE.md §0).

This is a golden-path demo: it teaches the pattern and is linted with the same bar as first-party modules.

## Surface

None. This module publishes no API command, bridge command or event; it only serves its own routes.

## Consumes

- `golden/provider` → `findUser` (API command) and the `golden/provider:userSeen` event, through
  `api()` and `listen()` only.
