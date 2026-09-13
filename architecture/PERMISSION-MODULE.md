# PERMISSION-MODULE — Where "Who May Do What" Lives in Razy

Research + gap analysis + decision on the "permission module" question: framework Auth-layer
completion vs a first-party `razymod/permissions` module vs hybrid. Code beats docs: every
local claim cites `path/File.php:line` actually read; every external claim cites a
primary-source URL actually fetched (failures named in §12/Sources). Companion foundations:
`architecture/OAUTH-SOCIALITE-HTTP.md` (three orphan islands, identity-persistence gap Q1,
`social.user_resolved`, users-table-off-limits boundary — **built on, not rediscovered**) and
`architecture/OFFICIAL-REPO-INSTALL.md` (dossier structure/tone). Generated 2026-09.
Status: **complete**.

## 0. Headline correction (the fourth orphan island is already the best one)

The research question assumes permissions must be built. Half of it **is already built,
fully tested — and wired to nothing**:

| Layer | Asset | Reality (verified) |
|---|---|---|
| Ability engine | `Razy\Auth\Gate` — 412 lines | Laravel-shaped: `define`/`allows`/`denies`/`check`/`any`/`none`/`authorize`/`forUser`/`before`/`after`/`policy` all real (§1) — **zero runtime callers**: `grep 'AuthManager|Auth\\Gate|new Gate|CallbackGuard|AuthMiddleware|AuthorizeMiddleware|GenericUser|AccessDeniedException' src/` → 30 hits, all inside `src/library/Razy/Auth/` itself or in *docblocks* (`Agent.php:372,402`, `RouteGroup.php:34`) |
| Identity seam | `AuthManager` (219), `CallbackGuard` (137), `GenericUser` (97), contracts `GuardInterface:25-70` / `AuthenticatableInterface:23-45` | Guard registry works; **no `login()/logout()` on the contract, no Session/Token guard class exists** (`AuthManager.php:31-32` docblock advertises both) |
| Route gate | `AuthMiddleware` (75), `AuthorizeMiddleware` (89), `MiddlewarePipeline` execution in the dispatcher | The middleware pipeline is **real and live** (`RouteDispatcher.php:518-549`) — but no module or core code ever attaches the two auth middlewares; every consumer today self-gates in-handler (§2, production evidence §3) |
| Persistence | ❌ none | No `permissions/roles` table concept anywhere in `src/`; first-party `razymod/queue-admin` shipped migrations for nothing (`grep 'Migration|Contract::define|CREATE TABLE' modules/queue-admin` → 0 hits) — the permissions module would be the **first first-party module to own tables** |
| Prior art that actually ships | `production-sample/…/razit-group` + `razit-user` | A working admin permission system in the maintainer's live site: session-hydration (`razit-user/default/controller/user.doLogin.php:12-23`), role→permission merge (`:39-52`), check surface `$this->api('razit_group')->auth($module, $action)` (`razit-group/default/controller/api/auth.php:4-13`), superuser bypass (`auth.php:9`) — the exact loop this dossier must generalize |

**Genuinely missing** (this dossier's scope): (a) identity hydration — turning
`$_SESSION` into an actor object that `Gate` can consume; (b) persistence — roles/abilities
rows via the ORM substrate; (c) per-distributor scoping decisions; (d) check plumbing — the
canonical `can()` call sites for controllers/routes/templates/CLI; (e) the missing glue
`Gate` needs (multiple `before` hooks; §1 hazard). **Already exists**: the ability engine,
the middleware rail, the module-gate precedent (`__onAPICall` allow-lists), eventing for
audit, and a production-proven loop to learn from. Also caught en route: a **phantom
framework API** (`Database::getSharedInstance()`) with 6 callers and no definition (§4.4) —
the exact trap a permissions module must not repeat.

## 1. `Razy\Auth\*` in full — reusable vs must-change

| Class | Lines | What it actually implements (file:line) | Verdict |
|---|---|---|---|
| `Auth\Gate` | 412 | Raw-array abilities (`private array $abilities` `:61`, `define()` overwrites same-name `:103-108`); policies = class-per-model registry (`:68`, `policy()` `:121-126`, instantiate-`new $policyClass()` **no DI** `:389`, kebab→camel method map `:408-411`); `before`/`after` = **one slot each, last-writer-wins** (`?Closure :73,:78`, assign `:139-144,:156-161`, non-null short-circuit `:180-185`, `after` can override `:199-204`); guest → hard `false` before any hook (`:173-177`); **default-deny for undefined abilities** (`$result ??= false` `:195-196`); `authorize()` throws `AccessDeniedException` (`:281-286`); `forUser()` clones with user override (`:295-301`, consumed `:342-349`); user comes from `$this->auth->user()` (`:90-93,:348`) | ✅ reuse as-is as *the* engine; ⚠️ needs one additive fix (§1 hazard 1) |
| `Auth\AuthManager` | 219 | Named-guard registry (`addGuard :83-88`, `guard()` throws on unknown `:99-108`, default `'default'` `:58`); delegation `check/guest/user/id/validate/setUser` (`:163-218`); **no `login()`/`logout()`**, no session writes | ✅ reusable; docblock lies (ledger P1) |
| `Auth\CallbackGuard` | 137 | `GuardInterface` over closures: lazy one-shot user resolve (`:85-96`, `resolved` flag), credential validator (`:109-116`), `setUser` marks resolved (`:121-125`), `reset()` for worker mode (`:132-136`); its own docblock shows the intended session wiring (`:30-36`: `userResolver: fn() => $_SESSION['user'] ?? null` `:31`) | ✅ this **is** the hydration seam — nobody hands it a resolver. It is *not* a route/callback gate: `addCallbackRoute` does not exist anywhere (`grep` = 0 hits) (ledger P3) |
| `Auth\GenericUser` | 97 | In-memory `array` holder implementing `AuthenticatableInterface`; id key configurable via `__identifier_name` (`:42-53`); password defaults `''` (`:58-61`) — a password-less actor (API token, OAuth-only) must tolerate the fake empty hash | ✅ reusable as the actor VO crossing module boundaries |
| `Auth\Hash` | 81 | `make` (bcrypt default `:38-41`), `check` `:51-54`, `needsRehash` `:65-68` | ✅ fine, unused too |
| `Auth\AccessDeniedException` | 48 | `RuntimeException` + status code, default 403 (`:33-37`) | ✅ |
| `Auth\AuthMiddleware` | 75 | 401 + short-circuit when `!$this->auth->guard($g)->check()` (`:61-74`), custom rejection closure (`:64-66`) | ✅ exists, unwired |
| `Auth\AuthorizeMiddleware` | 89 | `gate->denies($ability, ...args)` → 403 short-circuit (`:66-88`), argument-from-context resolver (`:70-75`) | ✅ exists, unwired — this is the route-gate primitive Laravel implements as `->middleware('can:…')` (§7-fetched) |
| `Razy\Authenticator` (TOTP/HOTP) | 552 | RFC 6238/4226 statics: `generateSecret` (`:89-98`), backup codes (`:111+`), provisioning URI; own suite `tests/AuthenticatorTest.php` | orthogonal — 2FA rides *on* identity, not on this dossier |
| `Razy\Contract\GuardInterface` | 70 | `check/guest/user/id/validate/setUser` (`:32-69`) — no login/logout | stable already |
| `Razy\Contract\AuthenticatableInterface` | 45 | `getAuthIdentifier(): string\|int` (`:30`), `getAuthIdentifierName()` (`:37`), `getAuthPassword(): string` (`:44`) | stable; the `string\|int` id is what decides the join-column type (§7) |

**Who calls Gate/AuthManager at runtime — claim verified: nobody.** All 30 `src/` hits are
the classes' own bodies or docblocks; `CLASS-CATALOG.md` lists only `Razy\Authenticator`
(`:52`) and no guard-layer row; `manual/` has **zero** `Gate`/`AuthManager` mentions
(grep). Same documented-but-absent-in-reverse pattern the OAuth dossier flagged for
`OAuth2` (D4 there).

**Test coverage — behavior, not surface.** `tests/AuthTest.php` = 1,256 lines, **89**
`test*` methods, `#[CoversClass]` on all eight classes (`:61-68`); it pins guest-deny
(`:532`), undefined-ability-deny (`:524`), before/after override semantics (`:731-779`),
policy fall-through + kebab mapping (`:696-729`), `forUser` isolation (`:804-850`), the
middleware 401/403 short-circuits (`:896-1048`), and a full validate→setUser→Gate→policy
lifecycle (`testFullAuthLifecycle :1100-1157`). Consequence: this surface is **frozen
by tests** — any RZ-012 change must stay additive (new methods), never behavioral edits to
what is pinned.

**Two hazards the module integration will hit:**
1. **Single `before` slot** (`Gate.php:73,:141`) vs Laravel's appendable hooks
   (fetched doc: `Gate::before(...)` "run before all other authorization checks"). If the
   permissions module registers a DB-check `before` hook and an app (or another module)
   registers a super-admin hook, the later registration silently replaces the earlier —
   a security-flavored footgun. Needs an additive `addBefore()` list in core (S1).
2. **Global ability name collisions**: `define()` overwrites by name (`:105`) and there is
   no per-module namespace; naming convention (§7.4) is mandatory, not stylistic.

## 2. Permission-ish surfaces already wired in production (align, don't duplicate)

| Surface | Enforcement point (file:line) | Semantics today |
|---|---|---|
| `__onAPICall` module gate | `Controller.php:173-176` **default returns `true`**; enforced at `Module/CommandRegistry.php:121-131 → executeCommand :202-206` — denied call silently returns **`null`** | "no gate means open" (AGENTS.md trap; `manual/07-security-guide.md:134-137`). Precedents: `razymod/queue-admin` allow-list const + `isset` gate (`queueadmin.php:17-22,:56-59`); golden demo the reference shape (`demos/golden/provider/.../provider.php:30-32,:67-73`, incl. per-caller tightening comment `:70-71`) |
| `__onBridgeCall` cross-dist gate | `Controller.php:187-190` default `true`; enforced `CommandRegistry.php:168-178`; defense-in-depth HMAC envelope `Razy\BridgeSignature` (`RAZY_BRIDGE_SECRET`, `hash_equals`) — RZ-002 | Allow-list as *identity hygiene*, not auth (`manual/07:138-146`); `executeInternalCommand` **bypasses the gate** (`CommandRegistry.php:144-153`, used by CLI `bridge.inc.php:71` — operator-trust local IPC) |
| CSRF / method gates | `Csrf\CsrfTokenManager` (`validate` hash_equals `:96-105`) vs queue-admin double-submit cookie (`modules/queue-admin/default/controller/support/csrf.php:18-51`) — module deliberately skipped the framework manager because the driver Session emits no cookie (`:5-13`) | queue-admin mutation route = POST-only 405 → CSRF 403 → service validation (`queueadmin.act.php:17-30`): "admin-ish" HTTP guarding today |
| In-handler auth | production razit: `user.main.php:6-9` (`checkSession()` then `api('razit_group')->auth(...) → Error::Show404()`) | Today's *actual* route-gating pattern — no middleware in use |
| CallbackGuard-as-route-gate | **does not exist** — it is an auth guard (§1); no `addCallbackRoute` anywhere | name is a misnomer (ledger P3) |
| Scaffold admin | `grep -i admin src/asset` → 0 hits | no admin scaffold; nothing to align with |

## 3. "Current user" reality

- **Native session**: `Distributor::setSession()` starts a PHP session per web request —
  `session_set_cookie_params` host-only / `secure` if HTTPS / `httponly` / `SameSite=Lax`
  (`Distributor.php:251-262`), **`session_name($this->code)`** (`:263`) → cookie (and thus
  `$_SESSION`) is already **per-distributor isolated by name**; called from
  `matchRoute()` (`:283`). CLI never reaches it (`WEB_MODE` guard `:253`; `CLI_MODE`/
  `WEB_MODE` defined `src/system/bootstrap.inc.php:75-80`).
- **Who can set `$_SESSION` today**: any module handler code on a web-routed request
  (superglobal, already started). What *does* in framework code: nothing —
  `grep '\$_SESSION' src/` = 2 hits, both docblocks (`Auth/CallbackGuard.php:31`,
  `Session/Session.php:25`). The driver Session (`Session\Session`) still emits no cookie
  (OAuth dossier §3; `queue-admin` csrf.php:5-13 restates) — so native `$_SESSION` is the
  only cookie-backed store that actually works on web routes.
- **The convention that exists is in production** (`production-sample/`): login stores
  `$_SESSION['login'] = ['login_name','password'(md5!),'session_key']`
  (`razit-user/default/controller/api/login.php:36,48-53`, with a `remember_me` cookie
  carrying the same tuple `:49-50`); every request re-validates session_key against the DB
  and hydrates `$this->userdata` (`user.doLogin.php:12-23`), merges group permission trees
  into it (`:39-52`, superuser gets the full tree `:51`), and enriches via an event
  (`$this->trigger('fetchinfo')->resolve(...)` `:34-36`). The manual already blesses this
  shape: "**one module owning session lifecycle and exposing `api()` commands**, rather
  than `$_SESSION` sprinkles (RZ-008)" (`manual/07-security-guide.md:125-128`).
- **`GenericUser` fit**: production userdata is a plain row array with `user_id` int PK;
  `GenericUser(['id' => …])` wraps exactly that (`GenericUser.php:34-45`, custom id key
  `:52`); `AuthenticatableInterface::getAuthIdentifier(): string|int` (`:30`) is the join
  type for permission rows (§7.2).
- **Anti-patterns to *not* re-import**: md5 passwords (`login.php:36`) and
  credentials-in-session/cookie (`:48-52`) — replace with "session stores an actor id +
  one-time-bound token only, DB re-validation per request stays" (the hydration *loop* is
  right, the *payload* is wrong).

## 4. Storage substrate — how a module declares and reaches tables (evidence 2026-09)

### 4.1 There is no TableTrait; two declaration paths ship today

- **Migrations**: `Razy\Database\Migration` (`up/down(SchemaBuilder)` `:59,:66`) +
  `MigrationManager` (owns `SchemaBuilder` `:57-58,:73`, own tracking table
  `ensureTrackingTable :111-144`, batched); module-side entry point
  `Controller::getMigrationManager(Database)` reads `<module>/migration/`
  (`Controller.php:681-695`; layout `:612-618`; corrected docblock — Table's real column
  API is `addColumn(string $syntax)`, `:650-654`). Column grammar
  `'name=type(int),auto'` / `reference(table,col)` FK (`Column.php:92,:109` cited there;
  `Table.php:499 CREATE TABLE IF NOT EXISTS`).
- **ORM Contract packs (C1/C2, shipped v1.1.0-beta)**: `Razy\ORM\Contract` — pure value
  object, "skeleton the Model reads and the DDL compiler writes from", reusing the *same*
  Column grammar (`Contract.php:10-28`), relations declared not inferred (`:23-26`), BC
  without contract (`:28`); `ContractCompiler::apply()` routes CREATE through the exact
  SchemaBuilder path migrations use (`ContractCompiler.php:42-46`), plus `create
  --generate` scaffold + snapshot drift (changelog `v1.1.0-beta.md:27-30`). ✅ both paths
  are viable for a module; S1-era guidance: **migrations own the tables, Contract declares
  the shape** (single grammar, double consumer).

### 4.2 How a module *gets* a `Database` (and the trap)

Framework does **not** auto-connect (`manual/04-database.md:16-22`: `connect(host,user,pw,db)`
`:220` / `connectWithDriver(driver, config)` `:238`). Resolution options, in health order:

| Pattern | Evidence | Health |
|---|---|---|
| `$this->resolve(Database::class)` + explicit connect, credentials from `getModuleConfig()` | `Controller.php:659` docblock; `Container` block-list does **not** fence `Database` (`Module.php:1166-1181` — the RZ-005 fence covers Application/Container/Distributor/Module/Controller/… only); `manual/04:45-51` recommends exactly this | ✅ the compliant default |
| app-owned provider module exposing `api('razit')->getDB()` — returns a **live `Database` object across the module boundary** | `razit/default/controller/api/getDB.php:5-6` | 🔶 production habit; works in-process but streams a live shared object through `api()` — RZ-008-adjacent; do not canonize |
| `Database::getSharedInstance()` | called at `queue.inc.php:52` + `modules/queue-admin/default/controller/{api/status.php:14, api/job.php:11, api/action.php:14, api/purge.php:11, support/store.php:20}` — **`grep 'function getSharedInstance' <repo>` = 0 definitions** (Database.php's full method list `:103-778` has none, no trait, no `__callStatic`) | ❌ phantom (ledger P2, §4.4) |

### 4.3 Per-distributor scoping — decide with evidence, not vibes

- Module config is **already per-distributor**: `config/<dist>/<ModuleClass>.php`
  (`Module.php:1052-1056`).
- Session isolation is **already per-distributor** by cookie name (`Distributor.php:263`).
- Every framework-owned table lives in *the Database the app hands it*, prefix-scoped
  (`Database.php:80-81`, prefix applied at `:391-392`/`:357`, `setPrefix :495`):
  jobs = `razy_jobs` (`Queue/DatabaseStore.php:33,:39-43`), sessions table
  (`Session/Driver/DatabaseDriver.php:218-231`), migration tracking
  (`Database/MigrationManager.php:111-144`).
- **No framework table carries a dist column** — `grep 'dist_code|distributor_code|site_code' src/`
  hits CLI/rewrite/skills metadata only (`Application.php:582`, `CaddyfileCompiler.php:371`, …),
  zero DB tables.
- ⇒ **Razy's storage model is "one app-chosen DB per distributor"** (deployment convention,
  never enforced). Verdict (§6): permission tables live in **the DB the app hands the
  module**, with **no `dist_code` column** — adding one would fake tenant isolation on
  top of a substrate that scopes by cookie-name + per-dist config + app-wired DB, and would
  contradict every sibling table. Shared-DB multi-dist deployments use `setPrefix()`.

### 4.4 The phantom `getSharedInstance` (new finding, load-bearing for §7)

`Database::getSharedInstance()` does not exist. Consequences verified by reading: the CLI
queue worker wraps it in `try { … } catch (Throwable)` (`queue.inc.php:47-63`) so
`queue work` **always** degrades to "no database" silently; `queue-admin`'s `status.php:14`
calls it *outside* the inner try (`:22`) — an `Error` there escapes to
`CommandRegistry::executeCommand`'s catch (`:220-222`) → `__onError` → surfaced as an
error path. The published module's `tests/` never execute this line (`grep getSharedInstance
tests/` = 0). The lesson the permissions module must apply: **resolve the DB from module
config (option 1), never from a hoped-for ambient singleton**; and the framework should
close the hole (S1.5 / Q3).

## 5. Where the checks would plug in (all four surfaces exist today)

| Surface | Mechanism (file:line) | Shape it should take |
|---|---|---|
| Controller / module handler | `Controller::api(string): ?Emitter` (`:510`); `Emitter::__call` → `Module::execute` → gate-checked command (`Emitter.php:50-54`, `CommandRegistry.php:121-131`); denied/absent ⇒ `null` (falsy ⇒ fail-closed for free) | `$this->api('razymod/permissions')->can('queue-admin.purge')` — the razit loop (`user.main.php:6-9`) with a nullable-safe `?? false` |
| Routes | middleware rail is live: `Route::middleware` `:131-137` (+`contain()` `:74`), group propagation (`Routing/RouteGroup.php:119,:409,:431-433,:459`), global/module scopes (`Agent.php:387-389` → `Module.php:983` → `RouteDispatcher.php:568,:586`), execution in order global→module→route with `routedInfo` as context (`RouteDispatcher.php:518-549`; context keys `Contract/MiddlewareInterface.php:51-60`); `Module::addRoute(string, string\|Route)` accepts a `Route` entity (`Module.php:690,:694-714`) | v1 canonical = in-handler `can()`; v1.5 = `AuthorizeMiddleware` (`Auth/AuthorizeMiddleware.php:66-88`) attached to `new Route(...)->middleware(…)` once the framework offers an app-wired Gate (else each module's Gate instance diverges — §6) |
| Templates | plugin folders are **per-owner-class, process-global** (`PluginTrait.php:41-44` → `PluginManager.php:83-89`); plugin file = closure returning `TModifier`/`TFunction`/`TFunctionCustom`; the registering controller is injected via `bind($args)` (`Template.php:338-353`); shipped examples `src/plugins/Template/modifier.escape.php:37-64`; production proof a module-provided template function works across modules (`razit-multilang/default/plugins/Template/function.ml.php:8-23` calls `$this->controller->…`) + `registerPluginLoader(PLUGIN_TEMPLATE)` (`Controller.php:704-719`) | `razymod/permissions` ships `plugins/Template/function.can.php` bound to **its own** controller (controller→`api()`→`can()`), registered at its own boot — no copy-paste per consuming module |
| CLI | scripts dispatch through the same block (CLI branch `RouteDispatcher.php:412-417`, request method `'CLI'` `:427`, middleware applies); `Module::addScript :741-746`; announce picks `__onScriptReady` in CLI (`Module.php:751-760`); **no session in CLI** (`Distributor.php:253` + `bootstrap.inc.php:75-80`) → `Gate` denies guests pre-hook (`Gate.php:173-177`) — fail-closed already correct | `can()` in CLI denies unless an actor is *explicitly* supplied (`can(ability, actorId)` or config-listed system actors, §7.5); never ambient |
| Audit | `$this->trigger('permission.denied')->…` (`Controller::trigger :339`, `EventEmitter::resolve :69` broadcast), `Agent::listen :166`/`observe :200`; production precedent `razit-logging` module consumes admin actions in the same ecosystem | event, not a table — auditors are listeners (RZ-001-clean) |

## 6. Options — decide where the line is

| | (i) Framework completes Auth layer, module persists nothing | (ii) Pure module consuming existing Gate | (iii) **Hybrid** |
|---|---|---|---|
| What | core adds Session/Token guards + container-wired Gate + config-driven abilities | `razymod/permissions` ships tables + builds its *own* `Gate` internally + api `can()` | core: declare `Razy\Auth\*` stable, ship the *real* `SessionGuard`, additive `Gate::addBefore()`, correct the lying docblock; module: tables, Gate registration at boot, template plugin, audit event, check helpers |
| "Define once" | ✅ one process Gate | ❌ every consumer that wants middleware/template gating would want *the same* Gate instance; a module-private Gate is invisible to `AuthorizeMiddleware` unless live objects cross `api()` (RZ-008-adjacent, §4.2 row 2) | ✅ core owns the *seam* (who am I), module owns the *policy* (what may I) — one Gate per dist, built once at boot by the module on top of the core-wired guard |
| RZ-008 | ✅ | 🔶 depends on how `can()` reaches middleware/templates | ✅ (data crosses via JSON-ish api() results; the live object never moves) |
| Q1 boundary (framework never owns the user row) | ❌ drifts toward a framework users story; ⚠️ guards still need an identity provider | ✅ | ✅ — guard resolves an **actor id from the app's own session slot**; module maps id→roles; user row stays app-side (§9) |
| Phar constraint (OAuth §2) | ✅ no new deps | ✅ | ✅ (~60 added core LOC) |
| Failure mode | framework invents identity semantics the platform hasn't agreed | two sources of truth the moment core middleware is used | core seam unused until module present → `can()` degrades to deny (nullable `api()` = fail-closed) |

**Recommendation: (iii) hybrid.** It matches the manual's own doctrine
(`manual/07:125-128` — module-owned identity surface, framework-owned primitives), the
OAuth dossier's split (primitives in core, provisioning in modules — OAuth §4), and the
razit production loop minus its security debt. Concretely:

- **Core (framework, additive, ~60–120 LOC):** ① declare `Razy\Auth\*` + the two contracts
  a **stable published surface** under RZ-012 (they are already test-frozen, §1); ② ship the
  *real* `Razy\Auth\SessionGuard` — a `GuardInterface` over a **caller-supplied** resolver
  `fn(): ?AuthenticatableInterface` (≈ `CallbackGuard` + a session-key default), finally
  making `AuthManager.php:31-32` true instead of deleting nothing (ledger P1); ③ additive
  `Gate::addBefore()/addAfter()` appended **lists** (fix §1-hazard-1 without touching the
  pinned single-slot methods); ④ optionally `Razy\Auth\GateFactory`/`AuthManager`
  construction sugar so exactly one Gate exists per distributor (module wires it in
  `__onReady`, RZ-009).
- **Module (`razymod/permissions`, pack-published like `razymod/queue-admin`
  `module.php:12-17`):** tables via migration/ + `Contract` shape; DB via module-config
  connect (§4.2 option 1 — **not** the phantom, §4.4); Gate ability registration at boot;
  `can()/assign()/revoke()/…` API with implemented allow-list (§8); template `function.can.php`;
  `permission.denied` event; `social.user_resolved` listener (§9).

## 7. Proposed contracts and schemas (draft — maintainer sign-off = Q1..Q5)

### 7.1 Tables (Column grammar, `reference(...)` FKs per `Controller.php:628-632`; shipped in `migration/`, shape mirrored by `ORM\Contract`)

| Table | Columns (grammar) | Notes |
|---|---|---|
| `permissions` (ability catalog) | `id=type(int),auto` · `code=type(text)` unique (`perms/queue-admin.purge`) · `label=type(text),nullable` · `module_code=type(text)` · `created_at=type(timestamp),nullable` | the "define **once**" registry; UI/menus read it, `Gate::has` stays in-memory |
| `roles` | `id=type(int),auto` · `code=type(text)` unique · `label=type(text),nullable` · `is_system=type(int)` flag (0/1) | system roles survive `down()`; per-dist meaning comes from *this DB*, no dist column (§4.3) |
| `permission_role` | `role_id=type(int),reference(roles,id)` · `permission_id=type(int),reference(permissions,id)`, composite unique | classic RBAC join; **no deny rows** (allow-list only — consistent with every existing Razy gate: `__onAPICall`, `__onBridgeCall`, queue-admin allow-lists; and with `Gate`'s default-deny `:195-196`) |
| `actor_role` | `actor_type=type(text)` default `'user'` · `actor_id=type(text),reference(users,id)`-shaped · `role_id=type(int),reference(roles,id)`, composite unique | `actor_id` is **`text` on purpose**: `getAuthIdentifier()` is `string\|int` (`AuthenticatableInterface.php:30`) — int user ids and UUID api-principals both fit without pinning the module to the app's PK type; `actor_type` reserves room for service/API actors (CLI, OAuth clients) without inventing a second table |
| `actor_permission` *(optional, off by default)* | same shape + `effect` field reserved but only `'allow'` accepted | escape hatch for one-off grants without role explosion — schema room, zero v1 code (Q5) |

### 7.2 Identity plumbing (the seam, Q1 answer in motion)

Session payload after login = `{actor_type, actor_id, bound_token}` — never credentials
(anti-pattern in §3). The **app/accounts side** builds a `GenericUser` (or its own
`Authenticatable` model) and hands it to the guard; the core `SessionGuard` (S1) wraps the
resolver; `AuthManager` is built once per dist at the permissions module's `__onReady` and
exposes the single Gate. `GuardInterface` still has no login/logout — keep it that way
(hydrate + `setUser`, like `AuthTest.php:1132` pins).

### 7.3 Super-admin escape hatch — explicit, config-first, fail-closed

Per-dist config `config/<dist>/Permissions.php` (`Module.php:1052-1056`):
`'super_actors' => ['user:1', …]` (env `RAZY_SUPER_ADMINS` may extend, never replace —
`BridgeSignature` env-gate precedent). Mechanics = one appended `before` hook
(`Gate::addBefore` S1) returning `true` for listed actors *only when the actor is already
resolved* — the guest-deny pre-check (`Gate.php:173-177`) runs first, so "super" never
applies to unauthenticated requests (matches razit `is_superuser` semantics,
`razit-group/.../auth.php:9`, minus the DB flag ambiguity). Default: absent config = no
super actor at all.

### 7.4 Ability naming

`<vendor-prefix-less-module>.<action>` dots (`queue-admin.purge`, `user.role.assign`);
documented, and validated at `define()` time by the module. Why not razit's tree codes
(`/a/b/c` from `Permission.php:48-55,:155-168`): fine as data, but `Gate::define` keys are
process-global and overwrite silently (`:103-108`) — flat dotted, module-namespaced keys
make collisions auditable; and kebab is reserved for the policy-method map (`:408-411`).

### 7.5 CLI actor semantics

No session ⇒ guard resolves nothing ⇒ `Gate` denies (§5 row 4). Authorized CLI paths pass
the actor **explicitly**: `can($ability, $actorId)` param, or `system_actors` config list
consulted only under `CLI_MODE` (`bootstrap.inc.php:75-76`) — the same posture as the CLI
`bridge` staying operator-trust (`manual/07:138-141`). Scripts get it via `addScript`
routes (`Module.php:741-746`) with no special-casing of the middleware rail.

### 7.6 Caching + worker mode

Lazy per-request memo `actor_key → Set<ability>` (one `prepare()->select()->where()->assign()`
per actor per request, RZ-003 style per `DatabaseStore.php:92-103`), stored on the module
controller; **must clear between requests** in FrankenPHP/Caddy worker mode — the house
pattern is explicit resets (`CallbackGuard::reset :132-136`, `Database::resetInstances
:133-136`, `PluginManager::resetAll :150-153`, `Module::resetForWorker :1073`); cross-request
`Razy\Cache` TTL layer is S5 optional, invalidation-on-write is mandatory before any
cross-request cache exists.

## 8. Check-surface wiring (commands, gates, tests)

Published API (each `addAPICommand` in `__onInit`, handlers IO in `__onReady`+ — RZ-009):

| Command | Contract | Notes |
|---|---|---|
| `can(string $ability, ?string $actorId = null): bool` | current actor (guard) or explicit actor; **never throws** | the hot path; memo-cached §7.6 |
| `can-any(array $abilities, ?string $actorId = null): bool` | mirror `Gate::any` (`Gate.php:249`) | |
| `abilities(): array` | catalog read for UIs | |
| `roles-of(string $actorType, string $actorId): array` / `assign-role(…)` / `revoke-role(…)` | mutations | allow-listed to the *governance* caller, not to every module (below) |
| `define-ability(string $code, string $label = ''): bool` | idempotent catalog insert | modules call it in their own `__onReady` via `api()` after `handshake` (`Controller::handshake :730`, `hasModule :754`) |
| `audit-actor(string $actorKey): array` *(S4)* | last denials for one actor, read side | backs Q-audit surface |

`__onAPICall` allow-list draft (shape = `queueadmin.php:17-22,:56-59` + golden `provider.php:67-73`):

```php
private const API_ALLOW = ['can'=>true,'can-any'=>true,'abilities'=>true,'define-ability'=>true];
private const GOVERN_ONLY = ['roles-of'=>true,'assign-role'=>true,'revoke-role'=>true,'audit-actor'=>true];
public function __onAPICall(ModuleInfo $module, string $method, string $fqdn = ''): bool {
    if (isset(self::API_ALLOW[$method])) return true;                       // reads: any module
    if (isset(self::GOVERN_ONLY[$method]))
        return $module->getCode() === ($this->getModuleConfig()->get('governor') ?? ''); // writes: named only
    return false;                                                            // deny unknown (AGENTS.md trap)
}
```

Route gating v1: document the razit loop — handler line 1 `if ($this->api('razymod/permissions')->can('x') !== true) { /* 403 */ }`
(`!== true` also converts the denied-by-gate `null`, `CommandRegistry.php:204-206`, into a deny — fail-closed on every leg).
Route gating v1.5: once S1 lands the wired Gate, consumers attach the **existing**
`AuthorizeMiddleware($gate, 'x')` to `new Route('handler')->middleware(…)` (`Route.php:131`,
dispatcher `:518-549`) — zero new core surface for that.
Template gating: `function.can.php` (TFunctionCustom like `function.ml.php:8-23`), bound to
the permissions controller via `registerPluginLoader(self::PLUGIN_TEMPLATE)` — usage mirrors
razit `{$var->…}` function call syntax; RZ-004 note in the file: it must return
`'true'/'false'` strings only.
Audit: module triggers `permission.denied` `{actor_key, ability, source:'api'|'middleware'|'template'}`
(`Controller::trigger :339`); `razit-logging`-style listeners subscribe via `listen`
(`Agent.php:166`). Fire-and-forget, never on the hot `can()` allow-path.

## 9. Cross-refs to OAUTH-SOCIALITE-HTTP.md (built on, §-by-§)

- **Q1 there (who owns the user row)** → this dossier takes the same line harder: the
  `permissions` tables reference `actor_id` as `text` and **own no user data** (§7.1);
  the razit-sample proves the loop works with the user row living in an app module
  (`razit-user`) (§3).
- **`social.user_resolved`** (OAuth §7 step 10) → `razymod/permissions` registers one
  `listen`: payload `{actor_type, provider, external_id, display}`; handler (a) mints/looks
  up an actor key **by convention, without a mapping table in v1** (`user:<app-user-id>` —
  the *accounts* module already dedupes identity; permissions must not re-do it),
  (b) grants default-role-if-configured, (c) triggers `permission.actor_resolved` for
  auditors. That is the whole integration — OAuth S5's `razymod/oauth` module gains a
  working "after login, who is allowed to do anything" story **without ever talking to
  users, roles, or permission tables itself**; symmetrically, `razymod/oauth`'s state-token
  pattern (OAuth §7 option C) is the template for this module refusing credentials-in-
  session (§3).
- **Identity hydration gap G12 there** → §7.2 is its second consumer: OAuth hands over a
  *resolved* actor once the app maps provider→user; the permission layer consumes the same
  hydration (`GenericUser`) the native-login app path builds. One hydration contract, both
  entry doors.

## 10. Milestones

Sizing (house convention): XS ≤ ½ d, S ≤ 2 d, M ≤ 5 d. All steps RZ-007-clean, RZ-012
additive-only, RZ-014 (tests ship with code), module steps lint-clean
(`tools/lint-module-discipline.php`).

| Step | Change | Files | Size · tests |
|---|---|---|---|
| **S0 Decide + label** (day 0) | Adopt hybrid as ADR line; add the guard-layer rows to `CLASS-CATALOG.md` with "unwired, test-frozen" caveat; correct `AuthManager.php:31-32` docblock **or** tag it "S1 fixes"; register the `getSharedInstance` phantom in the bug ledger | `architecture/*.md`, `CLASS-CATALOG.md`, `Auth/AuthManager.php` docblock | XS · 0 tests (docs only) |
| **S1 Core seam** | `Razy\Auth\SessionGuard` (resolver-backed `GuardInterface`, session-key default); `Gate::addBefore()/addAfter()` appended lists (single-slot `before/after` untouched — pinned behavior); `GateFactory`/`AuthManager` wiring sugar (one Gate per dist, container-bound at module-container level, **not** Application) | `src/library/Razy/Auth/{SessionGuard,Gate(+methods)}.php`, wiring | **S** · extend `tests/AuthTest.php` in pinned style: hook *list* order + short-circuit, guest still pre-denies, existing 89 tests = regression net; `tests/SessionGuardTest.php` resolver/`reset()` (worker mode) |
| **S1.5 Close the phantom** (parallel) | Either add `Database::shared()`/`getSharedInstance()` **set-once/get** (set by app boot, reset per request in worker mode) or delete all 6 call sites — framework choice (Q3); queue worker stops swallowing a hard miss | `Database.php`, `queue.inc.php:47-63`, `modules/queue-admin/…×5` | XS-S · `tests/DatabaseSharedInstanceTest.php` + make `queue work` store-resolution asserted (currently untested — `grep getSharedInstance tests/` = 0) |
| **S2 `razymod/permissions` skeleton + schema** | module dir (`module.php` like `queue-admin/module.php:12-17`), `migration/` tables §7.1 (Column grammar), `ORM\Contract` mirror, config keys (`super_actors`, `governor`, default roles), DB via config-connect (§4.2 option 1), `__onAPICall` two-tier allow-list (§8) | `modules/permissions/{module.php, default/{package.php, migration/*, controller/permissions.php, controller/api/*}}` | **M** · migration up/down on sqlite + mysql fixtures (`tests/MigrationTest.php` pattern `:1291`); allow-list matrix test per RZ-014 (every command × allowed/denied caller) |
| **S3 Check surface + registration** | boot-time ability catalog load → `Gate::define` per code + one appended `before` hook answering from DB (memo §7.6, reset hook for worker mode); `can()/can-any()/abilities()/define-ability()` handlers; `permission.denied` trigger on denials reached through a gate | `default/controller/*`, wiring into S1's GateFactory | **M** · behavior pins: default-deny, super-actor config-only, memo invalidation on write, `null`-vs-`false` on gate-denied `api()` leg still closes fail-closed |
| **S4 Templates + audit + docs** | `plugins/Template/function.can.php` (TFunctionCustom, `function.ml.php` shape), `registerPluginLoader(PLUGIN_TEMPLATE)`; `manual/11-permissions.md`; razit→permissions migration notes (what `auth()` mapped to); golden-demo update | `default/plugins/Template/function.can.php`, `manual/`, `demos/golden/*` | S · template-render test (allowed/denied/superuser), RZ-004 raw-output note test |
| **S5 Governance polish (only if demanded)** | optional `actor_permission` direct grants, `Razy\Cache` cross-request layer + write-time invalidation, `audit-actor` read API, admin UI **only** to the queue-admin `/ui` ceiling (`queueadmin.php:26-33`) | as needed | S–M · deferred by default; each gate its own decision |

**Do NOT build** (scope-kill list): ABAC/attribute engine or a policy DSL (Casbin-style model
files — the two engine attempts failed to even fetch, and `Gate`'s closure+policy design is
Laravel-semantic and test-frozen, §1); role **inheritance graphs** (razit's tree lives on
the *permission labels* (`Permission.php:48-55`), not on roles — keep roles flat);
deny-rows/`effect` machinery (allow-lists are Razy's entire gate grammar today, §2); a
framework-owned `users` table (OAuth Q1 + RZ-008); per-field/per-column permissions; JIT
access-request workflows; `Gate::inspect()`/`Response::deny()` objects until a real caller
needs the message (fetched Laravel offers them — YAGNI here, §11-fetched); an admin panel
beyond the queue-admin `/ui` bar; login/logout UI (accounts territory); password/2FA flows
(Hash/Authenticator already exist and stay orthogonal).

## 11. Open questions for the maintainer

- **Q1 — Where does the user row live?** (restates OAuth Q1 with the storage facts):
  tables here reference `actor_id` as `text`; the DB is app-provided (§4.2) and per-dist by
  config+cookie scoping (§4.3). *Recommendation*: user row stays **app-side forever** —
  either the app's own table (razit pattern, `razit-user`) or a future `razymod/accounts`;
  `razymod/permissions` v1 binds `user:<id>` keys and nothing else. Framework: never.
- **Q2 — Promote `Razy\Auth\*` to a stable published surface now?** It is test-frozen
  (89 behaviors) and about to get its first real consumer. *Recommendation*: yes — declare
  `Gate`, `AuthManager`, `GuardInterface`, `AuthenticatableInterface`, `GenericUser`,
  `Hash` semver-stable at the next minor under RZ-012 (additive-only thereafter); fix the
  `AuthManager.php:31-32` lie by shipping the real `SessionGuard` (S1), not by deleting the
  line, so `CallbackGuard`'s misnomer stays documented (ledger P3).
- **Q3 — `Database::getSharedInstance()` phantom**: define it (set-once/get by app boot,
  request-reset) or delete the 6 call sites and migrate queue + queue-admin to
  config-connect? *Recommendation*: define it — the CLI `queue work` path genuinely needs
  an ambient DB handle that predates any module, and one honest shared-instance accessor
  kills the "hoping for magic" class of bugs; document it as **app-must-set** with
  fail-loud semantics replacing `queue.inc.php`'s swallowed `Throwable` (`:47-63`).
- **Q4 — Governor for mutations**: is one config-named governor module
  (`governor => 'razymod/accounts'`-style, §8 allow-list) the right trust shape, or does
  each app wire its own governance UI and the module should *also* expose a same-origin
  HTTP surface (queue-admin `/ui` precedent)? *Recommendation*: config-named governor for
  `api()` writes; no mutation HTTP surface in v1 (the double-submit pattern defends a
  *read* shell only, and admin UI is a maintainer decision anyway).
- **Q5 — Direct grants** (`actor_permission`): schema space reserved, zero v1 code. If a
  real app needs them, do they participate in super-admin exemption (they wouldn't) and
  audit (`yes`)? *Recommendation*: keep off until a named use case; the role layer covers
  every razit-era scenario observed in `production-sample/`.

## 12. Doc-drift ledger (code wins; adds to OAuth §10, whose D2 this re-verifies)

| # | Doc/comment claim | Code reality (winner) |
|---|---|---|
| P1 | `AuthManager.php:31-32` docblock registers `new SessionGuard(...)` / `new TokenGuard(...)` | **neither class exists** (re-verified this pass; OAuth ledger D2 stands) — `src/library/Razy/Auth/` = Hash, AccessDeniedException, AuthManager, AuthMiddleware, AuthorizeMiddleware, CallbackGuard, Gate, GenericUser (glob) |
| P2 | `queue.inc.php:52` + `modules/queue-admin` (×5) call `Database::getSharedInstance()` as if it were the blessed pattern (`support/store.php:5-8` *documents* mirroring the CLI) | **no definition anywhere** (`grep 'function getSharedInstance'` = 0; Database.php has no trait/`__callStatic`, full method inventory `:103-778`) → `queue work` always "no database" (swallowed, `queue.inc.php:47-63`); published module's api commands `Error` on first call (`status.php:14` outside its try) — tests never hit it (§4.4) |
| P3 | `Razy\Auth\CallbackGuard` name suggests callback/route gating; nothing documents otherwise | it is an auth `GuardInterface` implementation (`:39,:40-37`); `addCallbackRoute` exists nowhere (grep = 0) |
| P4 | `Gate.php:28-52` usage docblock (`$gate = new Gate($auth); …`) reads like supported wiring | there is **no bootstrap path**: no container binding, no Agent surface, no caller (§0); "usable in principle, wired nowhere" |
| P5 | `manual/` and `CLASS-CATALOG.md` coverage of the guard layer | `manual/` = 0 `Gate`/`AuthManager` mentions (grep); `CLASS-CATALOG.md:52` lists only `Razy\Authenticator`; `manual/07-security-guide.md:130-149` covers API/bridge gates and never the auth layer |
| P6 | `AuthenticatableInterface::getAuthPassword(): string` implies every actor has a password | password-less actors must fake `''` (`GenericUser.php:58-61`) — fine for the guard (validate is caller-owned), a design tax worth one docblock note, not an interface break (RZ-012) |
| P7 | README-level "permission" vocabulary (`manual/README.md:21` promises "permission gates" in 02-modules) | it means `__onAPICall`/`__onBridgeCall` (`manual/07:130-149`) — the word "permission" in this repo means *module gates*, not *abilities*; renaming risks churn, dossier vocabulary suffices |

---

## Sources

### Local (`file:line` read in this pass)

- Auth layer: `src/library/Razy/Auth/Gate.php:54-412` (esp. `:61-108,:139-161,:171-207,:281-301,:342-399,:408-411`);
  `Auth/AuthManager.php:31-32,:46-219`; `Auth/CallbackGuard.php:30-136`; `Auth/GenericUser.php:26-96`;
  `Auth/Hash.php:25-80`; `Auth/AccessDeniedException.php:22-47`; `Auth/AuthMiddleware.php:42-74`;
  `Auth/AuthorizeMiddleware.php:44-88`; `src/library/Razy/Authenticator.php:52-120`;
  `src/library/Razy/Contract/GuardInterface.php:25-70`; `Contract/AuthenticatableInterface.php:23-45`;
  `Contract/MiddlewareInterface.php:26-66`; `tests/AuthTest.php:1-1256` (89 tests; lifecycle `:1100-1157`);
  `tests/AuthenticatorTest.php` (exists).
- Runtime callers absence: greps `AuthManager|Auth\Gate|new Gate|CallbackGuard|AuthMiddleware|AuthorizeMiddleware|GenericUser|Auth\Hash|AccessDeniedException`
  → 30 hits, all self/docblock (`Agent.php:372,402`; `Routing/RouteGroup.php:34,49`);
  `grep 'addCallbackRoute'` = 0; `manual/` Gate/AuthManager = 0; `CLASS-CATALOG.md:52`.
- Gates + middleware rail: `src/library/Razy/Controller.php:173-176,:187-190,:339,:471,:510,:604-695,:704-719,:730-757`;
  `Module/CommandRegistry.php:90-225`; `Module.php:261-263,:690-714,:741-760,:983,:1042-1056,:1073,:1166-1188`;
  `Distributor/RouteDispatcher.php:409-433,:470-573,:586`; `Route.php:34-212`; `Routing/RouteGroup.php:119,:312,:409-459`;
  `Agent.php:55,:129,:166,:200,:279,:387-389`; `Emitter.php:28-54`; `Module/ClosureLoader.php:126-130` via golden-demo cites;
  `demos/golden/provider/default/controller/provider.php:23-73`; `demos/golden/consumer/default/controller/consumer.panel.php:18-62`.
- Session/identity: `Distributor.php:238-299` (esp. `:251-268,:263,:283`); `src/system/bootstrap.inc.php:75-80`;
  `Csrf/CsrfTokenManager.php:80-105`; `Session/Session.php:25`; `Session/Driver/DatabaseDriver.php:210-231`;
  `modules/queue-admin/default/controller/queueadmin.php:15-59`; `…/queueadmin.act.php:14-58`;
  `…/support/csrf.php:1-52`; `…/support/store.php:19-23`; `modules/queue-admin/module.php:12-17`;
  `modules/queue-admin/default/controller/api/{status.php:13-28, job.php:11, action.php:14, purge.php:11}`;
  `production-sample/…/razit-user/default/controller/api/login.php:14-63`, `user.doLogin.php:7-57`,
  `api/{getUser.php:3-4, checkSession.php:3-19, bypass.php:3-5}`, `user.main.php:5-41`;
  `razit-group/default/library/Permission.php:11-193`, `api/{auth.php:4-13, getDB→razit/api/getDB.php:5-6}`;
  `razit/default/controller/api/getDB.php:5-6`.
- Storage: `src/library/Razy/Database.php:43-778` (method inventory; `:80-81,:115-136,:220,:238,:357,:391-392,:450,:495,:547-612`);
  `Queue/DatabaseStore.php:28-117,:258-290`; `Database/MigrationManager.php:57-73,:111-159`;
  `Database/Migration.php:32-66`; `Database/SchemaBuilder.php:43-70,:169`; `Database/Table.php:289-299,:458-499,:769-772`;
  `ORM/Contract.php:10-80`; `ORM/ContractCompiler.php:7-46,:144-155`; `changelog/v1.1.0-beta.md:15-59`;
  `src/system/terminal/queue.inc.php:35-74`; `bridge.inc.php:71`; `grep dist_code` hit-set (`Application.php:582`, `CaddyfileCompiler.php:371`, `SkillsGenerator.php:182-341`, …);
  `src/asset/setup/*` (grep `admin`=0, `database|mysql|sqlite`=0); `playground/sites/testsite/dist.php:1-54`;
  `manual/04-database.md:14-90`; `manual/07-security-guide.md:120-154`.

### External — fetched primary sources

- **Laravel 12.x Authorization**: `https://raw.githubusercontent.com/laravel/docs/12.x/authorization.md`
  ✅ fetched complete (HTML sibling `laravel.com/docs/…/authorization` returned nav-shell only, ⚠️).
  Mapped: gates≈`Gate::define` (exists), `forUser/any/none/authorize` (all exist, §1),
  `Gate::before` super-admin hook (exists but single-slot — §1 hazard), policies ≈ `Gate::policy`
  (exists, incl. kebab→camel method map), `->middleware('can:ability,arg')` ≈ `AuthorizeMiddleware`
  on `Route::middleware` (both exist, uncomposed), Blade `@can` ≈ template `function.can.php`
  (§5). Marked do-not-build: `Response::allow/deny` objects, `inspect()`, `allowIf/denyIf`,
  policy discovery-by-convention, `UsePolicy` attribute, policy DI.
- ⚠️ **Casbin model doc — fetch failed twice** (honest record, second prior-art slot unused
  after it stopped being load-bearing): `https://casbin.org/docs/model/` → redirect to
  `casbin.apache.org` refused → `https://casbin.apache.org/docs/model` → 404;
  `https://raw.githubusercontent.com/casbin/casbin/master/docs/model.md` → 404. No claim in
  this dossier rests on Casbin; the RBAC join shape (§7.1) is instead grounded in the
  in-repo production evidence (`razit-group`/`doLogin.php`) and the fetched Laravel doc.

**Status: complete.** All local claims verified at cited lines; the one failed external
source is named inline (⚠️) and carries no load.
