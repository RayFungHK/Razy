# Changelog

All notable changes to the Razy framework are documented here.  
Each version has its own detailed changelog file in the [`changelog/`](changelog/) directory.

The format is based on [Keep a Changelog](https://keepachangelog.com/) and this project adheres to [Semantic Versioning](https://semver.org/).

---

## [Unreleased]

**Security hardening & scale-readiness** (2026-07, audit findings §S2/§S3/§checklist)

- **Fixed** zip-slip (audit §S2): new `Razy\ArchiveSafety` validates every ZIP entry
  name (`..`/absolute/drive/wrapper/NUL) *before* `extractTo`, purges symlinked
  extractions, randomises temp dirs at 0600, and rejects non-HTTPS distribution URLs
  (opt-out: `RAZY_ALLOW_INSECURE_TRANSPORT=1`). Wired into `PackageManager` and
  `RepoInstaller`; 52 unit tests (`tests/ArchiveSafetyTest.php`).
- **Added** bridge HMAC (audit §S3, roadmap v1.2): `Razy\BridgeSignature` signs a
  canonical envelope (source+module+command+args+ts+nonce, ±60s, `hash_equals`);
  `Module::executeBridgeCommand` denies unsigned calls when `RAZY_BRIDGE_SECRET` is set.
  11 unit tests (`tests/BridgeSignatureTest.php`).
- **Added** `GET /_razy/health` liveness endpoint (`Razy\Health`): answered in
  `main.php` before Application boot (no dist/module/session side effects); default
  payload is probe-safe (status/uptime/ts); verbose (version/php/mode) behind
  `RAZY_HEALTH_VERBOSE`; deep checks behind `RAZY_HEALTH_TOKEN`. 6 unit tests.
- **Fixed** shipped Docker image runs as root: `.docker/Dockerfile` now builds/runs as a
  non-root `razy` user, enables OPcache (immutable-phar settings), and HEALTHCHECKs
  `/_razy/health`; production worker image + K8s manifests + distributed benchmark in
  new `deploy/` (deployed sizing anchored on measured RPS in `benchmark/results/`).
- **Changed** `PackageManager` extraction dirs created 0700 (was world-writable 0777).

**Market-standard features** (2026-7, gap-closure round vs. mainstream frameworks)

- **Added** Scheduler subsystem (`Razy\Scheduler`): `CronExpression` (5-field POSIX with
  month/day names, wrapped ranges, `a/n`, correct day-OR-dom semantics, timezone-deterministic),
  `Scheduler`/`Job` fluent API (`call`/`command`, `everyMinute`…`monthly`, `dailyAt`,
  `withoutOverlapping`, `disabled`), and `Lock\FileLock`/`Lock\RedisLock` overlap guards
  (Redis uses compare-and-delete so an expired lock is never stolen). New `schedule` CLI
  (`run`/`list`/`test`) reads `scheduler.inc.php` at project root; one crontab line drives all
  jobs. 48 tests (`CronExpressionTest`, `SchedulerTest`).
- **Added** `GET /_razy/metrics` Prometheus endpoint (`Razy\Metrics`): answered pre-dispatch
  like Health; exposes `razy_up`/uptime/`razy_http_requests_total`/memory always, OPcache hit
  rate + load-average + peak memory behind `RAZY_HEALTH_TOKEN`. The request counter is
  APCu-shared across FPM workers (labelled `scope=`); the K8s `hpa.yaml` + pod scrape
  annotations now target it, closing the autoscaling loop with a first-class metric. 7 tests.
- **Added** i18n backbone (`Razy\Translation`): `Translator` (Traditional-Chinese-first,
  default locale `zh-Hant`, fallback chain, `group.key` dot keys, module-namespaced
  `vendor/mod::group.key` dictionaries, smart-case `:placeholder` substitution, missing key →
  key not throw), `FileLoader` (opcache-friendly PHP files, memoised) and `Pluralizer`
  (Laravel-compatible `{n}`/`[a,b]` selectors + CJK single-category handling). 15 tests.
- **Added** Redis session driver (`Razy\Session\Driver\RedisDriver`): native TTL sliding
  expiration, SCAN-based GC (never `KEYS`), `allowed_classes=false` deserialization — the
  prerequisite for multi-node/15k-TPS sessions (FileDriver's per-file lock serialises
  concurrent same-session requests). Mirrors the `RedisAdapter` inject-a-client pattern.
- **Added** gate-hook execution tests (`tests/GateHooksTest.php`, 13): the security gates
  themselves — `__onAPICall`/`__onBridgeCall` and the `RAZY_BRIDGE_SECRET` HMAC pre-gate — had
  **zero** framework-test coverage before this round (grep-verified); now pinned, including the
  source-swap forgery rejection.
- **Fixed** `SimpleSyntax` tokenizer now rejects oversized input (16 KiB cap) upfront: the
  recursive/`(*SKIP)` regex already failed safely on pathological input, but the cap avoids
  paying backtracking cost first (one parser — `VersionUtil` — consumes remote bytes). 4 new tests.
- **Known gap** `Controller::__onDispatch()` is declared but never invoked in `src/` — a
  reserved hook, not wired behavior (tracked as documentation drift).

**Registry S0 — honest one-click install** (2026-07, `architecture/OFFICIAL-REPO-INSTALL.md`)

- **Added** `Razy\PackageVerifier` (+ typed `PackageIntegrityException`):
  fail-closed sha256 gate — index entries CLAIMING a checksum are mandatory
  (claimed-but-empty aborts too); v1 indexes without checksums warn loudly
  ("CANNOT be verified") and proceed, matching reality instead of pretending.
  URL policy delegates to the existing `ArchiveSafety::isSecureUrl`.
- **Added** built-in default official registry (`DEFAULT_OFFICIAL_*` = the URL
  the scaffolder already pointed at) via `RepositoryManager::resolveRepositories()`
  — `install`/`sync`/`search`/`pkg` now work with ZERO config; a
  `repository.inc.php` with entries stays authoritative; `install --from=<url>[@branch]`
  one-shot override added.
- **Fixed**: checksum verification now happens BEFORE every
  `extractTo`/save across ALL FOUR fetch paths (install main + dependency,
  sync, pkg) — previously the phar path bypassed `ArchiveSafety` entirely and
  the published sha256 was verified nowhere; the dependency cURL also gained the
  missing `CURLOPT_PROTOCOLS`/redirect caps/timeout. Source-ordering is pinned
  by tests. 34 tests, network-free.
- ⚠️ Maintainer action items: the scaffolded registry URL 404s until the repo is
  created (clear error + `--from` documented meanwhile); the plaintext GitHub
  PAT in `packages/publish.inc.php` (never committed — caught by the catch-all
  ignore rule) should still be rotated and moved to an env var.

**Route coexistence — Phase 0+1** (2026-07, `architecture/ROUTE-COEXISTENCE.md`)

- **Added** declared sibling exclusions: host-level `exclude_paths` in
  `sites.inc.php` makes the generated `.htaccess` / Caddyfile NEVER claim the
  listed prefixes (the cure for the reported path-theft, FM-1). Apache emits
  `REQUEST_URI`-anchored `[L]` passthroughs before domain/mount blocks; Caddy
  de-claims `php_server` via path matcher — and deliberately never emits
  `reverse_proxy` (Q3: Razy's machine-owned file stays out of the app-graph).
  New `Routing\ExcludePaths` validates at generation time (fail with a clear
  `rewrite` error, never take down serving). Empty/absent config keeps output
  byte-identical (regression-pinned). Q1 decision: host-level ownership —
  exclusions describe the shared host, not one distributor.
- **Added** `manual/08-coexistence.md` + `deploy/coexistence/` worked samples
  (edge-proxy split incl. the PHP-side impossibility boundary). 29 tests.

**Declarative data contracts** (2026-07 Wave 2, `architecture/ORM-CONTRACT-PACKS.md` C1/C2)

- **Added** Visibility Packs — the decision's second half ("pack 就是指定 name/id
  visable 的屬性"): `Model::$packs` declares named serialisation views; a dotted
  entry (`profile.city`) prunes a cast JSON column to the declared sub-path
  (addresses on one root merge). `ModelQuery::pack('name')` validates
  IMMEDIATELY (typo throws before any SQL) and propagates through
  `get/first/find/paginate`; a pack overrides `$visible`/`$hidden` while active
  and shapes ONLY the queried model (relations keep their own shape). Honest
  boundary pinned by tests: OUTPUT gate — `getRawAttribute()` still sees
  in-memory data (never was an injection control; Executor inlines values).
  No pack = `$hidden`/`$visible` behaviour byte-for-byte unchanged. 13 tests.

- **Added** `Razy\ORM\Contract` + `ContractCompiler`: the declarative skeleton the
  user asked for — table/fields/relations declared ONCE, reusing the existing
  Column grammar (no new DSL), compiled to real CREATE TABLE SQL incl. FK
  constraints from field-level `reference(table,col)`; JSON sub-schemas are
  addressable annotations (`profile.city`) without phantom columns; relation
  kinds include the new `hasManyThrough`. Ships create-generate (generated
  migration SOURCE, require-verified) + snapshot/drift REPORTING (added/removed/
  changed columns). Honest scope: NOT diff-migrations against a live DB (driver
  introspection verifiably absent, §3.2); the grammar's positional-length
  normalization is documented by the tests themselves. Models without a
  Contract behave exactly as before (BC).

**ORM reliability & dead-API cleanup** (2026-07, from `architecture/ORM-CONTRACT-PACKS.md`)

- **Fixed** `with()` was silently ignored by `first()`/`find()` — eager loading
  ran only on `get()`, so the most-used terminals quietly fell back to lazy
  per-model loads (N+1 by surprise). Both now honour declared relations
  (pinned by 3 new tests in `EagerLoadingTest`; `cursor()` keeps batch-free
  semantics by design, documented).
- **Deprecated** `Database::getMaxStatement()` — zero callers AND never
  executable (it overwrote its own validated table name with a GUID; the
  advertised `latest` alias could never bind). Removal next major (RZ-012);
  the Statement `Max` plugin or explicit joins are the sanctioned paths.
- **Fixed** doc drift: `Controller`'s migration docblock no longer shows the
  Laravel-style fluent API that never existed (real `addColumn` grammar,
  verified against `tests/MigrationTest.php`); manual/04 drift warning updated.

**Module ecosystem begins** (2026-07 Wave 1, `architecture/PORTING-VALUE.md`)

- **Added** first-party module home `modules/` — new top-level convention (approved
  2026-07); `modules/` now joins the fixer finder, the discipline-lint CI target,
  and CI gains a `redis-integration` job (real redis service; skipped Redis-backed
  suites FAIL there, so "green" can no longer mean "silently skipped").
- **Added** `razymod/queue-admin` (v1.0.0, unreleased) — headless queue dashboard
  over `QueueStoreInterface` (works over DatabaseStore and RedisQueueStore with
  zero store-specific code): status matrix per queue, job lookup, guarded
  release/bury/delete actions, purge. Published commands (`status/job/act/purge`)
  gated by an implemented `__onAPICall` allow-list; HTML shell deferred.
  Store resolution mirrors the `queue` CLI (getSharedInstance); Redis-backed
  resolution stays unavailable until a framework connection layer exists (stated,
  not faked). 11 unit tests pin every command's semantics through an in-memory
  store fake (RZ-014 met from day one).

**Async execution & queue breadth** (2026-07 thread round)

- **Added** `Razy\WorkerPool` — persistent worker-process pool complementing
  ThreadManager's spawn-per-job model: boot N workers once, feed file-based jobs
  over a line-JSON stdin/stdout protocol (warm opcache, amortised boot). No
  posix/pcntl/signals — proc_open pipes + cooperative shutdown frame, identical
  logic on Windows and Linux. Job deadlines are enforced **in-worker** via
  `set_time_limit` (parent-side non-blocking pipe reads are empirically broken on
  Windows: `fread` blocks regardless of `stream_set_blocking(false)`,
  `stream_select` false-positives on anonymous pipes — documented in the class).
  At-most-once delivery: dead workers fail their in-flight jobs and are replaced
  on next dispatch. Direct instantiation (like the Redis drivers); creator owns
  `shutdown()`, process-exit hook as backstop. 13 end-to-end tests with real
  processes (persistence via pid equality, real parallelism via in-worker start
  timestamps, crash recovery, deadline reaping).
- **Added** `Queue\RedisQueueStore` — second `QueueStoreInterface` backend
  (ZSET-per-status + hash-per-job, Lua-atomic reserve, priority in the sub-second
  score fraction, GLOBAL id counter since the bare-id API demands cross-queue
  uniqueness). Same semantics as DatabaseStore: attempts++ at reserve, complete
  deletes, stale RESERVED not auto-recovered (parity by design). Follows the
  framework-wide inject-a-connected-`\Redis` pattern (Cache/Session/Scheduler
  family; no connection-config layer exists, so the `queue` CLI stays on
  DatabaseStore until one does). 12 tests — executed in CI's redis job, skipped
  honestly elsewhere.
- **Deprecated** `ThreadManager::spawnPHPCode()` (child-side `eval(base64_decode())`,
  the standing RZ-011 sink). Migration: `spawnPHPFile()` for one-shots,
  `WorkerPool::submitCode()` for repeats; all "use spawnPHPCode" doc pointers
  reversed to the safe paths. Functional during the deprecation window.

**Template plugin ecosystem** (2026-07, market-parity round)

- **Fixed (core, significant)** `TModifier::modify()` could not pass parameters at all:
  it iterated the PATTERN-ORDER `preg_match_all` matrix as if it were per-match rows, so
  **every** parameterised modifier (`{$tags->join:', '}`, `{$x->number:2}` …) silently
  received empty arguments and ran on defaults. Now parses rows correctly (word/number/
  quoted args, quote-stripping, order preserved); contract pinned by
  `tests/TModifierParamParsingTest.php` (10 tests incl. real-`join` end-to-end).
- **Added** five built-in modifiers: `truncate` (multibyte-safe, suffix counts toward the
  limit, optional word-boundary cut — the classic `truncate:50` the docs once taught),
  `date` (epoch s/ms, `DateTimeInterface`, formats, explicit timezone), `number`
  (thousands/decimals, EU separators), `json` (script/attribute-safe via `JSON_HEX_*`,
  CJK-readable, failure renders empty), `strip_tags` (allow-list accepts plain `b i` or
  native `<b> <i>` forms). 26 tests (`TemplatePluginModifiersTest`).
- **Added** `function.paginate` — first tested first-class function plugin: windowed
  pagination nav from plain numbers (`page`/`pages`/`base`/`query`/`window`), all output
  escaped at emit, active page `aria-current`. Exercises the real `{@name key=$var}` wire
  format in its 7 tests (`TemplateFunctionPaginateTest`).
- **Added** custom-validation-rule coverage: user rules (`extends ValidationRule`,
  attached via `Validator::field()->rule()`) were an open surface with zero execution
  tests; now pinned end-to-end — transform-on-pass, cross-field data, `:field` message
  interpolation, `withMessage()`, bail modes, `when()` (7 tests, `ValidationCustomRuleTest`).
- **Deliberately NOT shipped** (honest scope): `csrf_field`/`asset` template functions
  (no view-reachable token/URL surface in the framework — the sanctioned pattern is the
  controller passing the token: `<input type="hidden" name="_token" value="{$token->escape}">`)
  and a `raw` modifier (the engine does not auto-escape, so `raw` would be a semantic no-op).
- **Note** `src/plugins/` docblocks were whitespace-normalised by the project fixer
  (plugins are excluded from `composer cs-check`; whitespace-only changes, tests green).

**Template output safety & CLI honesty** (2026-07 docs/discipline overhaul)

- **Added** built-in `escape` template modifier (`src/plugins/Template/modifier.escape.php`):
  `{$value->escape}` (ENT_QUOTES, UTF-8, `ENT_SUBSTITUTE`, non-scalar → `''`; chainable).
  Closes the RZ-004 framework gap; contract pinned by `tests/TemplateModifierEscapeTest.php` (9 tests).
- **Added** CLI dispatch guard (`src/main.php`): terminal helper libraries (e.g.
  `publish.inc.php`, the pkg-publish helper) no longer crash the runner when invoked as
  commands; `php Razy.phar publish` now prints an actionable "use `pkg publish`" hint.
- **Fixed** `help` command list: removed 5 commands with no implementation
  (`fix`, `man`, `update`, `query`, `commit`); added 7 real ones (`serve`, `routes`,
  `validate`, `search`, `sync`, `queue`, `bridge`).
- **Fixed** `{$var|modifier}` usage examples in all shipped modifier plugin docblocks →
  `->modifier` syntax (the engine's `|` is a fallback chain, `Entity.php:388-396`);
  same for the `TModifier` header example (`truncate:50` never existed).
- **Fixed** `VERSION` file `1.0.2-beta` → `1.0.3-beta` (was drifting from
  `composer.json`/`RAZY_VERSION`; the file feeds `SkillsGenerator`).
- **Changed** shipped `demo_modules/` fully migrated to Golden-Rule compliance
  (4 errors / 109 warnings → 0 / 0, 247 files, 49 changed): `spawnPHPCode` teaching →
  `spawnPHPFile`, raw-SQL lessons rewritten with the statement builder (`~=`, `|=`,
  `alias()`, `group()`), 92 template outputs gained `->escape`, 18 silent legacy
  `|pipe` no-ops fixed to real `->modifier` syntax, superglobal reads annotated with
  justified `// lint-allow`. CI discipline lint now **blocking** on `demo_modules/`.
- **Changed** discipline lint tool: `->escape`-era rules extended to `.tpl` PHP
  snippets (scope `any`), legacy no-op `|pipe` detection added, suppression counters
  separated (`line_suppressions` vs `files_disabled`), violation dedupe; self-test 25/25.

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
