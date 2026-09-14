# MIGRATION-GOVERNANCE — the migration subsystem has no owner, no trigger, no integrity check

Date: 2026-09. Status: **DECIDED 2026-09 — all four maintainer calls per recommendation; ALL SHIPPED (M0+M1, M2, M3+M4)** (§4).
Trigger: maintainer challenge during razymod/permissions S3: "模組升級/降級沒有好的檢查；
愈多 migration 愈累積更多 SQL 檢查；沒有統一化管理；migration 由 developer 負責會出很多問題。"
Every claim below is code-verified (file:line), per house doctrine "Code beats docs".

## 1. Evidence (current facts)

| # | Fact | Evidence |
|---|------|----------|
| E1 | **Nothing in the framework ever runs migrations.** `getMigrationManager()` has ZERO call sites in `src/` (only its own definition + docblock); all 28 terminal commands contain no `migrate` | grep `MigrationManager` over `src/`: hits only `Controller.php:605-691` (the helper), `Database/MigrationManager.php` itself, and one `ContractCompiler.php:116` comment; `ls src/system/terminal/*.inc.php` (28 files, none migrate) |
| E2 | **No upgrade/downgrade hook exists.** `__onUpgrade` appears nowhere in `src/`; `pkg install/update` paths never consult a module's `migration/` dir | grep `__onUpgrade\|onUpgrade` over `src/`: no hits |
| E3 | **Applied migrations are name-only.** Tracking table columns = `migration, batch, executed_at`; NO checksum → editing an already-applied file is permanently undetectable (classic "developer owns migrations" incident: silently rewriting history) | `MigrationManager.php:122-139` (DDL), `getApplied()` :192-206 selects names only, `migrate()` records name+batch :249-253 |
| E4 | **The tracking table is process-global, not per-module.** `TRACKING_TABLE` is a `const` — every manager instance in every module against the same Database shares ONE table with no module discriminator; `rollback()` selects `DISTINCT batch` across ALL modules, and unresolvable files are "gracefully skipped" → **module A's rollback can delete module B's tracking rows while B's tables remain** | `:52` (const), `:284` (`SELECT DISTINCT batch FROM {$quoted}`), missing-file skip behaviour pinned by `tests/MigrationTest.php` |
| E5 | **Per-call cost, quantified honestly.** One `migrate()` call = 1× `CREATE TABLE IF NOT EXISTS` (per manager INSTANCE memo :113 — a new instance per request pays it every request) + 1× `SELECT migration` (**constant query count, O(n) transferred rows**, n = lifetime migrations) + `scandir` + `require` of every migration file; pending applies add 1 batch-query + 1 INSERT each. So "愈多 migration 愈多 SQL" is half-true: queries stay constant, but rows/scandir/requires grow linearly, and a module calling `migrate()` from `__onReady` pays it PER REQUEST (boot-time DDL — the exact cost pattern RZ-009 warns about) | `:111-144`, `:192-219`, `:232-257`, `discover()` :155-185 |
| E6 | Consequence nobody chose: the sanctioned integration point today is module code calling `getMigrationManager()` itself — i.e. **every deployment decides ad hoc** (boot = cost+RZ-009 smell, or manual = fresh installs half-migrated). `razymod/permissions` S2/S3 deferred the problem honestly (tests drive MigrationManager directly; nothing in the module runs migrations at runtime) | `Controller.php:605-691` is doc-only guidance; S2/S3 module code contains no migrate() call |

## 2. What the maintainer's four complaints map to

1. "升級降級沒有好的檢查" → E1+E2+E3: no trigger, no hook, no checksum, no status surface.
2. "愈多 migration 愈累積 SQL" → E5: constant queries but O(n) rows + O(n) file requires per call; **no fast path**.
3. "沒有統一化管理" → E4: one shared tracking table without module identity; no site-wide `--status`; per-module DB targets are invisible to any central tool.
4. "developer 負責會出問題" → E6: today that is literally true — the framework ships the engine and points at the developer's bootstrap for ignition.

## 3. Proposed milestones (M0 first; each independently shippable, all additive RZ-012)

- **M0 — module scope column (integrity floor, small).** `MigrationManager` gains a `scope` (module_code) recorded at apply; tracking rows filtered by scope in `getApplied/getPending/rollback`. Old rows (no column) read as legacy scope `''` = current behaviour, `ALTER TABLE ... ADD COLUMN` self-heal on ensure. Kills E4 cross-module rollback corruption. ~S-sized incl. tests.
- **M1 — checksum.** `sha256(file contents)` recorded at apply; `migrate()` verifies every applied row's file hash; mismatch ⇒ fail-loud (one bypass flag). Rewriting applied history becomes impossible-by-accident. Cheap: 1 hash per file on the fast path (see M4).
- **M2 — `php Razy.phar migrate <dist> [--status] [--to=…] [--rollback=n]`.** Deploy-time CLI (the "統一管理" surface): resolves each module's declared DB (same config-connect contract `razymod/permissions` pioneered, §4.2 option 1), runs its scoped manager, prints applied/pending/batch/date table. Web requests never migrate (stated policy, not default drift).
- **M3 — declaration, not developer wiring.** `package.php` key `migration => 'deploy' | 'manual'` (default `manual` = today): `deploy` modules get their pending migrations run by the M2 CLI only. The __onReady-boot-DDL option disappears from guidance.
- **M4 — fast path (fixes E5 at scale).** Store a manifest hash (sorted discovered filenames+hashes) in the tracking meta row; `migrate()` = 1 indexed SELECT + 1 local hash compare → return-early without requiring any migration file. O(1) regardless of history length; checksum verification (M1) rides the same manifest.

Downgrade stance (deliberate limitation to state): `--to`/`--rollback` stay explicit-operator tools; a package downgrade installs older code and the CLI **warns** when applied migrations are newer than the installed manifest — automatic down() on downgrade is data-loss roulette and should stay refused.

## 4. Questions for the maintainer — ✅ ALL DECIDED 2026-09 (all per recommendation)

- **Q-M1 = YES**: M0+M1 as ONE framework patch (scope column + checksum, self-healing ALTER).
- **Q-M2 = BAN**: web-request auto-migration prohibited; CLI/deploy-time only.
- **Q-M3 = FAIL-LOUD** on checksum mismatch, `--force` is the only escape.
- **Q-M4 = ORDER**: M0+M1 first, M2 CLI next, M3+M4 as one.

Execution queue: **M0+M1 (integrity floor)** → M2 → M3+M4. razymod/permissions S4/S5
interleave freely (module line and framework-migration line are independent).

> **✅ M0+M1 SHIPPED 2026-09** (same wave as the decision). Implementation
> decisions beyond the letter of the proposal, stated: strict scope — pre-M0
> rows keep `scope ''` and are visible ONLY to scope-`''` managers (no silent
> adoption; the house had zero live consumers, E1, so adoption ambiguity is
> theoretical); self-healing `ADD COLUMN` per driver on existing tables;
> `checksum ''` rows are unverifiable-by-design (skipped, never guessed);
> verification covers EDITED and MISSING applied files, `migrate(force: true)`
> is the operator-only escape; `verifyChecksums(): array<name,error>` is the
> programmatic surface M2 `--status` will consume; `Controller::
> getMigrationManager()` now auto-scopes by module code. One pre-existing test
> (`MigrationTest::testMigrateThrowsForMissingFile`) pinned the lenient
> missing-file silence M1 exists to kill — strengthened, not weakened, with a
> docblock saying so. New pins: `tests/MigrationGovernanceTest.php` (10),
> incl. the exact E4 batch-interleave scenario.

> **✅ M2 SHIPPED 2026-09**: `php Razy.phar migrate <dist> [module_code]
> [--status|--rollback=n] [--force] [--domain=…]` — the unified surface.
> Boots the distributor init-phase only (RZ-009), enumerates modules
> carrying a `migration/` dir, resolves each one's DB via the config-connect
> contract (§4.2 option 1 — the ambient `getSharedInstance`/`getInstance`
> shapes are pinned-OUT by test), and drives the scoped manager one scope per
> module. `--status` is read-only and doubles as the deploy gate (drift
> present ⇒ exit non-zero); `--rollback` requires an explicit module code —
> mass rollback is not a deploy verb; `--force` passes through to the M1
> operator escape and is documented as never-for-automation. Web-request
> migration policy is printed in the command's own usage (Q-M2). Tests:
> source-pin suite in the house inc.php style (`MigrateCommandTest`, 9) plus
> real-phar smoke (usage/exit codes verified against the built phar).

> **✅ M3+M4 SHIPPED 2026-09** (one patch, closing the queue):
> **M3** — `package.php` gains `'migration' => 'deploy' | 'manual'`
> (default `manual` = the historic, developer-invoked behaviour; parser at
> ModuleInfo, getters `getMigrationMode()`/`getMigrationDeclared()`). The
> unnamed bulk `migrate <dist>` pass now runs ONLY `deploy`-declared modules;
> naming a module explicitly IS the manual sign-off; `--status` ignores the
> gate and shows every module tagged `[deploy]/[manual]`. A suspect
> declaration (typo) degrades to `manual` — the safe side — WITH a loud CLI
> warning, never silently. `razymod/permissions` declares `deploy` (the tree
> demonstrates its own contract).
> **M4** — fast-path manifest (`MigrationManager::MANIFEST_TABLE`, one row
> per scope): the manifest is the combined sha256 of every discovered FILE
> (name + content hash), stored only after a pass that left zero pending and
> invalidated by every `rollback()`/`reset()`. A matching manifest proves the
> exact state a completed pass left behind — no applied-rows SELECT, no
> per-file re-hashing. `--force` (and `migrate(force: true)`) never reads nor
> WRITES the manifest, so an operator escaping drift can never normalize that
> drift into a fast path. E5's "每多一次 migration 就累積更多檢查 query" is
> answered twice over: the accumulation itself is gone (content verification
> is manifest-checked, O(1) rows), and the true no-op path no longer scans
> applied history at all — pinned by a strict query-count comparison test.
> Content edits invalidate the manifest by construction (hash covers bytes);
> rollback-then-migrate re-applies (pinned). 7 governance + 3 command tests
> added; full suite 5,346.

Original questions retained verbatim below (recommendations inline).

- **Q-M1**: M0+M1 together as one framework patch (tracking-table ADD COLUMN + hash column, self-healing)? **Rec: yes** — E4 is a live footgun the moment a second module adopts migrations; both share the same table touch-point.
- **Q-M2**: Web-request auto-migrate — banned outright in guidance (CLI-only, deploy-time)? **Rec: ban** — boot-time DDL is a RZ-009-shaped cost with concurrency races (two requests running the same ALTER) on top.
- **Q-M3**: checksum mismatch default = fail-loud with `--force` escape? **Rec: yes** (silent auto-trust of edited history is the incident we are preventing).
- **Q-M4**: order — M0+M1 now, M2 next, M3+M4 as one? **Rec: yes.**

## 5. Non-goals

No auto-generated migrations, no ORM-sync schema diffing (Contract stays a declared mirror, §4.1 stance unchanged), no multi-tenant dist-level parallelism tricks, no change to `queue`'s own `DatabaseStore::ensureStorage` (it self-provisions by design and is not a MigrationManager consumer).
