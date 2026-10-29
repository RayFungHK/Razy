# Changelog

All notable changes to the Razy framework are documented here.  
Each version has its own detailed changelog file in the [`changelog/`](changelog/) directory.

The format is based on [Keep a Changelog](https://keepachangelog.com/) and this project adheres to [Semantic Versioning](https://semver.org/).

---

## [Unreleased]

- **RZ-47** — **Security:** unbalanced parentheses in any Simple Syntax throw (`SyntaxException`, a `QueryException` in the query builder) instead of silently dropping or rewriting a clause (`order('>id)--')` dropped ORDER BY); `bulkUpdate()` SET values get their own placeholders (a same-named `where()` parameter replaced them); NUL refused in JSON paths; `:rzpN` counted only outside quoted literals; Model keys / columns spliced into syntax must be identifiers. **Changed:** `Crypt` refuses an empty key. [unreleased/RZ-47.md](unreleased/RZ-47.md).
- **RZ-48** — **Fixed:** `razymod/apps` and `razymod/permissions` boot on a real distributor: the controller files return an anonymous subclass (`buildController()` accepts only that), the hyphenated commands are camelCase (`assignIds`, `canAny`, `defineAbility`, `rolesOf`, `assignRole`, `revokeRole`, `auditActor`; the Agent refused the old names), and permissions loads its `Service`. A boot test on a real Module + Agent pins all three; RZ-018 now fails a command name the Agent would refuse. apps 0.2.0, permissions 0.3.0. [unreleased/RZ-48.md](unreleased/RZ-48.md).
- **RZ-42** — **Added:** `docs/STYLE.md`, led by the founding rule (one owner per module; its API doc is the contract; changes go through the owner), with one naming rule, one operator table, values only through `:name`, the plugin contract, version folders, typed errors and size caps. Golden Rules **RZ-018** (module `'owner'` + `API.md` documenting every command and event; all 33 in-tree modules now have both) and **RZ-019** (no value glued into a query-builder syntax string), and `tools/lint-style.php` (naming, size caps, strict_types against a shrink-only baseline) in `composer quality` and CI. **Changed:** PHPStan stops ignoring undefined methods / properties wholesale; 7 call sites are baselined one by one. [unreleased/RZ-42.md](unreleased/RZ-42.md).
- **RZ-41** — **Security:** `Crypt` writes a versioned encrypt-then-MAC format (HMAC over version + IV + ciphertext, verified with `hash_equals` before decrypting, separate HKDF-derived AES and HMAC keys); an unrecognised Where syntax operand throws `InvalidOperandException` instead of going into the SQL raw, and a stray `)` no longer drops the whole WHERE (it ran `DELETE FROM t`) (numbers, bare identifiers and allow-listed zero-argument functions such as `NOW()` still work). **Fixed:** two `whereIn` / `whereBetween` (and siblings) calls on one column no longer overwrite each other's values. Also closed: JSON path suffix re-quoted by the driver (a `\'` closed it on SQLite/Postgres), ModelQuery helper columns and reserved `_mq_` bindings, raw Update Simple Syntax tokens and `bulkUpdate()` keys, `JOIN ... USING` columns. **Changed:** `Crypt::decrypt()` returns `null` on failure and refuses the legacy format unless `allowLegacy: true`. **Deprecated:** the pre-RZ-41 Crypt format (migration opt-in only; a legacy MAC does not cover the first block, so re-encryption does not prove integrity), removed in v2.0.0. [unreleased/RZ-41.md](unreleased/RZ-41.md).
- **RZ-39** — **Fixed:** the bootstrap no longer calls `setlocale()` on every request. Under a threaded SAPI (FrankenPHP / ZTS) PHP's request-end locale reset showed other threads `LC_CTYPE=C`. LC_CTYPE is changed only when it is not already the target (PHP 8.2+ starts in `C.UTF-8`, so the defaults never call it), once per process. **Added:** `Razy\Util\Locale`, `RAZY_LOCALE_CTYPE` / config `'locale_ctype'` (candidates or `off`), and a FrankenPHP race check in CI. [unreleased/RZ-39.md](unreleased/RZ-39.md).
- **RZA-A** — **Added:** `razymod/apps` 0.1.0, the first Razy Apps module: app definitions as data (schema v1: entities, fields, relations, views, workflows, permissions, i18n), a validator with caps that refuses with JSON Pointer paths, canonical JSON + `sha256:` content hash, stable ULID element ids, an Excel-like expression language (own parser, typed AST, closed function list, evaluator with a step budget, typed errors, fuzz-tested), a deterministic compiler plan and the weight function. API: `validate`, `compile`, `hash`, `assign-ids`, `weight`. [unreleased/RZA-A.md](unreleased/RZA-A.md).
- **RZ-35** — **Added:** dist.php `modules` pins pick the version folder per distributor (`'*'` set plus tag overrides; folder name, `'*'` or a semver range); `default/` stays the fallback when nothing is pinned. **Changed:** a pin with no matching folder, or an unmet package.php `'require'` constraint, stops the boot with `ModuleVersionException`; one PHP process loads one version of a module; `getVersion()` is the semver number (`getVersionFolder()` is the folder); `VersionUtil` caret/tilde follow Composer. Deprecation: pins used to be ignored, so check existing `modules` entries. [unreleased/RZ-35.md](unreleased/RZ-35.md).
- **RZ-28d** — **Added:** `Fixture::component()`: `ui render` fixtures for `.rzx` components (states are props; `namespaces()`, `vocabulary()`, `rzxFunction()`, `fill()`); `ui list --json` gains `component`. [unreleased/RZ-28d.md](unreleased/RZ-28d.md).
- **RZ-25** — **Security:** `/data` no longer renders active uploads inline. Every extension Apache or Caddy/Go maps to HTML, XHTML, SVG, any XML type, or JavaScript (including `shtml`, `xht`, `xsl`, `xslt`, `rdf`, `mathml`, `mml`, `cjs` and `xbl`) is `Content-Disposition: attachment` plus `Content-Security-Policy: sandbox`. On `php -S`, libmagic does the same for `text/html`, a `/xml` or `+xml` type, or a JavaScript type. A `.php` / `.phtml` / `.phar` under the data mapping, including `/data/note.php/extra`, is bytes or 404, not PATH_INFO into the PHP handler. A directory URL under that mapping (`/data/`, `/data/sub/`) does not serve `index.html`. [unreleased/RZ-25.md](unreleased/RZ-25.md).
- **RZ-28c** — **Added:** `.rzx` in Razy: package.php `'rzx'` namespaces and vocabularies, `.rzx` screens and layouts in `Controller::render()` (optional `$layout` props), `{@rzx}` for `.tpl` pages, Razy functions (asset, importmap, nonce, url, modifiers except raw), dist.php `'rzx'` modes, `compile --rzx`, data-driven vocabulary/profile checks. [unreleased/RZ-28c.md](unreleased/RZ-28c.md).
- **RZ-28b** — **Added:** the `.rzx` compiler, runtime and build cache: compile-time props, slot and markup-safety checks, context escaping by default (`SafeHtml` is the only raw path), components calling components, checksum cache with dev recompile and a never-compile production mode, render errors mapped to `.rzx` lines. [unreleased/RZ-28b.md](unreleased/RZ-28b.md).
- **RZ-31** — **Fixed:** `{@each}` renders every item. The enclosure flag was misspelled, so the body ran once as the last item. A value containing `{$…}` stays text. [unreleased/RZ-31.md](unreleased/RZ-31.md).
- **RZ-28a** — **Added:** `Razy\Rzx\Parser`, the `.rzx` component lexer/parser (JSX-subset markup, control-flow tags, slots, pushes, fixed expression grammar) to an AST with `RZX0nn` line:col errors; parse only, nothing renders yet. [unreleased/RZ-28a.md](unreleased/RZ-28a.md).
- **RZ-29** — **Added:** UI fixtures (`*.fixture.php`, `Razy\View\Fixture`) and `php Razy.phar ui list | ui render`, which render one piece of UI per state and theme to standalone HTML (no network) for pixel goldens. [unreleased/RZ-29.md](unreleased/RZ-29.md).
- **RZ-27** — **Added:** one layout per site (`dist.php 'layout'`, `Controller::render()`), named anchors (`{@anchor}`) and `{@push}` with dedupe, order and the CSP nonce; `{@importmap}` once per response. **Changed (breaking):** a block or `INCLUDE BLOCK` comment inside an open `{@if}` (or other enclosing tag) is a parse error instead of silently ignoring the condition (RZ-26). [unreleased/RZ-27.md](unreleased/RZ-27.md).
- **RZ-30** — **Security:** `{@if}` no longer parses a substituted value as template. `{@if $x}{$x}{/if}` prints `{$secret}` when `$x` is that text. [unreleased/RZ-30.md](unreleased/RZ-30.md).
- **RZ-32** — **Security:** a dist config that omits `auto_escape` now HTML-escapes `{$tag}` output. Sites that relied on the implicit off must set `auto_escape => false` explicitly. [unreleased/RZ-32.md](unreleased/RZ-32.md).
- **RZ-24** — **Changed (breaking, security):** the document root is not a public file tree (`favicon.ico`, `robots.txt`, `sitemap.xml`, and other docroot files now hit the front controller on Apache, `php -S`, and generated Caddy). [unreleased/RZ-24.md](unreleased/RZ-24.md).

## [v1.2.1](changelog/v1.2.1.md) — 2026-09-19

**The Release That Armed a Real Site, and the Two Machine Doors** — the armed session's token-blindness fixed at the root (lazy start; the 419 loop found live on a production-shaped dist and closed with receipts), the MCP server (`php Razy.phar mcp <dist>` — the bridge surface served to AI clients, no new door), and the fail-closed route-ability rail (`Route::can` + `Agent::addAuthzAbility`, inert until declared). Includes two documented retractions: Model casts already shipped complete; `Razy\Auth`'s Gate has no boot wiring (now documented and bridged). Suite 5,565 → **5,605**. Full narrative: [v1.2.1](changelog/v1.2.1.md).

## [v1.2.0](changelog/v1.2.0.md) — 2026-09-18

**The Security Release · Compile-on-Deploy · MODULE-LIFECYCLE L0–L5 · CSRF-RAIL L0–L4 · FORMREQUEST-RAIL · Worker Integrity** — the release the manual had already been advertising as "v1.2+": the armed CSRF door (`'csrf' => 'on'`), deploy-time boot snapshots, derived module readiness with the wizard door, validation's first real consumer, and the worker status/reconnect integrity fixes under everything. Suite 5,441 → **5,565** (+124). Full narrative: [v1.2.0](changelog/v1.2.0.md).

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
