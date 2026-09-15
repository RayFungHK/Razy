# Changelog

All notable changes to the Razy framework are documented here.  
Each version has its own detailed changelog file in the [`changelog/`](changelog/) directory.

The format is based on [Keep a Changelog](https://keepachangelog.com/) and this project adheres to [Semantic Versioning](https://semver.org/).

---

## [Unreleased]

- **Added** MODULE-LIFECYCLE L2 — the operator surface. `php Razy.phar module status <dist>` renders the
  derived-state table (code, version, provision, enabled, pending, ready) with deploy-gate exit codes: a
  pending migration or an UNREACHABLE ledger exits non-zero — the gate refuses what it cannot see.
  `module enable|disable <dist> <code>` writes `config/<dist>/modules.php` through `Configuration`
  (ghost module names refused — enable-list entries for absent modules are never written); `disable`
  refuses when a live module `require`s the target and names the dependents, `--force` being the
  operator-grade override. There is deliberately no install/uninstall verb. Dogfooded live on the
  playground, where the run caught two real bugs the tests then pinned: the READY column (and the L1
  predicate with it) had accepted a module blocked mid-require — `standby()` leaves `Processing`, which a
  "bad states" blacklist let through — both now take the POSITIVE whitelist `[InQueue, Loaded]`; and the
  dependent-refusal proved its worth against the very `require` L0 had just un-deadened. Suite 5,461 →
  5,470 (`tests/ModuleLifecycleL2Test.php`, incl. the enable-list file round-trip contract between the
  write door and the boot-time read).
- **Added** MODULE-LIFECYCLE L1 — readiness is DERIVED, never stored. `Distributor::moduleReady('vendor/mod')`
  answers `ready := declared migrations all applied` from the M0–M4 ledger (M4 manifest fast path first,
  new `MigrationManager::isUpToDate()`), memoized in-process only; a loaded module without a `migration/`
  directory is vacuously ready with zero DB touch, and an unreachable ledger THROWS with its named cause —
  a quiet false here is how the ERP's six `$installed` flags were born. `package.php 'provision' =>
  'deploy'|'wizard'|'none'` parses with the M3 degradation shape (unknown → 'deploy', the web-never-migrates
  side) and `validate` errors on suspect values; `'provision'` joins the closed key set. The enable-list lands
  at `config/<dist>/modules.php` (Q5: absent file = everything listed is enabled — today's sites behave
  byte-identically): an explicit `false` skips boot via the resurrected `ModuleStatus::Disabled` (zero
  assignments since the enum was born; `Module::disable()` is its first writer), a disabled `require` dep
  blocks dependents through the L0 warning, and zombie enable-list entries name themselves. The config-connect
  resolver became the ONE door — `Database\ModuleDatabaseConnector` — shared by `migrate` and the predicate,
  with distinct instance-name prefixes. Suite 5,450 → 5,461 (`tests/ModuleLifecycleL1Test.php`).
- **Added** MODULE-LIFECYCLE dossier L0 companion pack — the loud-failure trio plus two new Golden
  Rules. `Emitter::has('cmd')` is now the sanctioned availability probe (`Emitter.php:68` → `Module::hasAPICommand`
  → `CommandRegistry::has`, mirroring `executeCommand`'s registration lookup exactly); `method_exists()` on an API
  object is documented as the always-false dead-code trap the ERP audit proved in production. A module left
  unqueued by an unsatisfied `package.php 'require'` now warns loudly, naming the skipped module, the missing
  dependencies, and the `require`-vs-`requires` typo hint (`Distributor.php`, mirroring the await-unresolved
  warning that previously stood alone). `validate` fails loud on unknown `package.php` keys — the closed set is
  `ModuleInfo::PACKAGE_KEYS`, with a did-you-mean suggestion — after the audit caught our own tree shipping dead
  `label`/`required`/`type`/`routes` zombies (all purged; the markdown_consumer dependency went from
  silently-dead to real). **RZ-016**: migrations run only at the deploy door — the lint flags
  `getMigrationManager()` in module code (the ERP's `getMigrationManager` API-command door is the named
  violator). **RZ-017**: cross-module class imports (`use`/FQCN of a sibling module's namespace) close the
  RZ-001 blind spot — the new structural lint pass pre-reads `module.php` manifests and caught 104 live hits
  on the ERP tree on first run. Suite grew 5,441 → 5,450 with `tests/ModuleLifecycleL0Test.php`.

## [v1.1.0-beta.2](changelog/v1.1.0-beta.2.md) — 2026-09-17

**OAuth 2.0 Core (PKCE S256 + Signed Single-Use State) · One HTTP Door · RZ-015 Zero-Dependency Law · Queue Fail-Loud** — the full OAuth dossier line (11 commits, 5,441 tests), details in [v1.1.0-beta.2](changelog/v1.1.0-beta.2.md).

- **Added** `Razy\Security\OAuth\*` — authorization-code flow core: PKCE S256 always-on (`plain` never offered or accepted), signed single-use `state` (Q2 option C; PKCE verifier custodied in `Cache`, NEVER through the browser), state verified BEFORE any network, RFC 6749 §5.2 error mapping only after verification, exact-match `redirect_uri`, RFC 8707 refresh; provider pack `GithubProvider` / `GoogleProvider` (identity is **`sub`, not email**) / `MicrosoftProvider` (Entra, Graph object id); `OAuth2::verifyIdTokenClaims` enforces the G11 honest label — claims checked, signature NEVER verified. 47 zero-socket tests, RFC 7636 Appendix B vector pinned
- **Added** `razymod/oauth` 0.1.0 — `/authorize`/`/callback` routes (302, never the 301 `Controller::goto`), per-dist config carrying env var NAMES only (Q5), implemented `__onAPICall` allow-list with one read-only command, Q1 enforced as schema (`package.php` without any migration key — no users table, test-pinned); `manual/09-social-login.md`; module-discipline lint 0/0
- **Changed** every outbound framework HTTP call now runs through ONE hardened door — HTTPS-only default (+ `RAZY_ALLOW_INSECURE_TRANSPORT` operator escape), mandatory timeouts floored at 1 s, redirect cap 3 judged at the final response, `raw_body`/`sink`/`progress` options; new `ClientInterface` injectable seam + `HttpTransportException` named causes; `publish.inc.php` ×10, `RepoInstaller` ×7, `RepositoryManager` (unreachable repos now NOTIFY instead of silent null), `PackageManager\HttpTransport`, and the `install`/`pkg`/`sync` downloads all migrated and source-pinned — framework-wide `curl_init` survives only in `HttpClient`, the excluded `SSE` streamer, and one environment probe; legacy `OAuth2`/`Office365SSO` heart-swapped (Q3: names kept), GitHub-shaped urlencoded token answers fixed
- **Added** RZ-015 as law — framework core carries zero third-party runtime dependencies forever (ADR-1 in `PORTING-VALUE.md`, rule table rows); the ~700-line OAuth core shipped with `require: 0` unchanged — the law's first real test, passed
- **Fixed** queue CLI silent-zero — all five subcommands now name the missing-database gap explicitly and `exit(1)`; a worker "succeeding" forever against nothing is structurally impossible (5 source-pinned tests)
- **Fixed** `tests/Razy-Feature-TestCases.md` §48 phantom constructor and phantom array-parameter calls corrected to the real signatures

## [v1.1.0-beta.1](changelog/v1.1.0-beta.1.md) — 2026-09-17

**Migration Governance M0–M4 · razymod/permissions S1–S5 · Phantom Repairs** — the full 2026-09 line (14 commits, 5,365 tests), details in [v1.1.0-beta.1](changelog/v1.1.0-beta.1.md).

- **Added** migration governance end to end (dossier MIGRATION-GOVERNANCE.md, all questions maintainer-decided): module-scoped + sha256-checksummed tracking with fail-loud drift (M0+M1), the deploy-time `php Razy.phar migrate <dist>` CLI (`--status` as deploy gate, explicit-code rollback, operator-only `--force`) (M2), `package.php` `migration => 'deploy'` declarations — bulk pass runs declared modules only, web requests never migrate (M3), and an O(1) manifest fast path (M4)
- **Added** first-party `razymod/permissions` — the complete dossier line S1–S5, published to the official registry at 0.2.0: `SessionGuard` / `Gate::addBefore/addAfter` / `GateFactory` auth seam (framework owns the seam, never the user row, Q1); four RBAC tables via end-to-end-tested driver-branched migrations + two-tier `__onAPICall` allow-list (governor-only mutations, Q4) + config-connect DB resolver; never-throw check surface (`can`/`can-any`/`abilities`/`define-ability` + governor mutations, `permission.denied` gate event); self-registering `{@can}` Template plugin (deny never ships); opt-in cross-request cache with mandated invalidation-on-write + opt-in audit trail with governor-only `audit-actor`; `manual/11-permissions.md`, `golden/policy` fail-closed demo, razit→permissions migration table
- **Fixed** two doc-phantoms, each caught the moment code believed the docs: `Database::getSharedInstance()` (6 callers, ZERO definitions — defined framework-side, retroactively repairing published `razymod/queue-admin`) and `Configuration->get()` (never existed — ArrayAccess only; manual/04 corrected at source, ledger P8)

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
