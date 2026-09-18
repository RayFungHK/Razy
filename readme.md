# Razy Framework

**A modular PHP platform for multi-site, multi-distributor application delivery.**

[![CI](https://github.com/RayFungHK/Razy/actions/workflows/ci.yml/badge.svg)](https://github.com/RayFungHK/Razy/actions/workflows/ci.yml)
[![Version](https://img.shields.io/badge/version-1.2.0-blue.svg)](changelog/)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4.svg?logo=php&logoColor=white)](https://www.php.net/)
[![Tests](https://img.shields.io/badge/tests-5%2C565_passing-success.svg)](#testing--quality)
[![Dependencies](https://img.shields.io/badge/runtime_deps-0-brightgreen.svg)](#what-razy-is-and-is-not)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

> **Documentation v2** — this README and the [`manual/`](manual/) tree were rewritten from
> audited source code (2026-07). Every claim here is either verified against code at
> `file:line`, or explicitly marked **[planned]**. Older generated docs may still contain
> drift — where docs and code disagree, **code wins**. See [Doc trust levels](#doc-trust-levels).

---

## Table of Contents

- [What Razy Is (and Is Not)](#what-razy-is-and-is-not)
- [Quick Start](#quick-start)
- [Golden Rules — Architecture Discipline](#golden-rules--architecture-discipline)
- [Architecture Overview](#architecture-overview)
- [Core Concepts](#core-concepts)
- [Routing](#routing)
- [Cross-Module API, Events & Bindings](#cross-module-api-events--bindings)
- [Template Engine](#template-engine)
- [Database Layer](#database-layer)
- [CLI Commands](#cli-commands)
- [Standalone Packages](#standalone-packages)
- [Performance](#performance)
- [Testing & Quality](#testing--quality)
- [Security Posture (Honest Edition)](#security-posture-honest-edition)
- [Docker & Deployment](#docker--deployment)
- [AI Agents & Coding Assistants](#ai-agents--coding-assistants)
- [Documentation Map](#documentation-map)
- [When to Choose Razy — and When Not To](#when-to-choose-razy--and-when-not-to)
- [License](#license)

---

## What Razy Is (and Is Not)

Razy runs **many websites, APIs, and services from one codebase**. Each site — a
**distributor** — loads its own versioned set of **modules**, with its own routing,
templates, config folders, and an **isolated Composer dependency tree**, while sharing
one framework binary (`Razy.phar`).

**Razy is:**
- A module-distribution platform: modules are versioned, shareable, distributable units;
  update a shared module once, every project picks it up on its own version schedule.
- Multi-site by construction: domain → distributor mapping, per-domain config overlays,
  per-distributor autoload scoping (`autoload/{distCode}/`).
- Zero runtime dependencies: `composer.lock` contains **0 production packages**. Every
  built-in (Mailer, WebSocket, OAuth2, Cache, Scheduler + cron, i18n Translator,
  Prometheus metrics endpoint, TOTP, Crypt, YAML…) is implemented in-repo.
- One binary: the whole framework is a single `Razy.phar`.

**Razy is not:**
- A general-purpose web framework competing with Laravel/Symfony feature breadth.
- A Kubernetes-native multi-tenant platform — **tenant isolation across processes is
  [planned] (v1.1→v2.0 roadmap)**; today isolation is per-directory and naming-level
  plus in-process guards (details in [Security Posture](#security-posture-honest-edition)).
- Composer — the built-in package manager reads Packagist metadata and extracts archives
  itself; it is a subset by design.

## Quick Start

Requirements: **PHP 8.2+** (CI-tested through **8.5**), `ext-zip`, `ext-curl`, `ext-json`.
Support policy: the 8.2 floor retires at its upstream EOL (**2026-12-31**) — the first
release after that date raises the floor to 8.3 (RZ-012 minor); 8.3+ users are unaffected
in the meantime.

```bash
# 1. Build (or grab the prebuilt Razy.phar from this repo root)
php build.php

# 2. Set up the Razy env in your project dir (config.inc.php, paths — there is no `init` command)
php Razy.phar build .

# 3. Bind a domain to a distributor code (interactive first run)
php Razy.phar set localhost mysite -i
php Razy.phar set example.com mysite     # add the real domain later, same dist code

# 3. Serve (dev)
php Razy.phar serve mysite
```

Project layout produced:

```
project/
├── Razy.phar               # the entire framework
├── config.inc.php           # global config
├── sites.inc.php            # domain → distributor mapping
├── index.php                # web entry point
├── autoload/                # per-distributor vendor trees
│   ├── lock.json
│   └── mysite/              # Composer packages for "mysite" ONLY
├── shared/module/           # cross-distributor modules
└── sites/mysite/
    ├── dist.php             # modules, tags, bridge config
    └── vendor/module/
        └── blog/
            ├── module.php           # metadata (module_code, version…)
            └── default/
                ├── package.php      # api_name, requires, prerequisite
                ├── controller/      # route handlers (closures)
                └── config/          # module config
```

A module's main controller returns an anonymous class extending `Controller`:

```php
<?php
use Razy\Agent;
use Razy\Controller;

return new class extends Controller {
    public function __onInit(Agent $agent): bool
    {
        $agent->addLazyRoute(['dashboard' => 'dashboard']);
        $agent->addAPICommand('getPost', 'api/get_post');
        $agent->listen('core/auth:onLogin', 'onUserLogin');
        return true;
    }
};
```

Route handlers are plain closures bound to the controller (`$this` = `Controller`).
Handler-file naming is resolved by `ClosureLoader` (verified `Module/ClosureLoader.php:126,130`):
paths are relative to `controller/`, carry **no `.php` suffix**, and slash-less names get
the module class-name prefix — the `dashboard` route above loads
`controller/blog.dashboard.php`, while `api/get_thing` loads `controller/api/get_thing.php`.

```php
<?php
// controller/blog.dashboard.php
use Razy\Controller;

return function (): void {
    /** @var Controller $this */
    $info = $this->getRoutedInfo();   // keys: route, arguments, method, url_query, module…
    $this->xhr()->responseAsBody(['ok' => true, 'route' => $info['route']]);
};
```

## Golden Rules — Architecture Discipline

These rules are what make one-codebase-many-projects **not** collapse into a distributed
monolith. They are binding for humans **and for AI coding agents** (full rule pack with
examples and enforcement: [`skills/RAZY-AI-RULES.md`](skills/RAZY-AI-RULES.md),
machine-checked by [`tools/lint-module-discipline.php`](tools/lint-module-discipline.php)).

| ID | Rule | Correct mechanism |
|----|------|-------------------|
| RZ-001 | Never `require`/`include` another module's files | `$agent->addAPICommand(...)` + `$this->api('vendor/mod')->cmd(...)` |
| RZ-002 | Never reach across distributor boundaries casually | Bridge commands + a **mandatory** `__onBridgeCall()` gate (the framework default allows all — you must close it, see [Security](#security-posture-honest-edition)) |
| RZ-003 | Never hand-write SQL from user input; never `new PDO` | `$db->prepare()->select(...)->where('x=:x')->assign([...])` or ORM (`Model`) named params |
| RZ-004 | **Templates do not auto-escape** — never output user data raw | `htmlspecialchars(..., ENT_QUOTES)` in code, the DOM builder in views, or `->escape` [planned core modifier] |
| RZ-005 | Never resolve framework internals through DI (`SecurityException` is a fence — do not climb it) | Module DI child only; services via documented Controller helpers |
| RZ-006 | Never write outside your module's `getDataPath()` / `getAssetPath()` | `getDataPath()`, `getAssetPath()` |
| RZ-007 | Never hand-edit `autoload/` or `lock.json`; declare deps in `package.php` | `require` (module deps), `prerequisite` (Composer deps) + `php Razy.phar compose <dist>` |
| RZ-008 | No shared state across modules (statics, singletons, global files) | APIs, events (`listen`/`trigger`), or per-distributor config |
| RZ-009 | Lifecycle discipline: register in `__onInit`, IO only after `__onReady`; respect `false` returns | [Core Concepts](#core-concepts) |
| RZ-010 | Call peers only through their published API; `bind()` for private helpers | `addAPICommand` (public) vs `$agent->bind()` (private) |
| RZ-011 | No `eval`/`exec`/`shell_exec` with interpolated input; `ThreadManager::spawnPHPCode()` is **deprecated** (child-side `eval`) | plain callables via `ThreadManager`, `spawnPHPFile()`, or `Razy\WorkerPool` (persistent workers, file-based jobs) |
| RZ-012 | A released module's API/Event surface is a **contract**: changes need version discipline | semver in `module.php`/`package.php`; new major dir for breaking changes |
| RZ-013 | Routes are registered through `Agent` only; never hand-edit generated rewrite/Caddyfile output | `addRoute`/`addLazyRoute`/`group` + `php Razy.phar rewrite` |
| RZ-014 | Every published API command ships a test; never weaken PHPStan/fixer configs to pass CI | `composer quality` |

If an AI agent proposes code violating a Golden Rule, reject and re-prompt with the rule
ID. The rules are greppable — CI fails on them.

## Architecture Overview

```
                 ┌────────────────────────────────────────┐
                 │   Application  (domain match → dist)    │
                 │   sites.inc.php: 'example.com' → dist   │
                 └────────────────────┬───────────────────┘
                                      │
             ┌────────────────────────┼────────────────────────┐
             ▼                        ▼                         ▼
      ┌─────────────┐         ┌─────────────┐          ┌──────────────┐
      │ Distributor │         │ Distributor │          │  Standalone   │
      │  (mysite)   │         │  (admin)    │          │   (lite)      │
      │ modules@tag │         │ modules@tag │          │ single module │
      └──────┬──────┘         └──────┬──────┘          └──────┬───────┘
             │    api() / events / bridge (gated)              │
        ┌────┴─────┐           ┌─────┴────┐                   │
        │ Modules  │◄─────────►│ Modules  │                   │
        │ per-dist │  API/Event│ per-dist │                   │
        │ autoload │           │ autoload │                   │
        └──────────┘           └──────────┘                   │
```

- **One FrankenPHP worker process** may host many distributors — they share the PHP
  class table. Treat cross-module trust as **intra-process trust** (see [Security](#security-posture-honest-edition)).
- `config_mapping` in `dist.php` reuses one distributor across domains with different
  config folders (`'example.com@v2' => 'prod'` loads `sites/mysite:prod/dist.php`).

## Core Concepts

### Module lifecycle (13 hooks + await callbacks)

```
__onInit ─► __onLoad ─► __onRequire ─► (await callbacks)
     ─► __onReady ─► __onScriptReady / __onRouted ─► __onEntry
     (+ __onDispatch, __onAPICall, __onBridgeCall, __onError, __onDispose, __onTouch)
```

| Hook | Do | Never |
|---|---|---|
| `__onInit(Agent)` | register routes, APIs, bindings, listeners | DB/file/network IO, calling other modules' APIs (not loaded yet) |
| `__onReady()` | cross-module API calls (all modules registered) | — |
| `__onRouted()` / `__onEntry` | request-scoped work, auth checks | mutating module-level state that leaks across requests in worker mode |
| `__onAPICall(ModuleInfo $fromModule, string $method)` | **permission gate** for your API surface | leaving unimplemented when exposing sensitive commands |
| `__onBridgeCall(string $sourceDist, string $cmd)` | **permission gate** for cross-distributor | relying on the framework default (which allows all — RZ-002) |

Returning `false` from lifecycle hooks aborts the module/request flow — handle it.

### Versioned modules & tags

A distributor selects module **tags** (versions) per module; `sites.inc.php` maps
`'/' => 'mysite@v2'`. Two distributors can run different versions of the same module
side by side — this is the core maintenance superpower: ship a fix once, every
distributor adopts on its own schedule.

## Routing

```php
$agent->addLazyRoute(['users' => 'users']);                  // handler: controller/<moduleClass>.users.php (prefix rule — see Quick Start)
$agent->addRoute('/api/user-(:d)/profile', 'user_profile');  // (:d)=digits, (:w)=word → controller/Route.user_profile.php
$agent->group('admin', function ($g) { /* scoped routes */ });
$agent->reserve('admin');   // deny other modules from claiming /admin/*
```

Request data arrives via the router; read route arguments with
`$this->getRoutedInfo()['arguments']` (verified keys: `url_query, base_url, route,
module, closure_path, arguments, type, method, is_shadow` — `RouteDispatcher.php:382+`)
and answer JSON with `$this->xhr()->responseAsBody($payload)`. Note `url_query` is the
**routed path, not the `?k=v` string** (`RouteDispatcher.php:409`) — Razy exposes no
query-input wrapper, so for `$_GET/$_POST` reads, cast and validate immediately and
never interpolate into SQL (RZ-003; the discipline lint flags raw superglobal reads at
warning level — the shipped demos' migrated pattern annotates each read with a
justified `// lint-allow: RZ-003`).

## Cross-Module API, Events & Bindings

This is the **only sanctioned** cross-module surface (RZ-001, RZ-008, RZ-010).

```php
// Provider module — __onInit:
$agent->addAPICommand('getPost', 'api/get_post');   // published to everyone (no .php suffix — loader appends)
$agent->addAPICommand('#draftSave', 'api/draft');   // '#' = also a private binding (self-call sugar)
$agent->bind('helper', 'helpers/format');           // private, never callable by others

// Provider — permission gate (real signature verified Controller.php:173; $fromModule is the CALLER):
public function __onAPICall(\Razy\ModuleInfo $fromModule, string $method, string $fqdn = ''): bool
{
    return $method === 'getPost';
}

// Consumer — anywhere after __onReady:
$post = $this->api('vendor/blog')->getPost($id);

// Events — provider (module acme/blog): BARE event name; payload via resolve(), not a
// second arg (Controller.php:339); the framework qualifies the name with the module code:
$this->trigger('published')->resolve(['id' => $post['id']]);
// Events — consumer's __onInit: listen by the QUALIFIED name (Agent.php:177-181)
$agent->listen('acme/blog:published', 'onPostPublished');  // or observe() for non-blocking
```

Cross-**distributor** calls use bridge commands (`$agent->addBridgeCommand`) and MUST be
gated via `__onBridgeCall` — see [Security](#security-posture-honest-edition).

## Template Engine

Block syntax with variables, conditionals, iteration:

```
{$user.name->capitalize}              ← modifier chain (->)
{@if $user.role="admin"} ... {/if}
{@each source=$items as="item"} <li>{$item.name}</li> {/each}
{@TEMPLATE "layout"} … {@INCLUDE "content"} {/TEMPLATE}
```

> **Syntax correction vs old README:** `|` is **not** a modifier pipe. `{$a|$b}` tries
> `$a`, then falls back to `$b` (alternatives chain; literals allowed: `{$user.nick|'anon'}`).
> Modifiers use `->name:arg` (e.g. `{$title->upper}`, `{$tags->join:', '}`).
> Trap: shipped `demo_modules/core/template_demo` .tpl files still use legacy `|upper`
> syntax — it silently no-ops under the fallback semantics (`Entity.php:388-396`), do
> not copy it.

Built-in modifiers: `upper, lower, trim, join, nl2br, capitalize, alphabet, gettype, addslashes`
plus the newer `escape` (v1.0.3-beta+), `truncate`, `date`, `number`, `json`, `strip_tags`.
Since v1.0.3-beta the engine ships an **`escape` modifier**: `{$userInput->escape}`
(ENT_QUOTES, UTF-8, chains like `{$v->trim->escape}`). `{$var}` without a modifier
still outputs **raw** — every dynamic value needs `->escape` or controller-side
`htmlspecialchars()` (RZ-004). On older versions, add the fallback patch from
[skills/RAZY-AI-RULES.md](skills/RAZY-AI-RULES.md) Appendix C. The **DOM builder**
remains the auto-escaping path for programmatically-built HTML.
Never render raw request input through `{$...}`.

Form tokens (the engine has no `csrf_field` function — deliberate, see the rules doc):
the controller passes the token, `<input type="hidden" name="_token" value="{$token->escape}">`.

**Module-owned plugins** (governance): each module can ship its own modifiers/functions
under `<module>/plugins/Template/` and register them with
`$this->registerPluginLoader(self::PLUGIN_TEMPLATE)` — module code, module path, RZ-001-safe.
Lookup is first-folder-wins and core folders register at bootstrap, so **modules cannot
shadow core plugins** (predictable, no monkey-patching). File naming is the identity:
`modifier.<name>.php` / `function.<name>.php`; the factory returns a closure producing a
`TModifier`/`TFunction` subclass. Same contract family for Collection / Pipeline / Statement
plugins (`PLUGIN_*` flags).

## Database Layer

Multi-driver (MySQL, PostgreSQL, SQLite) with a statement builder, "Simple Syntax",
ORM, migrations, transactions/savepoints.

```php
$db = $this->getDB();

$stmt = $db->prepare()
    ->select('u.id, u.name')
    ->from('u.user-g.group[group_id]')          // join: user u ⋈ group g ON group_id
    ->where('u.user_id=:uid,!g.auths~=:auth')   // named params + JSON not-contains
    ->assign(['uid' => $userId, 'auth' => 'view'])
    ->order('>created_at')                       // > DESC, < ASC
    ->limit(10, 0);

$rows = $stmt->query();         // result set
$one = $stmt->lazy();           // first row
$sql = $stmt->getSyntax();      // rendered SQL — debugging only, never re-inject user input
```

Rules (RZ-003): values **always** via `assign()`/named params; identifiers only from a
whitelist (`^[A-Za-z_]\w*$`); never build condition strings from user input;
`getSearchTextSyntax()` with raw text is a known injection landmine — pass search text
through `assign()`. ORM (`Model`) uses `:param` bindings consistently — prefer it for
row-level work. `Database::prepare(string)` raw passthrough is framework-internal
plumbing; modules must not use it.

## CLI Commands

28 commands in `src/system/terminal/`: `build`, `init`, `serve`, `run`, `runapp`
(interactive shell), `rewrite`, `routes`, `compose`, `install` (GitHub owner/repo),
`pack`, `publish`, `validate`, `set`, `link`/`unlink`, `sync`, `remove`, `search`,
`inspect`, `generate-skills`, `scaffold`, `cache`, `queue`, `schedule`, `bridge`, `pkg`, `standalone`,
`version`, `help`.

```bash
php Razy.phar compose mysite      # resolve prerequisites into autoload/mysite/
php Razy.phar validate mysite     # structure + dependency validation (run in CI)
php Razy.phar install acme/blog   # fetch a module archive from GitHub
php Razy.phar generate-skills     # regenerate skills context for AI agents
```

## Standalone Packages

Any module can ship as an executable `.phar` app with its own manifest:

```json
{
  "package_name": "my-api",
  "version": "1.0.0",
  "mode": "exec",                    // exec = run-to-completion, serve = long-running
  "strict": false,
  "on_depend": [
    {"package": "db-setup",      "wait": "complete"},
    {"package": "cache-service", "wait": "healthcheck"},
    {"package": "shared-lib",    "wait": "load"}
  ],
  "healthcheck": {"url": "http://localhost:8080/health", "interval": 2, "timeout": 30},
  "prerequisite": {"monolog/monolog": "^3.0"}
}
```

Hooks via `PackageTrait`: `__onPackageStart / __onPackageExec / __onPackageServe /
__onPackageStop / __onPackageHealthcheck`. Inter-package API/events exist for co-modules
loaded with `"wait": "load"`. Note: the per-package healthcheck **endpoint** is yours
to implement — the framework ships its own liveness at `/_razy/health` (`Razy\Health`)
which is a different thing (orchestrator probe, not package-specific).

```bash
php Razy.phar pkg migrate -- --fresh
php Razy.phar pkg my-api --daemon
php Razy.phar pkg list
```

## Performance

Measured on this repo's `benchmark/` suite, **2026-09 symmetric epoch** (raw k6
JSON for every pass under `benchmark/results/`, toolchain receipts embedded in
`benchmark/REPORT.md`; protocol and invalid-pass log in
[`benchmark/EPOCH-2026-09.md`](benchmark/EPOCH-2026-09.md)): both stacks run the
**identical FrankenPHP 1.10 runtime** (Razy worker vs Laravel 13.32 + Octane's
FrankenPHP driver — the Swoole-vs-FrankenPHP asymmetry of the 2026-02 table is
gone), both render through their real engines and query-builder layer, both on
persistent links, 2 vCPU/4 GB containers, MySQL 8.0.43, 3 runs per scenario.

| Scenario | Razy | Laravel | Verdict |
|---|---:|---:|---|
| Static route | **6,336 RPS** | 2,146 | Razy 2.95× |
| Template render (real engine both sides) | **4,950** | 1,961 | Razy 2.52× |
| DB read | **4,327** | 1,456 | Razy 2.97× |
| DB write | 808 | 779 | ≈ equal (MySQL-bound — as the audit predicted) |
| Composite (DB + template) | **3,600** | 1,464 | Razy 2.46× |
| Heavy CPU | 144 | 121 | ≈ same runtime, as expected |

Plain **PHP-FPM baseline** (both stacks on stock php:8.3-fpm, every request pays
full boot) isolates what worker mode buys each framework — and the answer is
asymmetric, which is the honest headline: **worker mode multiplies Razy's own
FPM throughput by 21–30× on request-shaped scenarios (4.4× write-bound, 1.8×
CPU-bound), Laravel's by 1.75–3.1× (0.5× on heavy CPU)**. Under plain FPM the
ranking flips (Laravel's compiled config/route/view artifacts boot cheaper than
Razy's phar-boot per-request assembly: 921 vs 295 static RPS at 200 VUs; the
attribution is measured, not assumed — a 3× CPU/request gap confirmed by
cgroup probes, its buckets profiled: phar autoload, FS probing, per-request
route assembly, reflection DI — see the EPOCH doc). Each
stack's strength lives on a different runtime; the tables and receipts are in
[`benchmark/REPORT.md`](benchmark/REPORT.md), the protocol and the invalidated
first FPM pass (a cache-ownership bug — found, logged, rerun fair) in
[`benchmark/EPOCH-2026-09.md`](benchmark/EPOCH-2026-09.md).

Update 2026-09-17 (compiled column, published from paired runs only): the
deploy-time compile landed — the baked classmap needs **no opt-in** and lifts
the FPM static plateau from 229.1 into the 370–396 RPS band (~+73%; the same
host's noise floor was measured at ±2x and is documented, so the band is the
claim, not a point). The boot-snapshot replay, measured PAIRED on a purpose-built
60-module fpm dist, now ships with the stat fingerprint auto-shortening where
opcache freezes bytecode (the case where its protection target no longer
exists): +10…+23% across runs (never lost a pair; the shortening keeps a
structure floor — an artifact naming folders that don't exist here is refused
outright, the one case frozen bytecode can't see coming). With the fingerprint
forcing full stats it cost -22% — the measurement that drove the fix (all
columns in [`benchmark/EPOCH-2026-09.md`](benchmark/EPOCH-2026-09.md)).
Dev (opcache off / vt=1) keeps the full stats. Worker deployments were always
the fingerprint-free case — boot once — and the replay earns its keep there.

These numbers carry no caveats about methodology because the 2026-02 caveats
were **fixed**: string-concatenation endpoints, connection-policy asymmetry,
missing raw data and unpinned toolchains are all closed items
([`RAZY-ANALYSIS-REPORT.md`](RAZY-ANALYSIS-REPORT.md) → absorption tracked in
`architecture/COMPETITOR-LANDSCAPE.md` §5). Worker-mode per-request framework
overhead is ~0.05 ms (boot-once, verified in `src/main.php`).

**Autoscaling out of the box (unreleased):** the framework serves
`GET /_razy/metrics` (Prometheus text format, answered pre-dispatch by `Razy\Metrics`)
whose `razy_http_requests_total` counter is the intended HPA metric source — see the
adapter rule in [`deploy/k8s/hpa.yaml`](deploy/k8s/hpa.yaml). Scheduled maintenance
jobs need exactly one crontab line: `php Razy.phar schedule run` against your
`scheduler.inc.php` (cron expressions, `withoutOverlapping()` locks, `--tz=`).

## Testing & Quality

Verified on this working tree (PHP 8.3, 2026-07):

```
composer test → OK, but there were issues!
Tests: 4845, Assertions: 8672, Warnings: 2, Skipped: 87   [40s]
```

- **5,534 tests / 167 test classes**, 0 failures (2 warnings, 116 skips). The skips are
  platform-conditional (Windows `/proc`, Redis ext, SSH2 ext, …) — all execute under
  `.docker/docker-compose.test.yml`.
- **CI**: PHP 8.2/8.3/8.4/8.5 matrix (Ubuntu) + Windows, pcov **50% line-coverage gate**,
  php-cs-fixer (PSR-12 extended) via `cs2pr`, PHPStan level 5.
- `composer quality` = cs-check + phpstan + test. `.githooks/pre-commit` enforces
  cs-check + phpstan locally (`git config core.hooksPath .githooks`); tests and the
  discipline lint run in CI.
- Module discipline lint (Golden Rules): `php tools/lint-module-discipline.php <path>`
  — CI runs it **blocking (`--strict`) on both `demos/` and `demo_modules/`**. The
  shipped demos were migrated to full compliance in 2026-07 (4 errors / 109 warnings →
  0 / 0 across 247 files, 15 justified `lint-allow` sites); the `->escape` modifier the
  migration relies on is built in since v1.0.3-beta.

```bash
composer test            # unit + integration
composer test-coverage   # HTML coverage (needs pcov/xdebug)
composer quality         # cs + phpstan + tests
```

## Security Posture (Honest Edition)

**Genuinely strong (verified):** AES-256-CBC + HMAC-SHA256 encrypt-then-MAC with random
IV and `hash_equals` (`Razy\Crypt`); RFC 4226/6238 TOTP/HOTP (`Razy\Authenticator`);
CSPRNG (`random_bytes`) for all tokens/session IDs/backup codes; CSRF synchronizer with
timing-safe checks; every `unserialize` uses `allowed_classes=false`; identifier
whitelists in the SQL layer; ORM bound params; HTTP client protocol allow-lists; zero
supply-chain runtime surface.

**Gap remediation status** (2026-07 audit; ⚠️ = still binding for every project):

| Gap | Status | What you MUST do today |
|---|---|---|
| Templates do not auto-escape | ✅ fixed v1.0.3-beta | Built-in `->escape` modifier ships; engine still does **not** auto-escape, so keep applying it (RZ-004) |
| Package extraction lacked entry-path validation (zip-slip); RepoInstaller permitted plain HTTP | ✅ fixed (unreleased) | `Razy\ArchiveSafety` now rejects `..`/absolute/symlink entries and non-HTTPS URLs before extract; HTTPS stays the default (opt out only via `RAZY_ALLOW_INSECURE_TRANSPORT=1` on trusted LAN mirrors) |
| Bridge commands open by default (`__onBridgeCall` default allows all); CLI `bridge` is unauthenticated local IPC | ⚠️ mitigated | Implement `__onBridgeCall` allow-lists for every bridge module. **New:** set `RAZY_BRIDGE_SECRET` to require an HMAC envelope (`Razy\BridgeSignature`) on every `executeBridgeCommand`; CLI `bridge` remains local IPC — treat shell access to the project dir as code-exec trust (RZ-002) |
| `spawnPHPCode()` uses `eval(base64_decode())` in the child | ⚠️ open | Never pass input-derived code; prefer `spawnPHPFile()` (0600, atomic) or plain callables (RZ-011) |
| SQL layer inlines quoted values (not native bound params); `getSearchTextSyntax()` landmine | ⚠️ open | `assign()` for every value; avoid raw search-text syntax (RZ-003) |
| Shipped Docker image ran as root on `php -S` | ✅ fixed (unreleased) | `.docker/Dockerfile` is now non-root + OPcache + `/_razy/health` HEALTHCHECK; production worker image + K8s manifests live in `deploy/` |
| No liveness endpoint for orchestrators | ✅ fixed (unreleased) | `GET /_razy/health` answered pre-dispatch (`Razy\Health`); verbose/deep tiers gated by `RAZY_HEALTH_VERBOSE`/`RAZY_HEALTH_TOKEN` |
| Metrics scraping | ✅ shipped (unreleased) | `GET /_razy/metrics` (`Razy\Metrics`, Prometheus text format) feeds the HPA via `razy_http_requests_total`; basic gauges are public — deep detail (opcache/load/peak) requires `RAZY_HEALTH_TOKEN` |
| Cross-tenant process isolation (tenant containers, HMAC bridge, K8s — Phase 1–5) | 🔶 improved | HMAC bridge + health + `deploy/k8s/` + worker image now exist, but the framework still shares one process/filesystem per install — **do not** host mutually-untrusted tenants in a single install today |

Report vulnerabilities per [SECURITY.md](SECURITY.md).

## Docker & Deployment

```bash
docker compose -f .docker/docker-compose.yml up                                          # dev: php + Caddy
docker compose -f .docker/docker-compose.yml -f .docker/docker-compose.dev.yml up        # live reload
docker compose -f .docker/docker-compose.test.yml up --build --abort-on-container-exit   # full suite, 0 skips
```

- **Reality check:** the official image currently CMDs the PHP **built-in server**
  (dev-grade) as root. For production, serve the built phar with your own
  Caddy/FrankenPHP or PHP-FPM, run **non-root**, and add health probes on your own
  endpoint (`/_razy/health` is **[planned]**).
- The benchmark FrankenPHP worker setup in `benchmark/docker/` (Caddyfile `worker`
  pattern) is the reference for persistent-worker deployment.
- Kubernetes support (Helm, NetworkPolicy, per-tenant PVCs) is **[planned]** — none of it
  exists today; do not deploy the manifests shown in `architecture/` docs as if shipped.

## AI Agents & Coding Assistants

Load these before writing any Razy project code — they are designed to be read by LLMs:

1. **[AGENTS.md](AGENTS.md)** — compact rule digest (every agent reads this file automatically).
2. **[skills/RAZY-AI-RULES.md](skills/RAZY-AI-RULES.md)** — full rule pack RZ-001…RZ-014:
   forbidden patterns (cross-module `require`, direct file access into other modules,
   raw SQL, raw template output, DI fence-climbing), correct alternatives, self-check greps.
3. **`skills.md` + generated per-dist/module context** — regenerate with
   `php Razy.phar generate-skills` after structural changes.
4. **Enforcement:** `php tools/lint-module-discipline.php sites/<dist> --format=json`
   fails CI on violations. Agents must run it (and `composer quality`) on every change
   they claim complete.

Agents must additionally trust **code over stale docs**: verify APIs in
`src/library/Razy/` (or `php Razy.phar inspect`) before use, and cite `file:line` in
rationales.

## Documentation Map

| Path | Role | Trust |
|---|---|---|
| `readme.md` (this file) | canonical overview | verified against code, 2026-07 |
| `manual/` | the user manual (v2) | written from source |
| `AGENTS.md` + `skills/` | AI guardrail packs | canonical rules |
| `demos/` | golden-path example modules + anti-pattern gallery | runnable references |
| `site/` | zero-build documentation site (renders `manual/`) | shell; content = manual |
| `architecture/` | design docs incl. ENTERPRISE-TENANT-ISOLATION.md | **design intent**, not current code |
| `changelog/` | per-version changes | release-time truth |
| `documentation/` | legacy HTML site | superseded by `site/` |
| `skills.md` | generated project context | generated; refresh via `generate-skills` |
| `RAZY-ANALYSIS-REPORT.md` | independent 2026-07 audit of this repo | evidence-backed self-assessment |

### Doc trust levels

1. **Code** (`src/`) — always authoritative.
2. **This README + `manual/`** — verified 2026-07; report drift via issues.
3. **Generated context** (`skills.md`) — authoritative *at generation time*; regenerate.
4. **Design docs** (`architecture/`) — intent; gaps marked **[planned]**.
5. Legacy mirrors (`docs/`, `memory/`, `Razy.wiki*`) — **deprecated**; never trust.

## When to Choose Razy — and When Not To

**Choose Razy** when: you run many client sites/services with overlapping-but-diverging
feature sets; you want one module update to roll across projects on per-project version
pins; you value zero supply-chain dependencies and one-phar deploys; your team boundaries
map to distributors/modules with API contracts.

**Choose Laravel/Symfony** when: single large app; you need the broad package ecosystem;
conventional patterns matter more than module governance.
**Choose Hyperf/Swoole** when: raw throughput + coroutine concurrency is the product.

**Do not choose Razy (yet)** when: you must host mutually-untrusted tenants on one
install (isolation is naming-level today; OS/cluster isolation is planned), or when you
need K8s-native deployment today.

## License

[MIT](LICENSE) — Copyright (c) Ray Fung
