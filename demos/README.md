# demos/ — Golden-Path Razy Demo Modules

These modules are the **patterns AI agents and humans must copy**. They exist
because the bundled `demo_modules/` are *not* safe to copy: static analysis with
`tools/lint-module-discipline.php` found **15 input-superglobal reads** and
**97 raw-output template warnings** across them — and generated code inherits
whatever the demos teach.

Everything here is **lint-clean by construction**: `errors: 0, warnings: 0`.
Every API call was verified against `src/library/Razy/` (AGENTS.md prime
directive #1: *code beats docs*) — citations are inline, file:line.

## What's here

```
demos/
├── README.md                  ← this file
├── anti-patterns.md           ← ❌/✅ gallery for RZ-001/002/003/004/005/008/011
└── golden/
    ├── provider/              ← module_code golden/provider, api_name provider_api
    │   ├── module.php
    │   └── default/
    │       ├── package.php
    │       └── controller/
    │           ├── provider.php           main controller: routes + API + gate (RZ-010)
    │           ├── provider.users.php     lazy-route handler: getRoutedInfo + trigger + xhr
    │           └── api/
    │               └── find_user.php      public API closure (typed params, return-value out)
    └── consumer/              ← module_code golden/consumer
        ├── module.php
        └── default/
            ├── package.php                require => golden/provider (RZ-007)
            └── controller/
                ├── consumer.php           register-only __onInit + event listener (RZ-008)
                └── consumer.panel.php     api('golden/provider')->findUser() (RZ-001)
```

## Install into a distributor

Copy each module directory (the `golden/` vendor segment included) under your
distributor's module tree:

```
sites/<dist>/vendor/module/golden/provider/    ← copy of demos/golden/provider/
sites/<dist>/vendor/module/golden/consumer/    ← copy of demos/golden/consumer/
```

Register both in `sites/<dist>/dist.php` (modules list), then verify:

```bash
php Razy.phar validate <dist>      # resolves require edges, checks closure files
php Razy.phar compose  <dist>      # only if Composer 'prerequisite' deps are ever added
```

Hit the demo surfaces (lazy routes are relative to each module's alias):

- `GET /<consumer-alias>/panel/2` → JSON assembled **through the provider's API**
- `GET /<provider-alias>/users/1` → provider's own handler; also fires the
  `golden/provider:userSeen` event (response `watchers: 1` proves the consumer
  heard it — over events, not shared state)

## Run the lint (must stay at zero)

```bash
php tools/lint-module-discipline.php demos --format=json
# expected: "errors": 0, "warnings": 0

php tools/lint-module-discipline.php demos --strict   # zero-warnings bar, enforced
php tools/lint-module-discipline.php --self-test      # lint tool's own fixtures
```

`demos/*.md` files are not scanned (the lint walks `.php`/`.tpl` trees only),
which is why every ❌ snippet lives **only inside markdown fences** in
`anti-patterns.md` — bad code never exists as a `.php` file an agent could copy
and lint-pass.

## The golden path in 30 seconds

| Boundary crossing | Sanctioned pattern (all verified in src/) |
|---|---|
| Module → module, request/response | `$agent->addAPICommand('findUser', 'api/find_user')` + caller `$this->api('golden/provider')->findUser($id)`; gate with `__onAPICall` (RZ-001/RZ-010) — `api()` is **nullable** (`Controller.php:510`), check it |
| Module → module, broadcast | provider: bare `$this->trigger('userSeen')->resolve($payload)` (`Controller.php:339`, `EventEmitter.php:72-73` auto-qualifies the emitter's code); consumer: `$agent->listen('golden/provider:userSeen', …)` (`Agent.php:152-181`) (RZ-008) |
| Data in | typed closure parameters + `getRoutedInfo()['arguments']` — never `$_GET`/`$_POST` (RZ-003) |
| Data out | `$this->xhr()->responseAsBody([...])` (`XHR.php:185`) — API closures return values, never echo |
| Dependency on a peer | `'require' => ['golden/provider' => '>=1.0.0']` in `package.php` (`ModuleInfo.php:250-256`) + `validate`/`compose` (RZ-007) |
| File layout | metadata `module.php` + version dir `default/package.php` + `default/controller/…`; **all closures live under `controller/`**; slash-less paths get the class-name prefix (`ClosureLoader.php:126`) → `provider.users.php`, not `users.php`; extension is appended by the loader (`ClosureLoader.php:130`) → register `'api/find_user'`, **not** `'api/find_user.php'` |

## Documented deviations (where an original spec/doc said X, the code says Y)

Verified per AGENTS.md prime directive #1; demos follow **code**:

1. **API closure location** — `controller/api/find_user.php`, not `default/api/find_user.php`: the loader resolves every closure path under `controller/` (`ClosureLoader.php:130`). The on-disk `demo_modules/io/api_provider/` does the same.
2. **No `.php` in closure paths** — `addAPICommand('findUser', 'api/find_user')`: the loader appends `.php` (`ClosureLoader.php:130`).
3. **Slash-less handler files carry the class prefix** — `provider.users.php` / `consumer.panel.php` (`ClosureLoader.php:126`; matches on-disk `route_demo.user.php`).
4. **`__onAPICall` real signature** — `(ModuleInfo $module, string $method, string $fqdn = '')` with `$module` = the **requesting** module (`Controller.php:173`, `CommandRegistry.php:113,121-129`); the rules-pack Appendix D snippet's two-string form is stale (the pack itself declares code wins).
5. **`trigger()` lives in the route handler, not `__onInit`** — `__onInit` is registration-only (RZ-009): firing there would run before peers can register listeners.

## Related

- Rules pack: [`../skills/RAZY-AI-RULES.md`](../skills/RAZY-AI-RULES.md) (rule IDs RZ-xxx)
- Agent contract: [`../AGENTS.md`](../AGENTS.md)
- Lint: [`../tools/lint-module-discipline.php`](../tools/lint-module-discipline.php)
- ❌→✅ gallery: [anti-patterns.md](anti-patterns.md)
