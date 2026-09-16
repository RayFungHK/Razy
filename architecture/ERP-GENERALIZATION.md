# ERP-GENERALIZATION — What the Production ERP Says the Framework Should Own

**Status: ANALYSIS-ONLY.** No milestone here is approved or built; nothing below has been
implemented. This dossier turns one production app's copy-paste archaeology into ranked,
evidence-cited framework candidates so the *next* doctrine dossier can be chosen, not guessed.
Sibling doctrine: [MODULE-LIFECYCLE.md](MODULE-LIFECYCLE.md) (✅ BUILT — its candidates came
from the same app, an earlier survey), [PERMISSION-MODULE.md](PERMISSION-MODULE.md),
[ORM-CONTRACT-PACKS.md](ORM-CONTRACT-PACKS.md).

Date: 2026-09. Method: read-only survey (bounded grep/glob sweeps + pair-reads of the claimed
"near-identical" families) of the ERP app, cross-checked against framework v1.1 sources in
this checkout. **Every headline count below was independently re-run by the maintaining agent
before this file was committed**; deltas from the raw survey are marked *(recount)*.

## 0. Ground truth

- **App**: `C:\Users\RayFung\VSCode-Projects\Razy-Development` (phar 1.0.3). Module roots:
  `shared/module/erp/` — 6 platform modules (`core` 605 files, `department`, `group`,
  `logging`, `multilingual`, `user`) — and `sites/erp/dev/` — 37 business modules,
  ~1,000 PHP files. The same instance also serves `sites/hkgxweb/rayfungpi` and `sites/vcard/dev`.
  App self-documentation exists: `docs/MODULE-API-MATRIX.md` (27 modules / 270 routes / 60 API
  commands) and an app-owned `scripts/module_sovereignty_lint.py` — the app is hand-building
  framework affordances today, which is itself a finding.
- **Excluded as already generalized (v1.1)**: derived readiness, route gates + 503/302,
  provision doors, `module` CLI, `/__setup` runner, `module.installed`, RZ-016/RZ-017.
- **Correction to MODULE-LIFECYCLE.md §1**: the stored-flag footprint is `['installed']`
  at **69 references across ~35 controllers** — the "6 stored flags" cited there counted
  distinct config keys, not sites. Same shape, much larger N; the doctrine is unchanged,
  the sales number was conservative. *(recount: grep `['installed']` in controller trees)*
- **Recount deltas vs the raw survey**: `api('core')->getDB` family is **433** sites
  (`api('core')?->getDB(` — the nullsafe form was the one actually used); `api('group')`
  auth-hub calls **327**; app-wide `getDB(` anywhere **439**. Case-insensitive `csrf`
  finds 4 hits app-wide — **all four are OAuth `state` comments**, zero form/route CSRF
  defense; the "0" headline stands with this nuance.

**Headline finding.** The six `shared/module/erp/*` modules *are* a hand-rolled admin platform
on top of Razy: `core` (menu registry, page shell, **DB broker**, config, install), `group`
(auth hub — already speaking `Razy\Auth\Gate` at
`shared/module/erp/group/default/controller/api/auth.php:9-14`, proving that shipped engine in
production), `user` (session/SSO/remap), `logging` (audit), `multilingual` (i18n),
`department`. The families below are mostly the 37 business modules re-talking to that hub in
copy-pasted closures. Each one is "every Razy app with an admin UI will need this".

## 1. Ranked candidates

Rank = frequency × pain × framework-fit. Sizes are framework-side work.

| # | Candidate | Verified counts | Framework fit | Size |
|---|-----------|-----------|---------------|------|
| 1 | Ability **declaration** manifest + canonical deny envelope (the PERMISSION-MODULE delta) | 327 guard sites, 33 declaration waterfalls | shipped `Auth\Gate` + `razymod/permissions` already exist | M (delta) |
| 2 | Database one-door: retire the `api('core')?->getDB()` broker | **433** sites, all 37 business modules | PERMISSION-MODULE §4.2 verdict: "do not canonize" | M |
| 3 | CRUD list family: param rail, pagination envelope, latest-history join, lookup endpoints | ~25×4 list copies, 28 join sites, 38 fetch files | ORM already ships `paginate`/`lazyGroup` | M (3 sub-ships) |
| 4 | Admin-shell wiring manifests: menu/sidebar, page shell, search + dashboard providers | 74 menu + 41 active + 82 shell + 13 + ~25 provider copies | `package.php` manifest idiom (the `provision` precedent) | L (split) |
| 5 | CSRF rail (default-on for mutating routes) | **0** checks on ~270 routes; `Razy\Csrf\*` shipped, unwired | wiring work, not building work | M |
| 6 | Audit-log write/read contract | 91 write sites, 3 raw-SQL readers crossing module lines | no framework service exists today | M |
| 7 | Response envelope unification (4 concurrent JSON dialects) | 771 `xhr()` vs 233 raw `echo json_encode` | `XHR::sendEnvelope` exists, unused | M |
| 8 | Upload / attachment service | 14 verbatim `move_uploaded_file` workers, **zero** MIME validation anywhere | `Razy\Upload` absent; RZ-006 rails exist | M |
| 9 | User-id remap as declared refs + framework event | 20 listener copies, all RZ-017 FQCN imports | pure column metadata → declarable | S–M |
| 10 | Module help system (md doors shipped in core) | 19 copies, 2 divergent payloads | help already a framework concept | S–M |
| 11 | i18n pack auto-registration + locale door | 47 `registerPack`, 35 packs; open-redirect class at `switch_lang.php:19-20` | `Razy\Translation` shipped, unwired | S–M |
| 12 | Mail hub: SMTP-config module + `Notification` channels wired | 3 heavyweight NotifyWorkers + hub module + 3 waterfalls | `Razy\Notification\*` shipped, unwired | M |
| 13 | Schema-drift rails (RZ-018: DDL outside `migration/`) + `api-matrix` CLI | 67 `ALTER`/`SHOW COLUMNS`/`ensureColumn` sites | RZ-016 family + ContractCompiler drift snapshot exist | S–M |

Cross-cutting: the hub-glue imports violating RZ-017 while *trying* to be framework
affordances — `use erp\company\CompanySupport` (10+ files),
`use erp\task\TaskApplicationDashboardWorker` (10 identical `api/dashboard.php` files),
`use erp\core\LatestHistoryJoin` (15 files), `use erp\user\UserRepository` (4 files);
76 cross-module `use erp\…` total (tree-wide RZ-017 L0 count 104). These are counted here as
**missing-affordance evidence**, not lint noise — the app reached across module lines because
the framework gave it no other door for what genuinely *is* shared machinery.

## 2. Candidate detail

### C1 — Ability declaration manifest + canonical deny envelope

**Current shape.** Enforcement, copy 300+ of:
`if (!$this->api('group')?->auth('erp/<mod>', '<mod>.<action>')) { $this->xhr()->send(false, $i18n?->getText('<mod>:no_auth') ?? 'No permission'); return; }`
(instances: `payment/default/controller/api/list.php:8-12`,
`calendar_event/default/controller/api/delete.php:10`, `task/default/library/TaskListWorker.php:127-128`).
Declaration, copy of 33: a `erp/group:registerPermissions` listener returning a literal map
(`it_worklog/default/controller/it_worklog.php:66-77`).

**Already half-shipped.** PERMISSION-MODULE.md: the Gate engine is built; ERP's `group` wired
it privately and it works in production (`api/auth.php:9-14` → `Gate::allows`). ERP ability keys
are already dot-named (`payment.view`, `task.view.task_audit`) — the §7.4 naming won by usage.

**The delta to generalize (do NOT re-propose the engine):**
1. `package.php 'abilities' => ['payment.view' => ['label' => …]]` consumed by the permissions
   module — kills the 33 waterfall copies and makes the catalog `validate`-checkable (exactly
   what `provision` did for install steps).
2. Canonical deny: a `deny()` handler helper + `AccessDeniedException` + the already-specified
   `permission.denied` event — kills the 327 hand-rolled deny shapes, gives one response
   envelope and a free audit hook.
3. Route-level wiring (`AuthorizeMiddleware`) so the check moves into the route definition.

**Rails/BC**: additive; `group->auth()` survives as a bridge (it already speaks Gate). **M.**

### C2 — Database one-door (retire the `getDB` broker)

**Current shape.** `$db = $this->controller->api('core')->getDB();` as the FIRST statement of
essentially every Worker/Repository: **433 verified sites** (`?->` form;
`payment/default/library/PaymentListWorker.php:28`, `registration/default/library/RegistrationSupport.php:15`
— 8× in one file — `*/controller/api/install_action.php:6` in ~30 modules). `core` answers with
a **live PDO/Database object crossing the module boundary** (`core/default/controller/api/getDB.php`)
— PERMISSION-MODULE §4.2 rated this "production habit … **do not canonize**"; it is also a
RZ-008 shared-live-object smell and the app's own API matrix documents it as *the* platform surface.

**Proposed shape.** The `ModuleDatabaseConnector` one-door idiom, dist-scoped: `config/<dist>/database.php`
read at boot → one registered `Database` (prefix applied) → `Controller::getDatabase()` helper —
making the compliant door the *easy* door (Controller today documents `resolve(Database::class)`
at `Controller.php:659-662`, near-zero adoption proves easy wins over correct-but-verbose).
CLI parity closes the known "queue CLI opens no DB" hole. Optionally `validate` warns when a
module exposes a live `Database` via `addAPICommand`.

**Rails/BC**: fully additive; broker dies with migration. **M.**

### C3 — CRUD list family

**a · Param collector + pager envelope.** ~25 `api/list.php` copies collecting the same params
with drift-prone aliases (`$_GET['per_page'] ?? $_GET['pageSize'] ?? 20`; one file already
drifted to `$_REQUEST['pageSize']`: `appform/.../list.php:19-24`) and ~25 Workers rebuilding
`{rows,total,page,per_page,pages}` with `max(1,min(100,…))` clamping verbatim
(`AddressChangeListWorker.php:55-151` ≅ `ActiveManagerListWorker.php:62-152` ≅
`ItWorklogListWorker.php:70-173`; byte-identical `@return array{rows:…}` docblocks across 7
Repositories). Framework `paginate()` exists — 2 modules use it. Shape: canonical pager envelope
+ `FormRequest`-shaped param collector with whitelisted sort bindings (`FormRequest` shipped,
near-zero adoption). **M.**

**b · Latest-history join.** `LatestHistoryJoin::attach(...)` — a top-1-per-group subquery — lives
in the hub and is RZ-017-imported into **14 modules / 28 call sites** (`ContactsSupport.php:367,418,593`,
`MemberPreviewSupport.php:42-54` asOf variant). Zero domain semantics → belongs in
`Razy\Database\Statement` (`$stmt->joinLatest(...)`) or Contract relations. **S.**

**c · Lookup endpoints.** 38 `api/fetch/*.php` files implementing one informal widget protocol
(`?q=` vs `?keyword=` both accepted, echo json + exit; `task/api/fetch/user.php:11-18` etc.).
Shape: shipped lookup-endpoint helper with param contract + xhr response + permission hook. **S–M.**

### C4 — Admin-shell wiring manifests

What an admin module must say about itself is currently said by calling the app hub:
`addCategory/addMenu` **74 matches / ~34 modules** (7th arg already an ability key — C1 synergy);
`setActiveMenu()` **41**; `renderHeader/renderFooter()` **82**; search-provider listeners **13**
returning ~12 lines of pure config each (`address_change.php:123-139`); dashboard-provider
listeners **9** + `addDashboard` **3** + 10 identical `api/dashboard.php` copies importing a
sibling's Worker.

**Proposed shape.** `package.php` manifest sections — the `provision` precedent is exactly this
pattern (declarative, lint-visible, CLI-checkable):

```php
'shell' => [
    'menu'   => ['category' => 'membership', 'order' => 10, 'icon' => 'buildings-1', 'ability' => 'company.view'],
    'search' => ['param' => 'keyword', 'detail' => '/?open={id}', 'id_field' => 'history_id'],
    'widget' => ['provider' => 'dashboard', 'priority' => 25],
],
```

consumed by a framework admin-shell surface (extend `packages/dashboard` lineage or new
`razymod/admin-shell` — maintainer call) that renders from the module registry; active-menu
becomes routing-implicit (41 copies die). Provider closures that must compute stay closures but
register via an `Agent` method — one discoverable API replacing 5 private event-name protocols
(~85 event-name sites). **L**, split: menu manifest M / page-shell S / provider registry M.
BC risk medium: menu order numbers are load-bearing in real sidebars; values must round-trip.

### C5 — CSRF rail (the zero-count family — the app's scariest row)

Every state-mutating route in ~270 routes is guarded by the C1 auth check and nothing else;
there is no token, cookie, or header check anywhere (the only `csrf` text app-wide is OAuth
`state` comments). Framework `Razy\Csrf\{CsrfMiddleware,CsrfTokenManager}` ships **wired to
nothing**, and queue-admin had to hand-roll double-submit in the first-party module because
session issues no cookie by default.

**Shape**: wire, don't build — route-rail default-on for non-idempotent methods, token
injection on `loadTemplate`/`loadAsset`, `X-CSRF-Token` header path for XHR, declared exemptions
in the route manifest for webhooks, and a lint/validate warning for exempt-without-reason.
BC risk medium (every frontend JS must send the token — ship the injection so most work
unchanged). **M.** *This is the only row on this list that is an active security exposure
rather than a maintainability tax.*

### C6 — Audit-log contract

Three writer shapes: ~25 per-module `safeLog()` wrapper copies (try/catch-wrapped
`api('logging')?->log(...)` — errors vanishing by design: `MilestoneSupport.php:16-22`,
`ApiAuthSupport.php:76`, …), inline array literals in Process workers
(`CompanyProcessWorker.php:140,177`, …), **91 total call sites** — and three readers that
raw-join the hub's `logging_activity_log` table from sibling modules
(`kaiser_workflow.php:238`, `KaiserPnlSupport.php:247`, `company.php:165-166` inside a
search-provider `sql`). The audit table is de-facto shared schema; the app papers over it.

**Shape**: `Controller::audit(string $event, array $context)` + `auditDiff($old, $new)`, one
standard payload (actor, module, entity, action, old/new, occurred_at, dist), fired as an
`audit.written` event; the app's `logging` module becomes a UI over the stream; a table-owning
`razymod/audit-log` (permissions already broke the no-framework-tables rule, so the precedent
exists). Readers then go through the API. **M.**

### C7 — Response envelope fragmentation

Four JSON dialects: `xhr()` **771** sites (incl. unused `sendEnvelope`);
`{result,message,response}` via raw `echo json_encode` **233**; help's `{success,…}`, itself
forked in two (`minter/api/help/chapters.php:28` vs `active_manager/api/help/chapters.php:32`);
bare arrays for the lookup protocol. Error keys differ per dialect and the frontend JS already
compensates per module — real, counted maintenance pain. **Shape**: canonical = `XHR::sendEnvelope`
+ `xhr()->list($rows, $paginator)` (the C3a shape) + a lint (RZ-019 candidate: handlers answer via
XHR/View, not `echo`+`header`+`exit`). **M** (mostly migration rails).

### C8 — Upload / attachment service

14 near-verbatim `move_uploaded_file` workers (per-module extension allowlists,
`bin2hex(random_bytes(8))` names, manual `getDataPath()` joins — and one variant doing it inline
in a controller closure: `announcement/api/attachment/upload.php:49`). **No MIME validation
exists anywhere in the app.** Attachments are per-module columns while task/appform hand-maintain
their own attachment row sets. **Shape**: `Razy\Upload` service — declared profile
(extensions/max-size/optional image re-encode), RZ-006 data paths, generated names, optional
attachment-record door. Centralizes the actual security surface (MIME) that today lives nowhere.
**M.** BC: on-disk files keep paths; new uploads take the service.

### C9 — User-id remap as declared refs

20 `erp/user:migrate` listener copies, every one FQCN-importing a sibling's `UserIdRemapHelper`
(RZ-017 as service of an unframeworked affordance): `address_change.php:141-153`, `task.php:144`,
`transfer.php:193`… **Shape**: `package.php 'user_refs' => ['<table>' => ['created_by', …]]`
(pure column metadata → declarable) + framework-dispatched `user.merged` event fanning out to
each owning module's own commands; remap SQL runs module-locally. UI stays app-side. **S–M**;
20 hand-copied column lists today = guaranteed drift; payoff is data integrity.

### C10 — Module help system

19 copies of `help/{lang}/*.md` + chapters/content endpoint pairs, already diverging into two
payload dialects; content served by raw `file_get_contents` guarded only by filename checks
(`active_manager/api/help/content.php:12-27`). **Shape**: `$agent->helpDir('help')` — framework
registers the two doors per module when the dir exists, with lang fallback and the `@ml` inline
string function. **S–M**, zero BC risk on the reader side.

### C11 — i18n pack auto-registration + locale door

47 `registerPack/loadPack` copies (some modules register twice: `company.php:33,173`), alias
drift off the module code (`addrchg`, `actmgr`, `pdecl`…), and the `$i18n?->getText('k') ?? 'English…'`
tail everywhere. The hub's `switch_lang` door carries the **open-redirect defect class**
(`multilingual/default/controller/api/switch_lang.php:19-20`, confirmed). `Razy\Translation`
ships unwired. **Shape**: auto-register each module's `lang/` keyed by module code (opt-out +
alias override for the 7 drifted names), `getText(key, default)` replaces the `??` tail, and a
framework locale-switch door with validated referer — **generalizing the defect away** rather
than patching one app's copy. Overrides UI stays an app module. **S–M.**

### C12 — Mail hub

App module `notification` = shared SMTP config + smoke inbox; 3 heavyweight per-module
NotifyWorkers (250–450 lines each) re-implement isEnabled-gate → recipients → render → send
(`GtlinkNotifyWorker.php:95,224,457`, `LeaveNotifyWorker.php:115,264`, `TaskNotifyWorker.php:95,226`);
`erp/notification:register` waterfall ×3. Framework `Notification\{NotificationManager,MailChannel,DatabaseChannel}`
ships unwired with **no SMTP-config story** (credentials live where? nothing says). **Shape**:
`razymod/mail` first-party — per-dist SMTP config table (encrypted password column), smoke/dev
inbox channel, payload registry replacing the waterfall, dispatch through the existing channels;
NotifyWorkers shrink to "compose payload, send". Queue affinity noted, NOT proposed (§3). **M.**

### C13 — Schema-drift rails + `api-matrix`

67 request/install-time `ALTER TABLE` / `SHOW COLUMNS` / `ensureColumn` sites across ~14 files
(`GtlinkSupport.php:58-93` including a runtime `MODIFY COLUMN` type widening;
`LeaveSupport.php:105-121` — 17 calls; …) *while* `vcard` also ships proper `migration/` files —
two mechanisms coexisting and drifting. The shipped RZ-016 lint only catches
`getMigrationManager()` in handlers; raw DDL walks free. **Shape**:
(a) **RZ-018** — DDL outside `migration/` = lint fail, message points at `php Razy.phar migrate`;
(b) promote the ContractCompiler drift snapshot into `validate <dist>` so drift fails CI;
(c) `php Razy.phar api-matrix <dist>` (+ Agent-readable twin) replacing the app's python script.
**S–M** ((a) and (c) are S each; (b) rides shipped machinery).

## 3. Refused — repeats that must stay app-side

- **Business workflows**: task pipeline rules, leave accrual, gtlink fee math, `kaiser_pnl`'s
  global-function style (`kaiser_pnl.main.php:11` includes a closure file; `kaiser_workflow.php`
  declares 6 global functions) — product idiom, RZ-001-adjacent; the framework should keep
  discouraging it, not absorb it.
- **Menu/label/order/icon values** and the category tree: IA decisions. The manifest (C4) is the
  framework part; the content is the app's.
- **Domain lookup semantics** (which fields a "company fetch" searches): only the protocol
  generalizes (C3c).
- **User row / SSO ownership**: ruled app-owned (PERMISSION-MODULE §9, OAUTH Q1). Adoption note:
  the ERP hand-rolls Office365 while `Security\OAuth2` + `MicrosoftProvider` ship in the phar it
  runs — that is **failed adoption of shipped code**, not a gap; same for `Razy\Session`. The
  fix for these is migration (manual/12 §8's table), not new framework surface.
- **Dashboard/favorites composition**: product.
- **Queue adoption**: ERP usage is zero (one DB column literally named `in_queue` is the whole
  footprint); proposing queue rails now is premature — C12's async dispatch is the point where
  it becomes a live conversation.
- **Server-side CSV export**: does not exist in the app (exports are client-side JS); no
  candidate.

## 4. Verified counts appendix

Independently re-run with PowerShell `Select-String` sweeps over the app tree (PHP files only,
1,461 files); deltas from the raw survey marked. `sites/erp/dev` unless noted.

| Pattern | Count | Notes |
|---|---|---|
| `api('core')?->getDB(` | **433** *(recount; survey said 388)* | hub DB broker, live object across module lines |
| `getDB(` anywhere | 439 | |
| `api('group')…` auth calls | 327 *(survey: 300 for the full guard shape)* | + platform modules |
| `erp/group:registerPermissions` listeners | 33 | |
| `->xhr()` | 771 | |
| raw `echo json_encode` | 233 | |
| `api(logging)` log/read | 91 | + 3 raw-SQL readers crossing module lines |
| `move_uploaded_file` | 14 + 1 closure variant | zero MIME validation anywhere |
| menu wiring (`addCategory/addMenu/addSubMenu`) | 74 | ~34 modules |
| `setActiveMenu(` / `renderHeader+Footer` | 41 / 82 | |
| search + dashboard provider wiring | 13 / 9+3+10 | 5 private event protocols, ~85 name sites |
| list-family param/pagination lines | 206 | ~25 controllers + ~25 workers |
| `LatestHistoryJoin` (imports+attach) | 44 | 15 files / 28 sites / 14 modules |
| `api/fetch/*` lookup endpoints | 38 | |
| `library/*Worker.php` files | 271 | Worker-per-command idiom |
| lang packs (`lang/en.php`) / `registerPack(` | 35 / 47 | |
| help endpoint copies | 19 | 16 business + 3 platform |
| `erp/user:migrate` listeners | 20 | all via one sibling class (RZ-017) |
| DDL-at-runtime (`ALTER`/`SHOW COLUMNS`/`ensureColumn`) | 67 | ~14 files |
| CSRF checks (app PHP) | **0** (4 comment-only `csrf` texts — OAuth state) | ~270 mutating routes unguarded |
| `['installed']` stored-flag refs | 69 across ~35 controllers | correction toward MODULE-LIFECYCLE §1 |
| cross-module `use erp\…` | 76 | tree RZ-017 L0 count was 104 |
| queue / CSV export | 0 / 0 | refusal evidence (§3) |

**Uncertainty.** Counts are grep totals on fixed patterns (case-sensitive where stated);
"near-identical" judgments were verified by reading pairs, not exhaustively. `getText(` not
counted (universal tail). Whether the admin-shell rides `packages/dashboard` or a new
first-party package is deliberately left as the next dossier's decision.

## 5. Sequencing recommendation (for the maintainer's next dossier pick)

1. **C5 CSRF rail** — the only *active exposure* on the list; it is wiring work on shipped
   pieces; every quarter without it is a compounding risk on a production ERP.
2. **C2 Database one-door** — 433 sites of a live PDO crossing module boundaries; the one-door
   idiom is the framework's own proven pattern (ModuleDatabaseConnector); unblocks honest
   RZ-008 posture and the CLI DB hole.
3. **C1 abilities manifest + deny** — completes PERMISSION-MODULE.md (engine already proven in
   this very app); the delta is declaration + one deny shape, not a new engine.
4. C13(a) RZ-018 + C3b joinLatest are the cheapest quality-of-life rows and can ride any release.
5. C4 admin-shell is the largest and the most product-shaped — worth its own signed dossier
   (Q-style questions: package home, menu round-trip BC, provider closure lifecycle).

C6–C12 are each a plausible standalone dossier; nothing here argues for a big-bang v2. The
L0→L5 arc's process is the suggested template: survey → signed Q&A → milestones with own
commits + gates, rails written before code.
