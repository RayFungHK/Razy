# Changelog

All notable changes to the Razy framework are documented here.  
Each version has its own detailed changelog file in the [`changelog/`](changelog/) directory.

The format is based on [Keep a Changelog](https://keepachangelog.com/) and this project adheres to [Semantic Versioning](https://semver.org/).

---

## [Unreleased]

- **Added** FORMREQUEST-RAIL M0+M1 — the Validation family (shipped v0.5, never had a
  consumer or a manual chapter) gets its door: `Controller::validated(RequestClass)`
  resolves source by Content-Type, PASS returns the validated payload in one call, FAIL
  answers 403 `{error:"forbidden"}` or 422 `{error:"validation-failed",errors,fix}` as
  two named verdicts (Q4) and ends dispatch on the established HttpException control-flow
  — rejected input physically cannot reach a handler. `messages()` — the hook the docblock
  taught and NO code consumed for two years — is wired (Validator-level `field.ruleName`
  map, lcfirst'd short class names); the `$_FILES` docblock lie retires with file
  validation explicitly out of scope (Q3). **M1's door test caught a whole-family bug
  first**: `XHR::responseAsBody()` only STORED its body — emission needed a `sendEnvelope()`
  tail that zero call sites ever called, so queue-admin's and oauth's JSON error surfaces
  have shipped EMPTY bodies since birth; an API used wrong by 100% of callers IS the bug —
  responseAsBody now emits directly (sendEnvelope remains an idempotent array-mode pull),
  reviving eight first-party call sites with zero module edits (both modules patched:
  queue-admin 1.2.1 — also realigning its module.php/package.php version desync from L4 —
  and oauth 0.1.1). The docblock reference to the non-existent ContextHandler class died
  too. Suite 5,543 (+9 across M0/M1), module discipline lint green on modules/.

- **Added** PHP-85-READY — the framework is deprecation-clean on PHP 8.5 and the CI
  matrix runs it: php.net's migration85.deprecated list swept against src (curl_close ×4
  removed — handles free out of scope since 8.0; `$http_response_header` behind a
  `function_exists` branch since the function replacement is 8.4-only; SplObjectStorage
  dedup moved to ArrayAccess; `PDO::MYSQL_ATTR_FOUND_ROWS` resolved via `class_exists`
  ternary — `\Pdo\Mysql` turned out to be a **8.4** class, verified live absent on a
  driver-loaded 8.3.1, so the ^8.2 floor keeps the legacy constant exactly where the
  clean one cannot exist). The 8.5-CI-open question (not touched blind):
  `Module::await()`'s `$caller(...)->bindTo(...)` vs 8.5's rebinding deprecation — every
  call site passes plain closures; the matrix will tell the truth. PHPUnit upgraded
  10.5 → **11.5** (10 cannot pass on 8.5): config migrated to the 11.5 schema with all
  strictness flags intact, the suite's three `MockBuilder::addMethods()` mocks became
  real-subclass stubs (API dies in PHPUnit 12), and the repo's single remaining
  doc-comment `@dataProvider` moved to `#[DataProvider]` — PHPUnit reports **zero**
  deprecations at 5,534 tests. Support policy (maintainer sign-off): floor stays `^8.2`
  until its upstream EOL **2026-12-31**; the first release after raises to `^8.3`.
  readme verified-stats line refreshed to the real current numbers (was a 2026-07 drift).

- **Changed** CSRF-RAIL L4 — `razymod/queue-admin` (v1.2.0, RZ-012 minor) now runs on the
  door instead of its own: `support/csrf.php` deleted (the confession docblock is false by
  construction), `/ui` issues via `csrfToken()`, `/act`+`/purge` lost their double-submit
  verify blocks, and the shell's fetch needed **zero changes** — its `X-CSRF-Token` header
  already matched the engine's `TOKEN_HEADER` case-insensitively. Intentional semantic
  changes: mismatch is the door's 419 (was handler-level 403), and an UNARMED dist makes
  the admin surface THROW rather than run mutations unprotected (fail-closed; the old
  hand-roll defended itself anywhere, the door defends better where armed and says so
  loudly where not). Verified live on the armed playground: ui 200 + session-backed
  64-hex meta token, tokenless `act` 419, header-carrying `act` 200 into the store-null
  handler. The dogfood also exposed a defect predating CSRF entirely: the module shipped
  `queueadmin.*` controller files while the dist layout expects the class-name form
  `queue-admin.php` — it could not load in ANY dist; six files renamed via `git mv` and
  loading verified. Double-submit demotes to the documented stateless recipe. manual/07
  §4 tutorial now self-contained (playground is git-ignored house convention); ERP
  arming sequence for the 270-route target appended to CSRF-RAIL.md as an appendix.

- **Added** CSRF-RAIL L3 — live dogfood, and it immediately earned its keep: the armed
  playground (`demo/csrfdemo`: form/save/hook trio, appdemo `'csrf' => 'on'`) proved the
  full loop end to end — cookie mint+adopt (2nd visit keeps id AND token), tokenless POST
  419 HTML/JSON pair, valid POST 200, **replay of a consumed token 419 (rotation at work)**,
  declared-exemption 200 answering with its reason, `validate` ✓ armed / ⚠ UNARMED (with
  honest behavior flip demonstrated), and the unarmed `csrfToken()` crash carrying its own
  fix. Full outputs recorded as a tutorial in manual/07 §4. Two findings: **(1) the first
  real one** — the wrapper fed the engine `routedInfo['method']` (the ROUTE *constraint*,
  `'*'` for unconstrained routes) where the safe-method check needed the REQUEST method:
  every GET on an armed dist would 419 its own form page; fixed in the wrapper (short-
  circuits real GET/HEAD/OPTIONS from `$_SERVER`, worker-refreshed) + regression test —
  unit tests had passed the whole L1/L2 suite while the demo page itself 419ed. **(2)**
  the loop's early confusion was harness, not code: curl's `-c` silently no-ops against a
  sandbox-unwritable jar path and every later `-b` shipped no cookie — a reminder that
  "the framework is lying" investigations must start at the wire. (L4 follow-up honesty:
  the playground tree is git-ignored house convention — the teaching files and the
  appdemo `'csrf' => 'on'` are local like every manual/12 walkthrough, and manual/07 §4
  now carries the full recreate.)

- **Added** CSRF-RAIL L2 — exemptions are declarations, not string lists. `(new Route('hook'))
  ->csrfExempt('webhook: HMAC-verified upstream X')` rides the same Route entity as the L3
  ready gates; **a reasonless exemption is unrepresentable** — the method throws at
  registration (stronger than the promised `validate` ✗: the static scan has nothing left to
  find, so the as-built rail is enforcement-by-construction). At match time the dispatcher
  copies the declaration into routed context (`csrf_exempt`), and the armed door's wrapper
  middleware honors ONLY that context flag — it cannot create one, and the engine stays
  object-free. The ctor's `excludedRoutes` string list survives untouched for BC but the
  manual now teaches only the declaration form (manual/07 §4 gained the armed-door section
  with the XHR meta recipe).

- **Added** CSRF-RAIL L1 — the door: one config key arms the engine. `'csrf' => 'on'` in
  `dist.php` boots the full chain at Distributor init (`CsrfDoor::arm`): file-backed Session
  (temp dir, per-dist filename prefix, zero disk touch at arm), `CsrfTokenManager` over it,
  global middleware onion `SessionMiddleware → CsrfMiddleware` (session outermost so the token
  validates between start/save), and the manager published on the container for
  `Controller::csrfToken()/csrfField()` — an unarmed dist calling the helper dies with a
  LogicException carrying the fix, never an empty hidden field that 419s every submit.
  `rotateOnSuccess` on at the door (Q5); rejection answers via `CsrfRejection`: `csrf.failed`
  event with module/route/method context (announce-only, resolver-miss tolerated), then the
  L3-gate-shaped pair — 419 JSON envelope for XHR, small honest HTML page otherwise, the
  submitted token never echoed. Missing/`'off'` = today's behavior exactly, but
  `validate` now prints a **UNARMED** warning line with the arm instruction (upgrade-neutral
  *and* loud — Q1); any other value is a config lie refused at boot and ✗ in validate.
  Shared-module scaffold next-steps now hint arming new dists from day one.

- **Added** CSRF-RAIL L0 — the session finally owns its cookie. `SessionConfig` had carried
  every cookie field (name/lifetime/path/domain/secure/httpOnly/sameSite) since v0.5 and
  NOTHING consumed them: `start()` never read `$_COOKIE`, so an existing session could not be
  recognised even in principle, and no response ever issued the cookie — razymod/queue-admin
  had cited exactly this ("the framework Session subsystem emits NO cookie anywhere") as the
  reason it bypassed `CsrfTokenManager` with a hand-rolled transport. Now a valid carried id
  (40-hex, the `generateId()` shape — malformed shapes are discarded, never queried) is
  adopted, fresh mints and `regenerate()` rotations emit, and `destroy()` expires it; caller
  `setId()` still wins over the cookie (BC). Options are built by a pure, testable
  `cookieOptions()` seam; the real emit paths stay inert under CLI/headers-sent. Makes the
  session-synchronizer CSRF door (L1) possible on a real per-client identity.

- **Fixed** the wizard door's web halves, both found only by live browser-level dogfood
  (unit tests structurally could not see either): the gate's not-ready **302** now sends
  `http_response_code(302)` + `Location` before throwing — `main.php`'s HttpException catch
  means "already sent", so the bare throw had produced a silent 200-empty; and the token
  form no longer hand-builds `action="/__setup/…"` (loses the dist prefix on subpath mounts
  → POST into a 404) but posts to its own URL, correct under every mount. Pinned by a
  source-order test and two runner rails.
- **Fixed** `compose` extraction and `RAZY_ALLOW_INSECURE_TRANSPORT` — dead since the
  Phase 2.5 refactor moved `env`/`xcopy` inside the `Razy` namespace while callers kept
  the never-resolving global `\env()`/`\xcopy()` form: a fresh package extract died
  "undefined function xcopy()", and the insecure-transport switch silently never fired.
  Call sites repaired to fully-qualified `\Razy\…` (the cs fixer re-globalizes bare words
  — pins hold the shape), re-verified by a forced live re-extract in playground.
- **Added** MODULE-LIFECYCLE L5 — the chapter and the declarations that make the doctrine usable.
  `manual/12-module-lifecycle.md` (derived readiness, `provision` values, route gates and their
  503/302 answers, the operator verbs, wizard rails diagrammed, honest `module.installed`, the
  old-app substitution table — every claim cited to a verified `file:line`). The razymod modules
  now declare their provisioning honestly: `permissions` → `wizard` (the login-foundation
  chicken-and-egg is the shape the door exists for), `oauth` and `queue-admin` → `none` (declared
  absence beats guessing). `validate` gained the dossier's Q2 rail backfilled from L1: declaring
  `wizard` without a `migration/` directory is an error — a door with nothing behind it is a
  declared lie (the CLI mint already refuses the same shape at the other door). The dossier
  carries its as-built record (§4.1, honest deltas included) and the ERP migration appendix (§7:
  phar-upgrade-first, shape-by-shape substitutions with the evidence anchors, operator sequence).
  Suite unchanged; the new validate rule pinned.
- **Added** MODULE-LIFECYCLE L4 — the wizard door, on the narrowest rails Q2/Q6 approved.
  `php Razy.phar module wizard-token <dist> <code>` mints a signed, single-use, 10-minute token
  (`WizardTokenSigner`, StateSigner lineage: HMAC-verified constant-time before parse, dist+module
  bound, nonce spent in the mandatory cache — `NullAdapter` is refused at mint, because a token that
  can never be spent is a lie printed in green). Minting is refused — loudly, audited — for modules
  that don't declare `'provision' => 'wizard'`, for unreachable ledgers, and when nothing is pending.
  The web runner (`Razy\Setup\WizardRunner`) owns `/__setup/<code>` — the exact path L3's 302 targets —
  intercepted BEFORE session, lifecycle, and route table in BOTH dispatch channels, so no module alias
  can ever impersonate it. POST verifies → spends the nonce BEFORE any migration work (a retry needs a
  fresh mint: a leaked page cannot re-run the door) → migrates through the SAME doors as CLI
  (`ModuleDatabaseConnector` + `MigrationManager`, prefix `wizard_runner`) → fires `module.installed`
  (`via: wizard`). The CLI door now fires it too — and only on a non-empty apply: an up-to-date pass
  fires nothing (Q3: the event means migrations RAN). Every mint, spend, and refusal writes one
  `[Razy][wizard]` audit line; the framework still owns no user row. The GET page is zero-DB by design
  — the ledger's shape is revealed only after proof of the shell. Suite 5,486 → 5,501
  (`tests/ModuleLifecycleL4Test.php`: signer behavior incl. tamper/cross-binding/replay/expiry/
  NullAdapter rails, runner CLI-refuse, door-order source pins); non-wizard and unknown-code mints
  dogfood-refused against the rebuilt phar.
- **Added** MODULE-LIFECYCLE L3 — the readiness gate at the dispatcher, the answer the ERP paid 15
  handler-whitelist copies for. `Route::ready('vendor/mod')` (or `'self'`) gates a route;
  `$agent->readyRoutes('self')` gates every route a module registers afterwards (explicit per-route gates
  win; CLI script routes never gate). A not-ready gate never answers 404-silence and never lets a handler
  write into tables that do not exist: `deploy`-provision modules get a framework **503** naming the
  module and the exact `php Razy.phar migrate <dist>` fix (JSON for XHR, `Retry-After: 60`), declared
  `wizard`-provision modules flip the same verdict to a **302** into `/__setup/<code>` (the token-gated
  L4 runner answers there; until then the redirect target simply 404s). The gate runs through ONE door —
  `RouteDispatcher::evaluateReadinessGate()` before the executor is even resolved — answering via a probe
  seam the Distributor wires to the L1 `moduleReady()` predicate, so there is one readiness policy;
  a gate without its probe warns loud and refuses closed. Honest deviations: the dossier's illustrative
  `['main', 'ready' => …]` leaf syntax collides with lazy-registry directory semantics (an array value is
  a folder level), so the `Route` entity carries the gate — and `Module::addLazyRoute()` widened to
  accept it, deliberately reversing the pinned TypeError that previously rejected Route entities there;
  gating is EXPLICIT opt-in only — existing modules with pending ledgers keep serving, auto-gating them
  would have been an unannounced 503 (BC); the handler-side helper promised in the L1 commit lands as
  `Controller::moduleReady()` (via a `Module::moduleReady()` proxy, `hasModule` lineage) documented as
  internal-logic branching only — request admission belongs to the route door, not handler bodies. Suite 5,470 → 5,485 (`tests/ModuleLifecycleL3Test.php`; the dispatch call-site order is
  source-pinned since `matchRoute()` itself is untestable end-to-end under the CLI_MODE test bootstrap).
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
