# 12 — Module Lifecycle: Readiness, Provisioning, the Wizard Door

*Code-verified against v1.1.0-beta.2+ (built 2026-09). Doctrine source:
[`architecture/MODULE-LIFECYCLE.md`](../architecture/MODULE-LIFECYCLE.md) — maintainer sign-off
2026-09-17, Q1–Q6 all approved.*

This chapter replaces three pieces of folklore that every Razy app used to hand-roll:

| Folklore | Replaced by |
|---|---|
| a stored `installed` flag per module | **derived readiness** — `moduleReady()`, answered from the migration ledger |
| per-handler "is the module ready?" whitelists on every route | a **route-level `ready` gate** the dispatcher enforces once |
| a bespoke install wizard per product | one **framework wizard runner**, CLI-token-gated, for modules that *declare* it |

The prime directive behind all of it: **state is derived, never stored**. A flag that can drift
from the ledger is a bug already written.

---

## 1. Readiness is a fact, not a checkbox

```php
// Distributor.php:756 — ready := "every migration I declare is applied", derived
$ready = $distributor->moduleReady('vendor/module');
```

What the predicate checks, in order:

1. **Positive status whitelist** — only a module in `InQueue` or `Loaded` can answer. This is
   deliberately *not* a "bad states" blacklist: a module blocked mid-`require` after
   `standby()` sits in `Processing`, which exclusion lists let through (caught live in the L2
   dogfood run — the status table showed `READY=yes` next to a boot warning naming the same
   module as NOT loaded).
2. **No `migration/` directory ⇒ vacuously ready** — nothing declared, nothing to wait for;
   zero database touch.
3. **The ledger answers** — `MigrationManager::isUpToDate()`
   (`Database/MigrationManager.php:375`): the M4 manifest fast path first (O(1) on a deployed
   module), then a full pending check. An unreachable ledger **throws** with a named cause
   (`no config-connect database declared`, `database connection refused`, …) — a quiet `false`
   here is exactly how six hand-rolled `$installed` flags were born in the wild.

Results are memoized per-process only (`Distributor.php:758`); there is no cache to invalidate
because there is no stored state to invalidate.

From a controller, the same door:

```php
// Controller.php:783 (via Module.php:234) — internal logic branching ONLY.
if ($this->moduleReady('vendor/ledger')) { /* offer the full view */ }
```

Request **admission** does not belong in handler bodies — that is what §3's gate does, in the
framework, once.

> Checksum **drift** is deliberately *not* folded into readiness. Drift is the
> `migrate --status` deploy gate's fail-loud business, not a per-request question.

---

## 2. `provision` — who is allowed to run my schema

`package.php` gained one key (`ModuleInfo` closed key-set; unknown keys fail `validate`):

```php
return [
    'name' => 'Billing',
    // ...
    'migration' => 'deploy',        // M3: what the schema IS (deploy-time applied)
    'provision' => 'deploy',        // Q2: WHO may apply it — the DEFAULT, web never migrates
];
```

| Value | Meaning | Migration door |
|---|---|---|
| `deploy` (default) | operator product; schema moves at deploy | CLI `php Razy.phar migrate <dist>` only |
| `wizard` | end-user product; first-run bootstrap | CLI **plus** the declared wizard door (§5), token-gated |
| `none` | module ships no schema at all | nothing to open |

Rails: an unknown value degrades to `deploy` at runtime **and** is flagged by
`php Razy.phar validate` (a typo'd `wizard` must never sit silently in a manifest — its value
decides whether a web door exists). Declaring `wizard` without a `migration/` directory is a
validate **error** — a door with nothing behind it is a declared lie. `razymod/permissions`
declares `wizard` (login-foundation chicken-and-egg is the target shape);
`razymod/oauth` and `razymod/queue-admin` declare `none` — declaring absence beats guessing.

---

## 3. The route gate — not-ready never means 404, never means a crash

```php
// Route.php:213 — one route, gated on a peer:
$agent->addLazyRoute('report', (new Route('report_main'))->ready('vendor/ledger'));

// Agent.php:305 — the whole module, gated on itself:
$agent->readyRoutes('self');           // call BEFORE registering routes
$agent->addLazyRoute('dashboard', 'main');   // plain paths get entity-ized only when gated
```

`ready('self')` resolves to the owning module at the door (`RouteDispatcher.php:666`); an
explicit per-route gate always wins over the module-wide default. CLI `Script` routes are
never gated. The gate runs before the executor is even resolved, and the route-free majority
pays one `null` coalesce for the feature.

When the gate fails, the **gated module's declared provision** picks the answer
(`RouteDispatcher.php:721`):

- `deploy` (or `none`) → **503**, naming the module and the exact fix:
  `php Razy.phar migrate <dist>`; JSON (`error: module-not-ready`, `module`, `fix`) for XHR
  (`X-Requested-With: XMLHttpRequest` or `Accept: application/json`); `Retry-After: 60`.
- `wizard` → **302** into `/__setup/<urlencoded-code>` (§5).

Readiness is re-derived on the request that hits the gate — apply the migrations and the very
next request passes. There is no "reboot to take effect".

**Why the gate is explicit opt-in.** A module that declares migrations is *not* auto-gated:
auto-gating would 503 every existing declared-migration module with a pending ledger the
moment this ships — a silent BC break against the operators the ledger informs. Migration
presence is a fact; gating is the module author's policy, and they declare it here.

The dispatcher answers through a probe seam; the Distributor wires it to `moduleReady()`
(`Distributor.php:971`) — **one readiness policy, one door**. A gate declared while the
probe is missing is a framework wiring bug: `E_USER_WARNING` loud, refused closed.

---

## 4. Operator surface — `php Razy.phar module …`

| Verb | What it does |
|---|---|
| `module status <dist>` | derived table: MODULE, VERSION, PROVISION (with `!` on declared-mismatch), ENABLED, PENDING, READY. **Exit non-zero** when anything is pending or its ledger is UNREACHABLE — the deploy gate refuses what it cannot see. |
| `module enable <dist> <code>` | writes `config/<dist>/modules.php` (`false` entry removed). Ghost names (no such module) are refused, never written. |
| `module disable <dist> <code>` | writes `false` into the enable-list. Refuses when a live module `require`s the target — dependents named — unless `--force`. Prints the Q4 rail at the moment of temptation: *disable never drops schema or rows.* |
| `module wizard-token <dist> <code>` | mints the single-use setup token (§5). The ONLY minter. |

There is deliberately **no `install` / `uninstall` verb**: schema moves at `migrate` (or the
declared wizard door), and uninstall-drop is outside the doctrine.

The enable-list is git-tracked config, read at boot: absent file = every module enabled; an
explicit `false` disables before init (a disabled module answers `Disabled` status, is skipped
by `require`, and its routes are absent from dispatch). Takes effect next boot.

---

## 5. The wizard door — the shell proves the operator

For `provision => 'wizard'` modules only, first-run setup no longer needs an SSH dependency
**or** a framework user row:

```
operator (shell)                browser                      framework
────────────────                ────────                     ─────────
php Razy.phar module \
  wizard-token appdemo \
  erp/holiday          ──prints──▶ (pasted into form) ──POST──▶ /__setup/erp%2Fholiday
                                                             verify HMAC, dist+module binding,
[TOKEN] eyJ...                                                 10-min window
    │                                                            ▼
    └── audit line ───────────────────────────────────────────  redeem nonce (SPENT, forever)
                                                                  ▼
                                                                  ModuleDatabaseConnector
                                                                  + MigrationManager::migrate()
                                                                  (prefix: wizard_runner)
                                                                  ▼
                                                                  module.installed {via: wizard}
```

The rails (`Security/Wizard/WizardTokenSigner.php`, `Setup/WizardRunner.php`):

- **The token is the whole auth.** Signed (`HMAC-SHA256`, secret `RAZY_WIZARD_TOKEN_SECRET` in
  env — blank secret throws), bound to one dist + one module, 10-minute window
  (`WizardTokenSigner::DEFAULT_TTL`), constant-time verified before any parse.
- **Single-use is physical, not advisory**: the nonce lives in the framework cache and is
  deleted on spend. An unconfigured cache (`NullAdapter`) is refused **at mint** — minting a
  token that could never be spent is a lie printed in green.
- **Spent before any work**: if the migration then fails, a retry needs a *fresh* mint. A
  leaked response can never re-run the door.
- **The runner is the same engine, not a second one**: migrations go through
  `ModuleDatabaseConnector` + `MigrationManager` — the wizard is a delivery channel.
- **Minting refuses, loudly** (`exit 1`): non-wizard provisions, unknown modules,
  wizard-declared-but-no-migrations, unreachable ledgers. Nothing pending → `[SKIP]`, no token.
- `/__setup/…` is intercepted **before session, module lifecycle, and the route table**, in
  both dispatch channels (`Distributor.php:792` and friends) — no module alias can ever
  impersonate it; the runner refuses to exist under `CLI_MODE`.
- Every mint, spend, and refusal writes one `[Razy][wizard][<dist>]` audit line to the error
  log. The framework owns no user row and learns no accounts, ever.

The runner has **no step registry** — deliberately. Seeding steps are product code listening
to `module.installed` (§6); the framework ships the door, not the product's checklist.

---

## 6. `module.installed` — an event that means what it says

Fires **only where migrations actually ran** — exactly two places:

- the CLI door, and only on a **non-empty apply** (`terminal/migrate.inc.php` — an up-to-date
  pass fires nothing; the module was installed before);
- the wizard POST door, after its migrate.

```php
// a peer's controller — seed YOUR OWN data off a peer's install:
$this->listen('module.installed', function ($data) {
    // $data: ['module' => 'vendor/ledger', 'version' => '1.2.0', 'via' => 'cli'|'wizard', 'applied' => 3]
    if ($data['module'] === 'vendor/ledger') { $this->seedMyOwnDefaults(); }
});
```

What is **refused by doctrine**: reacting to a peer by migrating the peer. Schema never moves
in reaction to an event; `module.ready` is not an event at all — readiness is a question you
ask (§1), not a thing that happens to you.

---

## 7. Rules the lint now shows you

- **RZ-016** — `getMigrationManager()` inside a handler = migration smuggled into the web
  process. The doors are the CLI and (by declaration) the wizard runner; nothing else.
- **RZ-017** — importing a sibling module's class (`use erp\user\Helper;` or its FQCN). The
  readiness gate answers "is the peer usable?" honestly; call it through `api()`/events.
- Zombie manifest keys (`required`, `type`, `label`, …) fail `validate` with a nearest-name
  suggestion — the `required`/`require` near-miss that silently disabled whole dependency
  graphs is now a loud error. Dependency key is `require` (singular), always.

Run them the way CI does:

```bash
php tools/lint-module-discipline.php sites/<dist> modules shared/module --format=json
php Razy.phar validate <dist>
```

---

## 8. Migrating an old app off the folklore

The ERP appendix in [`architecture/MODULE-LIFECYCLE.md`](../architecture/MODULE-LIFECYCLE.md)
documents the concrete shapes (38 `registerInstall` sites with 5 colliding order numbers, 15
whitelist copies, 6 stored flags, one dead-code existence guard). The substitution table:

| Old shape | New shape |
|---|---|
| `if (!$this->installed)` guard in every handler | `$agent->readyRoutes('self')` (or per-route `->ready(...)`) — the dispatcher answers |
| stored `installed` flag + manual sync | `moduleReady()` / the gate — derived from the ledger every request |
| per-product install wizard + `usort($steps)` | `provision => 'wizard'` + `module wizard-token`; seeding moves to a `module.installed` listener |
| `ensureSchema()` on boot (RZ-016) | declare the migration; run it at the CLI door (or the wizard door) |
| root-route hijack "please install X" | the 503/302 gate — the module that must exist says so itself, and the fix command is printed |
| `class_exists` of a peer's class | `api('vendor/mod')->has('cmd')` — never `method_exists` on an Emitter (RZ-017) |

Milestone order that actually worked building this framework feature: L0 fix the dependency
mechanics (`require` warnings, key audits) → L1 derive readiness → L2 operator surface →
L3 the gate → L4 the door → L5 the docs you are reading. Each shipped green; the two real
bugs the dogfood caught (a blacklist that let `Processing` through; a dead `required` key that
had silently disabled a dependency graph) are pinned by tests, not folklore.

## 9. Tutorial — a module's first run, end to end

A walkthrough you can type. Every expected output below was taken from an actual run (the
playground `appdemo` site, `demo/livedemo` + `demo/firstrun` as the teaching modules — swap
in your own module codes anywhere).

### 0 · What you need

A distributor with a working `php Razy.phar module status <dist>` (if that exits 0 already,
good — the tutorial's first half works regardless), and a MySQL the module may declare. No
MySQL on hand? Everything still works — the failures ARE half the lesson (§"reading the
honest failures").

### 1 · Declare, don't install

A module that owns schema is three declarations and a migration file:

```text
sites/<dist>/shop/orders/
├── module.php                    # module_code => 'shop/orders'
└── default/
    ├── package.php               # name/version + the two lifecycle keys
    ├── controller/orders.php     # __onInit registers routes
    └── migration/
        └── 2026_09_20_120000_CreateOrdersTables.php
```

```php
// default/package.php — the lifecycle half
return [
    'name' => 'Orders',
    'version' => '1.0.0',
    'migration' => 'deploy',   // schema ships with deploys (bulk-safe door)
    'provision' => 'wizard',   // first-run setup may ALSO run through the token door
];
```

`migration => 'deploy'` answers *how the schema lands*; `provision => 'wizard'` answers
*who may run first-run steps*. There is no `installed` flag anywhere in these files — that
is the point (§1). A `provision => 'wizard'` that ships no `migration/` directory is a
`validate` **error** (§7) — the declaration must mean something.

### 2 · Gate the routes you expose

```php
// default/controller/orders.php
use Razy\Agent;
use Razy\Controller;
use Razy\Route;

return new class () extends Controller {
    public function __onInit(Agent $agent): bool
    {
        $agent->readyRoutes('self');                      // module-wide default
        $agent->addLazyRoute('list', 'list');             // now gated on self
        $agent->addLazyRoute('ping', 'ping');             // scripts never gate
        $agent->addLazyRoute('audit', (new Route('audit'))->ready('vendor/logger')); // peer gate
        return true;
    }
};
```

`readyRoutes('self')` is the one-line version of "every route handler starts with an
installed check" — except the dispatcher answers before your closure runs, and the answer
comes from the ledger, not a stored flag. An ungated route is a deliberate statement, not
an oversight (a `/ping` that must answer even while pending is a legitimate ungated route).

### 3 · Declare the database — one door, one shape

`config/<dist>/orders.php`:

```php
return [
    'database' => [
        'type' => 'mysql',
        'connection' => [
            'host' => '127.0.0.1', 'port' => 3306,
            'user' => '…', 'password' => '…', 'database' => '…',
        ],
    ],
];
```

This ONE declaration is what `migrate`, the readiness gate, and the wizard runner all
resolve through (`ModuleDatabaseConnector`). There is no second engine and no ambient-DB
fallback — misdeclare it and every door tells you so.

### 4 · Ask, don't guess: the operator verbs

```bash
php Razy.phar module status <dist>            # READY column per module, non-zero exit if any not-ready
php Razy.phar migrate <dist> --status         # ledger view: applied vs pending per module
php Razy.phar module enable <dist> shop/orders
php Razy.phar module disable <dist> shop/orders   # flag flip only — data untouched
```

A module that declares nothing shows `PENDING -` / `READY yes` — **vacuous** readiness, zero
DB touched. A module whose ledger is unreachable shows `PENDING ???` / `READY UNREACHABLE`
with the named cause and exit 1:

```text
  MODULE                       VERSION   PROVISION ENABLED  PENDING  READY
  demo/firstrun                default   wizard   yes      ???      UNREACHABLE
    Module 'demo/firstrun': database connection refused
```

Reading the honest failures — real outputs, all exit 1, all naming the fix:

| You see | It means |
|---|---|
| `no config-connect database declared` | §3's file is missing or misshapen |
| `database connection refused` | declared DB unreachable — check server/credentials |
| `Ledger unreachable, refusing to mint: …` | `wizard-token` will NOT hand out a token it cannot verify pending>0 for |
| `[SKIP] ... nothing to apply` (exit 0) | pending==0 — a token would be spent on nothing |
| `PENDING -` / `READY yes` | vacuous truth: no migrations declared, nothing to wait for |

### 5 · Deploy the schema (the door migrations run behind)

```bash
php Razy.phar migrate <dist> shop/orders      # applies pending in file order
```

Non-empty apply is the ONLY thing that fires `module.installed` (payload includes
`via: cli`) — seeding lives in your listener (§6), never in a step registry. Re-run the
same command: `[SKIP]`, exit 0, nothing fires. The command is idempotent; your listener
is what made the install mean something.

### 6 · Or let the web finish it: the wizard door

For a `provision => 'wizard'` module whose routes a visitor just hit — the gate answered
`302 /__setup/shop%2Forders`. Nobody can run schema from that page: the operator mints a
one-time token on the shell:

```bash
php Razy.phar module wizard-token <dist> shop/orders
# [TOKEN] b64url(…).b64url(HMAC…)  (valid 600s, single-use, bound to this dist+module)
```

Paste it into the page's form, POST runs `migrate` through the same door as CLI, fires
`module.installed` with `via: wizard`, and prints the applied count. Wrong/expired token →
403, the attempt is audited (`[Razy][wizard][<dist>] …`); a spent token stays spent even if
the migration then fails — retry with a fresh mint. The framework never touches accounts:
there is no login at this door because there is no user row to log into yet.

### 7 · Watch the gate answer live

```bash
php -S 127.0.0.1:8093 router.php          # from the site root; NOTE: after ANY Razy.phar
                                          # swap RESTART this server — it keeps the old
                                          # phar loaded in-process
curl http://127.0.0.1:8093/<dist>/orders/list/
# pending  → 503, names shop/orders + the exact migrate command
curl -H 'X-Requested-With: XMLHttpRequest' …/<dist>/orders/list/
# pending  → {"error":"module-not-ready","module":…,"fix":"php Razy.phar migrate <dist> …"}
curl …/<dist>/orders/audit/               # peer gated on a wizard module
# not ready → 302, Location: …/__setup/vendor%2Flogger
```

`php Razy.phar module disable <dist> vendor/logger` and the peer route flips to the 503
without touching logger's files; `enable` again and the very next request passes — the
answer is derived per request, so there is nothing to resync. (Route keys are alias-based —
`/orders/…`, not `/shop/orders/…`: the vendor segment is not part of the URL.)

### 8 · What you now own

- readiness you never store and can never desync;
- schema that lands at exactly two doors, both auditable;
- an install event that means migrations ran — not "the wizard finished";
- a `module.php`-clean upgrade path: `validate <dist>` + `module status <dist>` tell you,
  at deploy time, what the old checkbox folklore hid until the first request crashed.
