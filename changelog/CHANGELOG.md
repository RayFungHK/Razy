# Changelog

All notable changes to the Razy framework are documented here.  
Each version has its own detailed changelog file in the [`changelog/`](changelog/) directory.

The format is based on [Keep a Changelog](https://keepachangelog.com/) and this project adheres to [Semantic Versioning](https://semver.org/).

---

## [Unreleased]

- **Added** `razymod/permissions` S5 (dossier PERMISSION-MODULE.md — the module's milestone line now reads
  S1–S5 complete, version 0.2.0 RZ-012-minor): each S5 gate decided individually.
  **Cross-request cache (§7.6)**: opt-in `cache_ttl` (default 0 = shipped posture stays zero ambient
  state), `Razy\Cache\CacheInterface` injected (facade supplies `NullAdapter` pre-initialize = fail-safe),
  the §7.6-mandated invalidation-on-write always armed — real writes clear the actor's set, a no-op
  assign deliberately does NOT (nothing went stale), empty deny-sets cache too (fail-closed direction),
  foreign cache values recompute instead of granting, `invalidateActor()` public for governor tools.
  **Opt-in audit trail (§8 read side)**: `audit` config + second migration `permission_audit_log` +
  Contract mirror; the module listens to its OWN `permission.denied` event and best-effort logs gated
  denials; governor-only `audit-actor(key, limit?)` answers newest-first. Event stays primary, default
  off. **Direct grants stay OFF** (Q5 reopens on a NAMED use case, not on a milestone word);
  **admin UI not shipped** (Q4: no mutation surface; read shell never demanded). 8 tests; S2 suite
  updated for the 5th table / two-migration batch / audit-actor governor row.
- **Added** `razymod/permissions` S4 (dossier PERMISSION-MODULE.md): the `{@can}` Template function plugin —
  ships inside the module, self-registers at its `__onInit` (`registerPluginLoader(PLUGIN_TEMPLATE)`),
  enclosure tags `{@can 'a.b' 'c.d'}…{/can}` with quoted/bare/`$var` tokens; deny is server-side
  (enclosed markup never ships), dead DB hides instead of breaking the render (never-throw hot path),
  RZ-004 posture documented and pinned. `canAbilities()` session-bound check on the controller.
  New golden demo `golden/policy` (fail-closed consumer: nullable api + `!== true` + `responseCode(403)`).
  `manual/11-permissions.md` (+README row; 09/10 numbering follows dossier reservations) incl. the
  razit→permissions migration table. **audit-actor deferred S4→S5** (dossier self-contradiction fixed:
  §8 audit is an event; a read side needs the table §8 refuses). Caught en route: `controller->service()`
  had never executed until the S4 real-engine tests — its `require` resolved `support/support/`;
  latent since S3, now paid. 8 tests (real Template render through the module's own registration path;
  PluginManager per-folder memo reset via the documented `Template::resetPlugins()`).
- **Added** migration governance M3+M4 (dossier MIGRATION-GOVERNANCE.md, closing its queue):
  `package.php` `'migration' => 'deploy' | 'manual'` (default manual = historic behaviour;
  `ModuleInfo::getMigrationMode()/getMigrationDeclared()`) — the bulk
  `php Razy.phar migrate <dist>` pass now runs only `deploy`-declared modules, naming a
  module explicitly is the manual sign-off, `--status` still shows everything tagged, and
  a suspect declaration degrades to manual WITH a warning (never silently). Plus the
  fast-path manifest (`razy_migration_meta`, one row per scope): combined file hash stored
  only after a zero-pending pass, invalidated by every rollback/reset, never read or written
  by `force` — a no-op `migrate()` no longer scans applied history or re-hashes files
  (strict query-count pin). `razymod/permissions` declares `migration => 'deploy'`. 10 tests.
- **Added** `php Razy.phar migrate` (dossier MIGRATION-GOVERNANCE.md M2 — the
  unified, deploy-time migration surface): applies pending migrations of every
  module carrying a `migration/` dir in a distributor, one scope per module (M0);
  `--status` renders applied/pending/drift per module and exits non-zero on
  checksum drift (a deploy gate, not a report); `--rollback[=n]` requires an
  explicit module code (mass rollback is not a deploy verb); `--force` is the
  M1 operator escape, documented as never-for-automation. Module DBs resolve via
  the config-connect contract — ambient connection shapes are pinned out by test.
  Web requests never migrate (Q-M2), stated in the command itself. help mirror
  updated; real-phar smoke verified (usage + exit codes). 9 tests.
- **Changed** `MigrationManager` — **M0+M1 integrity floor** (dossier
  MIGRATION-GOVERNANCE.md, maintainer-decided 2026-09): tracking rows now carry
  `scope` (module owner) and `checksum` (sha256 of the file at apply time),
  self-healing `ADD COLUMN` on pre-M0 tables. Every read/write
  (applied/pending/rollback/reset/status/record/remove) filters by scope — the
  E4 footgun (module A's rollback deleting module B's rows via shared batches
  + missing-file skip) is closed and pinned by the exact interleave scenario.
  `migrate()` verifies checksums FIRST and fails loud on drift (edited or
  missing applied files) — `force: true` is the operator-only escape; rows
  predating M1 (`checksum ''`) are unverifiable by design, never guessed.
  `verifyChecksums()` is the public surface for the upcoming `migrate --status`
  (M2). `Controller::getMigrationManager()` auto-scopes by module code.
  Behavior change (decided): previously, a missing applied file migrated on
  silently; one pre-existing test that pinned that leniency was strengthened
  to the new contract. 10 new tests (`tests/MigrationGovernanceTest.php`).
- **Added** `razymod/permissions` **S3** check surface — the S2 allow-list now fronts
  REAL handlers (RZ-014 honored in reverse: commands landed 1:1 onto the pre-pinned
  matrix): `can` (hot path, NEVER throws — dead DB collapses to deny), `can-any`,
  `abilities`, `define-ability` (flat-dotted grammar enforced, idempotent), plus
  governor-only `roles-of`/`assign-role`/`revoke-role` (idempotent grants, unknown
  roles REFUSED never auto-created). Decision grammar guest-pre-deny > super
  (config EXTENDED by `RAZY_SUPER_ADMINS`, never ambient for guests) > DB membership;
  CLI resolves only configured `system_actors` (§7.5); session actor via the module's
  own `session_key`. The per-distributor **Gate** (S1 seam) answers from the DB through
  one decisive before-hook, built lazily — never at boot (RZ-009 cost doctrine);
  gated db-denials fire `permission.denied` (direct `can()` reads stay silent per §8).
  16 tests (`tests/PermissionsCheckSurfaceTest.php`; 51 permission tests total),
  `phpstan.modules.neon` (supplementary module analysis; api-handler `$this`-binding
  limitation documented, coverage by tests instead), discipline lint 0/0.
- **Added** `architecture/MIGRATION-GOVERNANCE.md` — evidence dossier behind the
  maintainer's migration-governance challenge: framework ships the engine with
  **zero** trigger (no CLI command, no upgrade hook, no call sites), no checksum
  (applied-file edits undetectable, `MigrationManager.php:122-139`), one
  process-global tracking table with **no module scope** — module A's `rollback()`
  can delete module B's rows (E4 footgun), per-call cost quantified honestly
  (constant queries, O(n) rows+requires). Proposes M0 scope-column + M1 checksum
  (integrity floor) → M2 `php Razy.phar migrate --status` deploy-time CLI →
  M3 declaration over developer wiring → M4 O(1) fast path. **All 4 questions
  DECIDED same-day by the maintainer (all per recommendation): M0+M1 as one
  patch; web-request auto-migrate banned (CLI-only); checksum fail-loud.**
- **Added** first-party module `razymod/permissions` **S2** (skeleton + schema + gates;
  dossier PERMISSION-MODULE.md milestone, in-tree like `queue-admin`, not yet published):
  four RBAC tables (`permissions` catalog / flat `roles` / `permission_role` / `actor_role`)
  via a driver-branched migration mirroring the framework's own `DatabaseStore::ensureStorage`
  precedent — so up/down run END-TO-END on sqlite, honour the Database prefix (shared-DB
  multi-dist §4.3), and composite UNIQUEs are constraint-enforced, not convention-enforced;
  `actor_id` is a pure text reference (Q1: no FK into any user table, no `dist_code`
  column anywhere); `ORM\Contract` mirrors pin the shape with parity checked against the
  LIVE schema (PRAGMA); config-connect resolver (declared `database.{type,connection}` →
  connected `Database` or explicit null — never the ambient-registry patterns it
  deliberately declines to copy); two-tier `__onAPICall` allow-list LIVE before any command
  ships (Q4: reads open, governance ONLY the config-named governor, empty/absent governor
  denies, unknown denies) — S3 can only plug into this reviewed shape. 35 tests
  (`tests/PermissionsModuleTest.php`) incl. a 27-case gate matrix. Module discipline lint
  0/0, phpstan [OK].
- **Fixed** ledger P8: `manual/04-database.md` advertised `getModuleConfig()->get(...)` —
  **no such method ever existed** (`Configuration extends Collection extends ArrayObject`;
  read API is `['key'] ?? default`). The session's second doc-phantom (after
  `getSharedInstance`), caught the moment S2 code believed the manual; corrected at the
  source with file:line trail.
- **Added** permission-module **S1 core seam** (dossier PERMISSION-MODULE.md; maintainer
  Q1/Q2 decided): `SessionGuard` — persists ONLY the actor identifier in `$_SESSION` and
  hydrates via an app-provided resolver closure (framework never owns the user row; garbage
  session values fail closed to guest; no session started (CLI) = request-lifetime storage,
  no exceptions, no leaks); `Gate::addBefore()/addAfter()` — multi-subscriber appended lists
  so modules compose instead of displacing (the single-slot `before()` keeps its pinned
  last-writer-wins semantics; first non-null appended `before` short-circuits incl. after
  hooks; appended `after`s chain, last non-null wins); `GateFactory` — one memoized Gate
  per distributor with `flush()` for worker mode. **Declared published surface (RZ-012,
  additive-only)** across `Razy\Auth\*` — manual §6 vocabulary subsection + CLASS-CATALOG
  appendix close ledger P5/S0; the `AuthManager` docblock's fictional `TokenGuard` example
  is replaced by the real `CallbackGuard` (P1 fully closed). Still true and stated: the
  core wires **nothing** — Gate population stays app bootstrap's job until
  `razymod/permissions` (S2+) ships. 21 new tests (SessionGuard 11, hook-list/factory 10);
  the pre-existing 89-test Gate/Auth regression net passes untouched.
- **Fixed** phantom `Database::getSharedInstance()` (dossier PERMISSION-MODULE.md P2): the method
  had **6 callers and zero definitions** — `queue work` always silently degraded to "no
  database", and the PUBLISHED `razymod/queue-admin` (v1.0.0/1.1.0, registry-signed) errored on
  first call to its `status/job/act/purge` commands. Defining it framework-side retroactively
  repairs the released module — no republish needed. Contract (stricter than the deprecated
  lazy `getInstance()`): shared = **registered AND connected** (`isConnected()`, the
  success-only flag — not `getDriver()`, which can hold an assigned-but-failed driver);
  never creates instances; `null` when unavailable, matching the callers' explicit
  null-degradation discipline. SQLite-backed tests (`tests/DatabaseSharedInstanceTest.php`,
  6) pin all of it, including worker-reset semantics. Known adjacent gap (stated, not faked):
  the queue CLI establishes no connection itself — no CLI DB-config layer exists yet; this fix
  makes resolution honest, and `queue work` becomes fully useful in any app that connects
  `'main'` in its bootstrap (the documented convention, as `database_demo` does).

## [v1.1.0-beta](changelog/v1.1.0-beta.md) — 2026-09-12

**Signed Official Registry · Scheduler · ORM Contracts · Route Coexistence · Ops Pack** — the full 2026-07/09 line (24 commits, 5,238 tests), details in [v1.1.0-beta](changelog/v1.1.0-beta.md).

- **Added** live official registry with zero-config resolution, fail-closed SHA-256 on all four fetch paths (`PackageVerifier`), and **Ed25519-signed index** verified before `json_decode` (`PackageSignature`, pinned phar-asset key, `[SIGNED]/[UNVERIFIED]/[SIGNATURE INVALID]` banners, `sign` CLI, auto-sign on `pkg publish --push`) — dossier G4/S5 closed
- **Added** Scheduler: POSIX `CronExpression`, fluent jobs, File/Redis overlap locks, `schedule` CLI (48 tests); `GET /_razy/health` + `/_razy/metrics` Prometheus endpoints answered pre-dispatch (APCu-shared counter, K8s HPA wired)
- **Added** declarative ORM `Contract` + compiler (create-generate, snapshot-drift) and visibility packs (named serialisation views); **fixed** `with()` honoured by `first()`/`find()`; deprecated never-executable `getMaxStatement`
- **Added** same-host route coexistence Phases 0–3: declared `exclude_paths`, Caddy claim scoping + FM-5 health handle, functional FM-2 route audit in `validate`, host claim summary in `rewrite`
- **Fixed** zip-slip across all extraction paths (`ArchiveSafety`, HTTPS-only transport); **added** bridge HMAC envelope (`BridgeSignature` + `RAZY_BRIDGE_SECRET`) and the first gate-hook security tests
- **Added** `WorkerPool` (persistent workers) + `RedisQueueStore`; **deprecated** `ThreadManager::spawnPHPCode()` (eval child) in favour of `spawnPHPFile()`/`WorkerPool`
- **Added** i18n backbone (`Translation`: Translator zh-Hant-first, FileLoader, Pluralizer) and Redis session driver (native TTL, SCAN GC)
- **Fixed** template modifier arguments never reached plugin code; +5 modifiers, `paginate`, plugin ecosystem round
- **Added** first-party `modules/` home + `razymod/queue-admin` published to the registry (core + self-contained HTML shell with guarded POST actions); packager now resolves first-party modules by declared `module_code`
- **Added** non-root container + `deploy/` pack (worker Dockerfile, K8s manifests incl. HPA, benchmark), Golden-Rules discipline lint scanner, CI `redis-integration` job, code-verified manual + AI rules pack
- **Fixed** `matchDomain()` case-insensitivity, host-only session cookies, `search` module-code display


## [v1.0.3-beta](changelog/v1.0.3-beta.md) — 2026-05-29

**Subdirectory Sessions & Module Routes** — Cookie scoping, site URL tidy, and `/library/*` rewrite fix.

- `RELATIVE_COOKIE_PATH` constant; session cookies scoped to `RELATIVE_ROOT` in Distributor and Standalone
- `Distributor::getSiteURL()` returns `PathUtil::tidy()`-normalized URLs
- `htaccess.tpl` skip-list no longer blocks `library` module routes
- `Application::updateSites()` registers secondary/wildcard domain keys in `multisite[]`
- `Application::matchDomain()` wildcard match uses hostname without port suffix
- `Agent::bind()` restores internal-only closure bridge for `$this->method()` (`Controller::__call()` registry)

## [v1.0.2-beta](changelog/v1.0.2-beta.md) — 2026-02-28

**Security Hardening & Package System** — Full security audit (44 fixes) and standalone package infrastructure.

- Full codebase security audit: 4 critical, 10 high, 13 medium, 7 low — all resolved
- SQL injection protection on dynamic table/view names (`Database.php`, `Statement.php`)
- Unsafe deserialization blocked in `FileAdapter.php` (`allowed_classes => false`)
- SSRF prevention: `CURLOPT_PROTOCOLS` restricted to HTTP/HTTPS in `HttpClient.php`
- Secret exfiltration removed from `Authenticator.php` (Google Charts QR API → local `otpauth://` URI)
- Dashboard token authentication, path traversal protection, CLI proxy hardening
- Null byte rejection in `FileReader.php`, anchored CORS regex in `XHR.php`, OAuth2 state verification
- New `PackageManifest`, `PackageRegistry`, `PackageRunner` classes for `.phar`-based standalone packages
- `pkg` CLI command with `list`, `info`, `stop`, `install`, `publish`, `run` sub-commands
- Dashboard package: 6-tab web UI, 15+ API endpoints, real-time monitoring
- Standalone `publish` command removed — consolidated into `pkg publish`
- Subdirectory URL prefix: `RELATIVE_ROOT` / `RAZY_URL_ROOT` use strict `DOCUMENT_ROOT` prefixing with `dirname(SCRIPT_NAME)` fallback so asset URLs (e.g. `getAssetPath()`) stay under paths like `https://localhost/abc/webassets/…`
- 4,823 tests, 8,615 assertions, 0 failures, 87 skipped

## [v1.0.1-beta](changelog/v1.0.1-beta.md) — 2026-02-27

**Worker Optimization & Tenant Isolation** — Performance and multi-vendor safety.

- 37x throughput improvement in FrankenPHP worker mode via boot-once architecture
- 5x faster than Laravel Octane (Swoole) on static route, template render, and DB read
- Multisite worker mode with distributor caching and config fingerprint hot-reload
- Cross-vendor module namespace isolation: 5 collision vectors fixed (config path, asset URL, API registration, closure prefix, rewrite dedup)
- DI container security hardening with `@throws SecurityException` on `make()`
- Git pre-commit hook (CS Fixer + PHPStan) to prevent CI failures
- 4,794 tests, 8,509 assertions, 0 failures, 87 skipped

## [v1.0-beta](changelog/v1.0-beta.md) — 2026-02-26

**First Public Beta** — Open-source readiness release.

- Version bump from `0.5.4` to `1.0-beta` across all source, config, and documentation files
- 4,564 tests, 8,178 assertions, 102 test classes, 0 skipped
- Composer-compatible package management documentation (prerequisite, compose workflow, per-distributor isolation)
- `build.php` updated with CLI path arguments for PHAR export
- Full open-source governance: LICENSE, CONTRIBUTING, CODE_OF_CONDUCT, SECURITY, issue/PR templates
- Docker infrastructure: production, dev, and test compose files with GHCR publish CI
- Standalone class extraction (`Razy\Distributor\Standalone` + `DistributorInterface`)
- 62 documentation HTML files and all wiki pages updated to v1.0-beta

## [v0.5.4](changelog/v0.5.4.md) — 2024-12-27 → 2026-02-16

**Post-build 238** — Major expansion release.

- Multi-database driver architecture (MySQL, PostgreSQL, SQLite)
- Async threading system (`Thread`, `ThreadManager`, `Mailer::sendAsync()`)
- Cross-distributor bridge command system for inter-process RPC
- FrankenPHP worker mode support
- `FlowManager` / `Flow` / `Transmitter` renamed to `Pipeline` / `Action` / `Relay` for clarity
- Complete Pipeline API rewrite: `pipe()`, `then()`, `execute()`, `when()`, `tap()`
- `PLUGIN_FLOWMANAGER` → `PLUGIN_PIPELINE` constant rename
- Repository-based module management (`install`, `pack`, `publish`, `sync`, `search`)
- OAuth2 / Office365 SSO / SSE classes
- Native YAML parser/dumper (no extensions required)
- Fluent `TableHelper` / `ColumnHelper` replacing `Table\Alter`
- Skills documentation generator CLI command and templates
- Comprehensive PHPUnit test suite (102 test classes, 4,564 tests)
- 16 new library classes, 11 new CLI commands, 30 new source files

## [v0.5.0](changelog/v0.5.0.md) — 2024-10-27 → 2024-12-27

**Builds 220–238** — The v0.5 milestone.

- Simplified application lifecycle (`__onInit` → `__onLoad` → `__onRequire` → `__onReady` → `__onDispose`)
- Introduced `Pipeline` / `Action` system (originally `FlowManager` / `Flow`, replacing `Action` / `Validation`)
- New `WRAPPER` template block type
- PSR-compliant autoloading and dependency-ordered module loading
- Route entity for complex routing with data containers
- View tables, data folder mapping, enhanced `.htaccess` rewrite engine

## [v0.4.4](changelog/v0.4.4.md) — 2024-10-15

**Build 219** — Transitional release preparing for v0.5.

- Added `Route` entity and execution method tracking
- `Controller::__onEntry()` event
- Groundwork for CLI-based module activation and APCu caching

## [v0.4.3](changelog/v0.4.3.md) — 2023-11-29 → 2024-02-14

**Builds 213–218** — PHP 8.2+ upgrade, template plugin overhaul, Action/Validation workflows.

- Upgraded to PHP 8.2+
- Template plugins restructured: `TFunction`, `TFunctionCustom`, `TModifier`
- `Action`, `Action\Plugin`, `Action\Validation` classes
- `Statement\Plugin` system for preset statements
- Extensive database and flow enhancements

## [v0.4.2](changelog/v0.4.2.md) — 2023-11-24

**Build 212** — Module package restructure and versioning.

- Module file structure changed (`default` folder convention)
- `vendor/module` code format enforced
- Extracted `ModuleInfo` from `Module`
- Terminal `commit` command for module versioning
- Removed `unpackasset`; added `rewrite` command

## [v0.4.1](changelog/v0.4.1.md) — 2022-09-29 → 2023-04-11

**Builds 206–211** — Major refactoring, Mailer, WebSocket, API overhaul.

- Controller changed to anonymous class
- Module code format: `vendor/package`
- API Emitter, `Mailer` class, `WebSocket::Server`
- `Controller::fork()`, Distributor `await` logic
- CLI script events, shadow assets

## [v0.4.0](changelog/v0.4.0.md) — 2021-08-29 → 2022-09-24

**Builds 194–205** — Inaugural public release.

- Core architecture: routing, module lifecycle, template engine, database abstraction
- CLI tooling foundation
- WhereSyntax, FQDN matching, STOMP protocol
- Template function tags, parameter extensions
