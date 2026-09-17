# COMPILE-ON-DEPLOY — the deploy-time boot snapshot

Status: **shipped (M1 + M2), default-off per dist** · Added 2026-09 · Owner decision:
「效能是 RAZY 其中一個重要的優勢」— compile-on-deploy 全面化.

## Why

The FPM cold-boot attribution (benchmark/EPOCH-2026-09.md, closed 2026-09-17, buckets
confirmed under xdebug) found the per-request CPU of a plain php-fpm boot dominated by
work that is **identical every request**: phar autoload FS probing (~22%), filesystem
existence ladders (~13%), route-table reassembly (~12%), reflection DI (~5%). Worker
mode already amortizes this across requests; the price that remains is **thread boot**
(pod start-up, scale-from-zero, cold first request) — and plain-fpm sites pay it all,
always. Laravel solved the same shape of problem in 2012 with `route:cache`/`config:cache`:
assemble the declaration graph ONCE at deploy, ship it as data, replay it per boot.

## What ships

### M1 — the baked classmap (phar build → autoload)

`build.php` scans `src/library/**.php`, parses namespace + type declarations
(regex, tolerant; first declaration wins per FQCN) and bakes the result into the
phar as `system/classmap.php`. `autoload()` (`src/system/bootstrap.inc.php`)
consults it for phar-library lookups: **a hit is one array read where the legacy
ladder paid `is_dir` + up to three `is_file` probes over the phar stream per
class**. Probe-neutrality is the safety law: a map miss falls through, and a hit
whose include fails to define the class ALSO falls through — the map can only
ever make a hit faster, never answer wrong. Non-phar runs (tests, dev-from-src)
never see a map.

### M2 — the compiled boot (deploy CLI → replay)

A distributor boot assembles pure data: the module manifest (module.php/package.php),
the merged dist config, and — through each module's `__onInit` — the declaration
tables (routes incl. their precomputed regexes, API/bridge commands, bindings,
event listeners/observers, module middleware). `Razy\Compiler\BootCompiler`
captures that assembly once and the runtime replays it verbatim.

- **The door**: dist.php `'compiled_boot' => true`. Absent = today's boot, byte for
  byte. No dist is compiled by an upgrade it did not ask for.
- **The CLI**: `php Razy.phar compile <dist> [tag]` (+ `--status`, `--clear`).
  It boots the dist TWICE; the two dumps must be identical (registration
  determinism — conditional-by-clock/env registration gets caught at deploy,
  not in prod). It then boots a THIRD distributor forced through the compiled
  path and requires its live route table to equal the legacy one — the compile
  command REFUSES and deletes the artifact if the replay diverges.
- **The replay** (`Distributor::assembleCompiled`): Module shells are built with
  the exact scanner-manifest data (folder / resolved version / raw module.php —
  ModuleInfo validation runs live), declarations re-register through their real
  doors (`addAPICommand`/`addBridgeCommand`/`bind`/`listen`/`observe` → registry
  side-indexes stay consistent by construction), routes load with their dumped
  scalars and re-linked Module objects, `__onLoad`/`__onRequire` stages run
  UNCHANGED. `__onInit` is not re-run; its recorded output stands in.
- **The refusals** — data cannot carry everything, and nothing is ever silently
  dropped. Compilation is REFUSED, offender by offender, for: closure
  listeners/observers/middleware/handlers, `await()` runtime callbacks, shadow
  routes (cross-module target references), non-serializable `contain()` payloads
  or module.php values, and middleware that `new $class()` cannot rebuild
  (reflection-probed at compile, zero side effects).
- **The freshness**: the artifact stamps schema id, framework version, and a
  fingerprint = md5 over (mtime+size) of EVERY `.php` under the dist folder and
  its `config/<dist>` folder. A mismatch at boot = one `error_log` line and the
  full legacy boot — staleness can only cost speed, never correctness. (This is
  the same stat-only trust level worker mode already runs for its in-process
  distributor cache; `RAZY_COMPILE_TRUST=1` skips even this for pure deploy
  discipline.) The enable-list still rules after replay; a re-enabled module
  without a recompile is visible right there.
- **The artifact**: `data/compiled/<dist>@<tag>.php` (wildcard tag filed as `_`),
  a `var_export`'d array — opcache makes each replay boot one hot require.

## The contract that makes it legal (RZ-009)

Replaying `__onInit`'s output instead of re-running it is only equivalent under the
Golden Rule that already governs it: **register in `__onInit`, act in
`__onReady/__onRouted/__onEntry`** (RZ-009: no IO/cross-module calls in `__onInit`).
A declaration-pure `__onInit` produces exactly the tables that get dumped; live
state belongs to `__onLoad`/`__onRequire`, which the replayed boot still runs.
`__onInit` that breaks RZ-009 cannot be compiled correctly by ANY tool — the
compile determinism check will surface clock/env-dependent divergence; a
future lint rule can flag state-setting in `__onInit` directly.

## What is NOT compiled (deliberately)

- **DI container bindings (M3-deferred)**: the reflection bucket profiled ~5% —
  replaying container state is the riskiest slice for the smallest gain; if
  post-M2 numbers still demand it, it lands as its own decision.
- **Standalone apps**: `Standalone::initialize` assembles a single Module by hand
  (no scanner, no manifest walk); its boot is a fraction of a dist boot. Covering
  it is a follow-up with the same pieces; the fpm benchmark numbers for compiled
  standalone will be published only when that lands.
- **App-level middleware, CSRF rail config, session**: registered outside module
  assembly, so replay never touched them.

## Measurement

Live-verified on the gate bench site (benchgate: 4 modules, 4 readiness-gate
routes): compile green, replay self-proof `4 routes match`, artifacts 5.3 KiB.
Gate regression trio unchanged (gr 200 / gv 200 / gf 503 honest body).
Rate numbers: see benchmark/EPOCH-2026-09.md + `benchmark/results/` — published
only from clean, checked runs, per the benchmark honesty law.

## Files

- `src/library/Razy/Compiler/BootCompiler.php` — dump / refuse / fingerprint / write
- `src/library/Razy/Distributor.php` — `initialize()` fast path + `assembleCompiled()`
- `src/library/Razy/Module.php` — `buildController()` / `dumpDeclarations()` / `applyDeclarations()`
- `src/library/Razy/Distributor/RouteDispatcher.php` — `loadCompiled*`
- `src/system/terminal/compile.inc.php` — the deploy CLI (migrate-pattern)
- `build.php` + `src/system/bootstrap.inc.php` — M1 classmap bake + lookup
- `tests/CompileOnDeployTest.php` — dump/refuse/replay/fingerprint/wiring gates
