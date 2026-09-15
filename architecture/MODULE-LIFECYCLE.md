# MODULE-LIFECYCLE — Install / Enable / Disable as a Framework Concern

**Status: DRAFT — awaiting maintainer sign-off (Q1–Q6 below)**  
**Drafted:** 2026-09 (post v1.1.0-beta.2)  
**Trigger:** Maintainer question 2026-09: 「是否 Razy 提供 module install/enable/disable 機制，比 developer 自行處理大部分安裝機制更好？簡單的 install event 讓 developer 不用再處理 order，也可以控制什麼 module 走 web setting、什麼不走 auto migration、什麼 module trigger 到其他 module installed 就 auto migration。」  
**Companion evidence:** ERP pain survey (2026-09, full report `scratch/ERP-ROUTE-DEPENDENCY-REPORT.md`, gitignored — load-bearing evidence inlined below so this dossier stands alone).  
**Doctrine carried forward:** beta.1 M3 (`web requests never migrate`, `migration => 'deploy'`), OAuth dossier Q1 (framework never owns the user row), RZ-015, RZ-008, RZ-012, RZ-013, RZ-014.

---

## 0. Verdict up front (recommendation)

**Yes — install/enable/disable belongs in the framework, with three hard rails:**

1. **State is DERIVED, never stored.** A module is *ready* iff the migrations it declares are all applied — the migration ledger (M0–M4) already computes exactly this (`MigrationManager::getStatus()` `:586`, M4 manifest fast path `MigrationManager.php:386-396`). A stored `installed` flag is a second source of truth and *will* drift (ERP proved it: six hand-rolled flags, one hand-rolled wizard per module). One policy, one door, zero ambient state.
2. **Migration EXECUTION stays CLI-first** (M3 survives). The user's "web setting / auto-migration" wish is reconciled NOT by weakening M3 globally, but by a per-module **`provision` policy declaration**: `'deploy'` (default, today's policy) | `'wizard'` (explicit, auditable opt-in for end-user products like Razy ES) | `'none'`. The framework — not the developer — owns the wizard surface, the state machine, and the fail-loud ordering. Smuggling DDL via `ensureSchema()` becomes the lint-visible violation it always should have been.
3. **Events announce, they never carry truth.** `module.installed` fires in the process that actually ran the migrations (the `migrate` CLI), so data-seeding listeners (default permissions, search providers) hook in without inventing boot-time watermark state. "Ready?" is a **queryable predicate** (`moduleReady('vendor/mod')`), not an event — an event that asks "is it ready" per boot is a stored flag with extra steps.

The ERP evidence below shows what the absence costs: **38 wizard-step registrations with 5 colliding order numbers, 15 byte-similar route-whitelist copies, 6 stored `installed` flags, and a four-layer existence guard that is literally dead code.**

---

## 1. Evidence (all file:line verified this campaign)

### 1.1 Framework: the state machine exists at RUNTIME but is invisible to installers

| Fact | Proof |
|---|---|
| `ModuleStatus` enum: `Failed/Disabled/Unloaded/Pending/Initialing/Processing/InQueue/Loaded` | `src/library/Razy/Module/ModuleStatus.php:23-47` |
| **`Disabled` is declared but NEVER assigned anywhere in `src/`** — a dead case; there is no disable path | grep `setStatus(ModuleStatus::Disabled)` → 0 hits |
| **Zero module lifecycle events** — no `module.installed/ready/enabled` anywhere | grep `trigger('module` → 0 hits |
| **No `enable`/`disable`/`uninstall` CLI** — `install.inc.php` only installs from repos | grep over `src/system/terminal/` → only Scheduler `Job::disabled` |
| Module dependency EXISTS: `package.php 'require'` (singular) parsed | `ModuleInfo.php:271-273` |
| …and `require()` resolves recursively, guaranteeing load order | `Distributor.php:857-891` |
| **…but an absent peer means `return false` SILENTLY — zero output, module just vanishes** | `Distributor.php:868-870` |
| The file already contains the exact warning pattern L0 should copy (await-unresolved) | `Distributor.php:288-295` (`E_USER_WARNING` naming missing modules) |
| `prerequisite` is COMPOSER-only (package version conflicts), NOT module deps | `ModuleInfo.php:238-246`, `Distributor\PrerequisiteResolver.php:134` |
| Migration ledger: per-scope table, sha256 checksums (M1), O(1) manifest fast path (M4), `getStatus()` per scope | `MigrationManager.php:99` (scope ctor), `:147-241`, `:386-441`, `:586` |
| `api('vendor/mod')` returns `Emitter` — a `__call`-only object; **`method_exists()` on it is always false** (silent integration-killer) | `Emitter.php:50` |

### 1.2 Application (ERP, `Razy-Development` @ `7a244a1`, phar 1.0.3-beta): the cost of absence

| Pain | Evidence |
|---|---|
| **Stored-flag drift by design**: `$installed` from module config, six copies | `logging.php:8,12`; `core.php:20`; same pattern in user/group/department (+35 site modules) |
| **Dual-track route registration** — `if (!$installed)` registers `POST api/install`, else 30+ routes | `sites/erp/dev/task/default/controller/task.php:44-84` (read in full — the shape repeats) |
| **Route-whitelist copies**: `if ($route === 'api/install' || str_starts_with(...))` in `__onEntry` guards | **15 files** (it_worklog:148, zone_account:92, notification:81, webcms:87, calendar_event:76, holiday:81, footage:80, vcard:83, milestones:80, announcement:147, event_highlight:89, leave:166, membership_listing:74, media_center:85, structure:80) |
| **Wizard = one module (`core`) hand-rolled as the framework-was**: `registerInstall(order, callable)` — **38 real call-sites (41 grep hits, 3 are comment mentions), 5 COLLIDING order numbers** (25 webcms/kaiser_pnl, 29 holiday/personal_declaration, 43 milestones/structure, 44 footage/announcement, 47 media_center/membership_listing), `usort` to impose order that a dependency graph should guarantee | 38 calls grepped; `core.php:286-303` (registry + `usort`) |
| **Cross-module closures**: install steps are `function(){}` values handed to `api('core')` — shared-state across module borders (RZ-008 grey), and the ordering is *manual integers* | `company.php:186`, `appform.php:162`, `user.php:233`, … all 38 |
| **The "install page" gate only protects core**: every other module returns a 404-ish dead menu item when not installed | `task.php` `__onReady` menu unconditional vs routes conditional (`task.php:87-89` dashboard guard vs `:44` routes) |
| **Dead-code trap**: leave guards holiday with `method_exists($holiday,'isInstalled')` — always false against `Emitter.__call` | `leave.main.php:17-20` vs `Emitter.php:50` |
| **Web-request migration smuggled** in core `__onReady` (auto-migrate on web boot) — the M3 policy has a live violator in production | ERP `core.php:152-163` |
| `migration` key declared by **0/44** modules; `migration/` dirs exist in only 5/44; 39 create tables from `install_action` handlers | ERP census (report §A) |
| Dead key: `task`/`appform` package.php use `'requires'` (plural) — **silently ignored** by `ModuleInfo` (reads `'require'` only) — dependencies never enforced | `ModuleInfo.php:271` |
| ERP invented its own dependency event (`erp/company:dependencyResolved`) because the framework has none | `CompanyFinalizeWorker.php:71` |
| `core.__onAPICall` = `return true` (open door) on the module with **764 inbound `api()` calls** | `core.php:130-133`; census in report §A |

### 1.3 What already works (keep it, don't re-invent)

- **Order is a SOLVED problem** for load time: `require` resolves recursively before init (`Distributor.php:864-879`). The ERP manual `usort(order)` wizard is re-solving it in the wrong dimension (install-time data seeding).
- **The ledger is a SOLVED problem**: scope-partitioned, checksummed, `--status` exit code = deploy gate (M2). Readiness can be READ from it.
- **The warning pattern is a SOLVED problem**: await-unresolved (`Distributor.php:288-295`) is exactly the UX that `require`-absent needs.

---

## 2. Design sketch (normative, sign-off pending)

### 2.1 Vocabulary (three states, one derivation)

```
declared   — module is listed in dist and loads (today's `require` graph guarantees order)
provisioned— all migrations it DECLARES are applied  := DERIVED from MigrationManager::getStatus(scope)
enabled    — dist-level toggle, distinct from provisioned (a provisioned module can be
             disabled = not loaded; disabling NEVER touches data)
ready      — provisioned && enabled;  moduleReady('vendor/mod') answers per-request via
             M4 manifest fast path (O(1) single-row read, in-process memoized, fail-loud
             if DB unreachable — that's an app-down situation anyway, never masked)
```

`package.php` gains one key:

```php
'provision' => 'deploy',   // default when migrations are declared = today's M3 policy
'provision' => 'none',     // module declares: I seed nothing, ready == enabled
'provision' => 'wizard',   // explicit opt-in: framework wizard runner may execute MY
                           // migrations inside a guarded POST — auditable exception to M3
```

Validation: unknown `provision` values fail loud (RZ-014 style — validate, don't guess); declaring `wizard` without migrations is a validate-time error (nothing to run).

### 2.2 Events announce facts from the actor

- `module.installed` — fired **only by the process that executed migrations**: the `migrate` CLI (M2 door) after a scope's batch commits, payload `{scope, module, applied: [...names]}`. Web requests NEVER fire it (they never migrate — M3 intact for `deploy`).
- A `wizard`-provisioned module's POST runner is the *sole* web-process exception (by declaration), and fires the same event honestly.
- **No boot-time "became ready" events.** That would require a persisted watermark = second source of truth = the ERP `$installed` disease wearing a costume. Listeners that need "did you just install?" listen to `module.installed` in the CLI process; listeners that need "is it ready NOW" call `moduleReady()`.

This kills the ERP `registerInstall(order, ...)` class of problem differently: data-seeding steps (default permissions, search providers) are **listeners on the peer's `module.installed`**, ordered by the `require` graph — no integers, no collisions, no cross-module closures. (ERP's own `erp/company:dependencyResolved` proves the demand existed.)

### 2.3 Enable/disable becomes real (resurrects the dead enum case)

- `php Razy.phar module status <dist>` — table: code, version, provision, enabled, pending-migrations, ready. Exit-code non-zero when anything is declared-but-pending (deploy-gate parity with `migrate --status`).
- `php Razy.phar module enable|disable <dist> <code>` — writes the dist enable/disable list; `disable` sets `ModuleStatus::Disabled` (`ModuleStatus.php:29` — dead case gets its first assignment), refuses when a `require`-dependent module would break (fail-loud naming dependents, override `--force` operator-grade).
- Disabling never drops schema (data rails, Q4 below); uninstalling is NOT in this dossier (see Do-NOT-build).

### 2.4 The readiness gate (kills the 15 whitelist copies)

Route-level declaration, enforced by the dispatcher, not by handler discipline:

```php
$agent->addLazyRoute([
    'dashboard' => ['main', 'ready' => 'erp/holiday'],  // moduleReady gate
    ...
]);
```

…or module-wide default: `addLazyRoute($map, ['ready' => 'self'])` — a module's routes are gated on the module itself by default when it declares migrations. Not-ready → framework **503 page** naming the module + the exact CLI command to fix (`php Razy.phar migrate <dist>`), JSON-shaped for XHR. Wizard-mode flips the same gate to a **302** into the framework wizard runner (which then, per Q2, is token-gated: `php Razy.phar module wizard-token <dist> <code>` prints a single-use token — the bootstrap-auth chicken-and-egg solved WITHOUT the framework owning a user row, per OAuth-dossier Q1).

### 2.5 Companion XS fixes (independently valuable, shipped L0)

1. **`require`-absent warning** — mirror `Distributor.php:288-295` at `:868-870`: name the module whose dependency vanished instead of `return false` into the void. (ERP's `requires`-typo modules were silently dead; nobody noticed until an audit.)
2. **`validate` unknown-key check** — `package.php` keys are a closed set (`ModuleInfo.php` parses a known list); unknown keys (`requires`, `controllers`, `migrations`) become validate-time errors. Typo = loud, not zombie.
3. **`Emitter::has(string $command): bool`** — ask the CommandRegistry; documented replacement for `method_exists` on API objects. (The leave↔holiday dead code is what `method_exists` on a `__call` façade does.)
4. **lint: web-migrate smuggle** — `getMigrationManager()->migrate()` inside lifecycle handlers (`__onReady/__onRouted/__onEntry`) = lint error; `install.inc`-style CLI context exempt. (ERP core.php:152-163 is the named violator.)
5. **lint: cross-module namespace import** (RZ-001 blind spot today — regex only catches `require/include`): `use` of a foreign module's namespace from a controller/library file = error with the RZ-001 remedy text. (74 live ERP hits; this is rule hardening, ship with the usual `lint-allow` escape.)

---

## 3. Decision questions (maintainer table)

### Q1 — State source: DERIVED vs stored flag?
**Recommendation: derived.** `ready := declared migrations all applied` (M4 fast path answers it O(1)). Stored flags drift — six ERP copies are the corpse evidence. Modules with NO migrations declare `'provision' => 'none'` and are ready-on-enable (no phantom gate). *If rejected:* stored-flag design needs a repair command (`module reconcile`) — more surface, strictly worse.

### Q2 — M3 reconciliation: is `'provision' => 'wizard'` an allowed exception?
**Recommendation: yes, narrow.** M3 ("web requests never migrate") stays the DEFAULT; a module explicitly declaring `wizard` + operator-minted one-time token is the auditable exception. Products sold to non-ops buyers (Razy ES) need first-run setup; the alternative is every vendor smuggling `ensureSchema()` (ERP: 5+ modules do exactly that today). The exception is per-module, declared in a manifest key, lint-visible, and CLI-token-gated. *If rejected:* wizard becomes UI-over-CLI-instructions only (operator must SSH for first run — acceptable, but the ERP product need remains).

### Q3 — Event semantics: who fires, when, and what about "auto-migrate peers on install"?
**Recommendation:** `module.installed` fires ONLY where migrations actually ran (CLI door; wizard POST for declared-`wizard` modules). `module.ready` is NOT an event — `moduleReady()` predicate only. **"Auto-migrate when peer installed" is deliberately refused**: migration execution stays in the two doors; cross-module reaction is `module.installed` → *seed your own data*, never *migrate someone else*. (Install-time ordering is already solved by `require`; per-Q1 derivation removes the need for install-time coordination events entirely.) ERP's wish "trigger other module installed 就 auto migration" maps to: peer listens, seeds its own rows; schema never moves in reaction to a peer.

### Q4 — Disable/uninstall data rails?
**Recommendation:** `disable` = not loaded, routes gone, `ModuleStatus::Disabled`, **data untouched, checksums stay recorded** (re-enable must be instant, no re-apply). `uninstall` (drop ledger rows + schema) **stays out of scope** — destructive, needs its own dossier after real demand (none surfaced: ERP uninstalls nothing; the M2 rollback exists per-migration already).

### Q5 — The enable-list: where does it live?
`dist.php` today lists modules. Recommendation: keep the module list as the single `declared` door; the enable/disable list becomes `config/<dist>/modules.php` (framework-owned file, CLI writes it, git-tracked, human-readable), NOT new hidden state in `sites.inc.php`. Default absent = everything listed is enabled (zero-migration BC: today's dists behave identically with no new file).

### Q6 — Wizard bootstrap auth (chicken-and-egg: who authorizes setup when no auth module exists yet?)
**Recommendation: CLI-minted one-time token** (`module wizard-token`, 5-10 min TTL, single-use via Cache nonce — the S2 StateSigner pattern reused, RZ-015-clean). No framework user row, no bootstrap password file, audit line in the log. Alternative "allow wizard when zero modules provisioned" is a public open door during the most-likely-internet-reachable first-boot window — refused.

---

## 4. Milestones

| # | Change | Files | Pain killed | Size |
|---|---|---|---|---|
| **L0** | Companion XS pack (§2.5): require-absent warning, `validate` unknown-key check, `Emitter::has()`, web-migrate lint, cross-namespace lint | `Distributor.php`, `validate.inc.php`, `Emitter.php`, `tools/lint-module-discipline.php` + tests each | silent vanish, typo'd manifests, method_exists dead code, smuggled DDL, 74-import blind spot | XS×3 + S×2 |
| **L1** | Derived readiness: `moduleReady('vendor/mod')` (manifest fast path + in-process memo, fail-loud DB), `provision` key parse + validate rules, dist enable-list file + `ModuleStatus::Disabled` assignment on load-skip | `ModuleRegistry`, `ModuleInfo.php`, `Distributor.php`, `MigrationManager` (reuse `getStatus`) | 6 stored flags; dead enum case | M |
| **L2** | `module status/enable/disable` CLI (status = deploy-gate exit codes; disable = dependent-refusal fail-loud) | `src/system/terminal/module.inc.php` (new), help registry | no operator surface | S |
| **L3** | Route `ready` gate + framework 503/302 page + `addLazyRoute` opts; **Q2 decides 503-only vs wizard-capable** | `Agent.php`, `RouteDispatcher.php`, error pages | 15 whitelist copies, dead menus, per-handler `isInstalled()` | M |
| **L4** | Wizard runner (only if Q2 approves): token mint/verify (`StateSigner` lineage), single-use nonce, per-module step registration (framework-side, ordered by `require` graph), audit | new `Razy\Setup\*`, CLI | 38 `registerInstall` + 5 collisions + bootstrap chicken-and-egg | M |
| **L5** | Docs + dogfood: `manual/12-module-lifecycle.md`; `razymod/queue-admin`, `permissions`, `oauth` declare `provision`; ERP migration appendix (replace `$installed`+whitelist+wizard with L1-L4 shapes, phar 1.0.3→new first) | manual, modules, dossier closure | — | S |

Every milestone: own commit, gates green (`composer quality`, module lint, `validate`), phar rebuild on src changes. Push only on explicit word (standing).

## 5. Do-NOT-build (killed this draft)

- Stored install flags / "reconcile" commands (Q1).
- Boot-time readiness-change events / watermarks (Q3).
- Peer-installed → migrate-peer chaining (Q3 — schema moves in doors, not reactions).
- Uninstall-with-drop (Q4 — own dossier or never).
- Framework user row / wizard accounts (OAuth dossier Q1 stands; Q6 token solves bootstrap).
- Per-request full-migration scans (M4 manifest answers ready in one row-read).
- A generic "install framework" beyond the above (ERP's wizard is 90% the same 3 shapes; the shapes become the framework, the remaining 10% stays product code listening to `module.installed`).

## 6. Sign-off record

| Q | Decision | Date |
|---|---|---|
| Q1 | ☐ derived / ☐ stored | |
| Q2 | ☐ wizard opt-in / ☐ CLI-only | |
| Q3 | ☐ as recommended (announce-only events, predicate ready) / ☐ other | |
| Q4 | ☐ disable never drops / ☐ other | |
| Q5 | ☐ config/<dist>/modules.php / ☐ other | |
| Q6 | ☐ CLI one-time token / ☐ other | |
