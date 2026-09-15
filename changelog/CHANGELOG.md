# Changelog

All notable changes to the Razy framework are documented here.  
Each version has its own detailed changelog file in the [`changelog/`](changelog/) directory.

The format is based on [Keep a Changelog](https://keepachangelog.com/) and this project adheres to [Semantic Versioning](https://semver.org/).

---

## [Unreleased]

- **Added** OAuth provider pack — **S3 (dossier)**: `Security\OAuth\Provider\GithubProvider` (S256-only, UA-bearing
  user calls, verified+primary email fallback via `/user/emails` — email as CONTACT data), `GoogleProvider`
  (`access_type=offline`, optional `prompt=consent`/`hd`; identity is **`sub`, not email**, `legacy_sub` still
  identifies), `MicrosoftProvider` (tenant-aware Entra endpoints, Graph field-selection, Graph object id — not the
  UPN — as identity, hints, sign-out URL). New `OAuth2::verifyIdTokenClaims`: audience exact-match, absolute expiry,
  issuer-regex, optional nonce/hd binds — and honest by law: it CHECKS STRUCTURE, never verifies signatures (full
  JWK machinery stays on the dossier's Do-NOT-build list; results may say "claims checked", never "signature
  verified"). `Razy\Office365SSO` re-expressed on the hardened client (Q3: name kept; its last two cURL sites died —
  framework-wide, hand-rolled cURL now exists ONLY inside `HttpClient` and the excluded `SSE` streamer). 19 fixture
  tests (`OAuthProvidersTest`), zero sockets.
- **Added** OAuth 2.0 core — **S2 (dossier)**: new `Razy\Security\OAuth\*` namespace — `OAuth2` (authorization-code
  flow: PKCE S256 always-on per RFC 7636, signed single-use `state` per Q2 option C, RFC 6749 §5.2 error mapping,
  exact-match registered `redirect_uri`, state verified BEFORE any network call, Basic or body client auth,
  Content-Type-aware token parsing — GitHub's urlencoded default finally works), `StateSigner` (HMAC state +
  `hash_equals`, provider/redirect binding, TTL; PKCE verifier custody via Cache, never through the browser;
  cacheless configuration fails loud), `TokenResponse` (relative `expires_in` becomes an absolute deadline),
  `OAuthConfig`, `ProviderInterface` + `ProviderRegistry`. Zero dependencies (RZ-015), zero network in tests —
  28 new `OAuth2CoreTest` cases include the RFC 7636 Appendix B vector. The legacy `Razy\OAuth2` keeps its name
  (Q3) with its internals replaced by the hardened HttpClient (transport reasons now reach `OAuthException`;
  urlencoded token bodies no longer die in `json_decode` — that bug surviving this long is the dossier's own
  evidence). The phantom `Razy\OAuth2` constructor documented in `tests/Razy-Feature-TestCases.md` §48 is
  corrected to the real signature in the same commit (signed Q3).
- **Changed** CLI download doors — **S1 caller migration completed**: `install` (safe-fetch helper + phar download +
  dependency download), `pkg install` and `sync` artifact downloads now run through the hardened `HttpClient`
  (transport reasons reach the operator instead of bare `HTTP 0`; sizes measured from the body). The `install`
  command's curl-LESS-environment stream fallback survives by design (the client requires the extension). The only
  hand-rolled cURL left in the framework: the deprecated `OAuth2`/`Office365SSO` internals (retired by their S2/S3
  rewrite, per signed Q3) and `SSE` long-lived connections (excluded — different door shape).
- **Changed** registry/publish HTTP — **S1 caller migration (mainline)**: `publish.inc.php` (all ten GitHub API
  sites), `RepoInstaller` (4 JSON reads + 2 HEAD probes + the streaming archive download), `RepositoryManager`
  index fetches and `PackageManager\HttpTransport` (metadata reader + file download) now run through the
  hardened `HttpClient` — hand-rolled cURL in these files is gone (5 source-pin tests guard the door). Enabled
  by additive client options: `raw_body` (verbatim octet-stream uploads), `sink` (stream-to-file, no
  whole-archive-in-RAM; internally-opened sinks always closed) and `progress` (modern XFERINFO callback).
  Failure paths got strictly more informative (transport reasons reach the operator; unreachable repositories
  now notify instead of silently nulling); disclosed wire diffs in code comments. Remaining raw-cURL by design:
  `OAuth2`/`Office365SSO` internals (S2/S3 rewrite per signed Q3) and `SSE` long-lived connections (not this
  door's shape); `install`/`pkg`/`sync` download sites follow next.
- **Added** `Razy\Http\ClientInterface` (injectable narrow seam: `get/post/put/patch/delete/head/options/send`)
  and `Razy\Http\HttpTransportException` — **`HttpClient` hardening, OAuth dossier S1 first slice**: HTTPS-only
  default gate reusing the shared `ArchiveSafety::isSecureUrl` primitive (one policy with the package paths;
  sole escapes `allowInsecureTransport(true)` or `RAZY_ALLOW_INSECURE_TRANSPORT=1`, per signed Q5); cURL-level
  failures now throw instead of returning the old fabricated status-0 response (fail-loud, same doctrine as
  the `queue` rework; verified zero readers of the old sentinel); timeouts floored at 1s (`timeout(0)` can no
  longer mean "wait forever"); `MAXREDIRS` 5→3; `HttpResponse::data()` parses bodies by Content-Type
  (JSON **and** `x-www-form-urlencoded` — the GitHub token-endpoint shape a JSON-only parser dropped);
  `redirect(302)` exact-status helper, zero-arg `redirect()` unchanged. 12 tests, zero network.
  (Caller migration onto the client = S1 continued, next.)

- **Added** RZ-015 — framework core keeps **zero third-party runtime dependencies** (rules-doc section +
  AGENTS.md table): PSR-18/7 interop lives in modules or standalone packages. OAuth dossier sign-off
  (Q1–Q5 all per recommendation, 2026-09-17): guard-seam-only identity + `social.user_resolved`, signed
  stateless `state` default, `OAuth2`/`Office365SSO` names kept but internals to be replaced (labels
  applied in CLASS-CATALOG), insecure transport single escape `RAZY_ALLOW_INSECURE_TRANSPORT=1`, provider
  secrets in env. ADR-1 recorded in PORTING-VALUE.md; build scope S0–S3+S5 authorised (S4 OAuth 1.0a
  stays deferred). Docs/policy only — zero code touched.
- **Changed** `queue` CLI — **fail-loud rework** (dossier PERMISSION-MODULE.md Q3 tail, the half of the
  P2 phantom the S1.5 fix left open): the resolver no longer swallows `Throwable` into one anonymous
  "check database connection" line that exit(0)'d. Failure class 1 — no registered-AND-connected shared
  instance — names the actual gap ("The queue CLI opens no connection itself") plus the bootstrap fix;
  failure class 2 — store construction throws — surfaces class + message verbatim. All five subcommands
  (`work`/`once`/`status`/`clear`/`retry`) `exit(1)` on resolver-null, retry's usage error too; the dead
  `class_exists(QueueManager::class)` guard is gone. The original phantom symptom — a worker "running"
  forever against nothing while reporting success — is now structurally impossible. 5 source-pinned
  tests (`tests/QueueCommandTest.php`, MigrateCommandTest house style).

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
