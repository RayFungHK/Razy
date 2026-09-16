# Razy Framework — User Manual

**The canonical, code-verified user manual for Razy v1.0.x.**

Every API example in these pages was checked against `src/library/Razy/` or copied from a
working file under `demo_modules/`. Claims carry a `file:line` citation. Roadmap items are
marked **[planned]** and are never presented as existing. Examples that demonstrate an idea
rather than a copied pattern are marked *illustrative*.

> Where these pages and the code disagree, **the code wins** — and that is a bug in these
> pages. Report it.

---

## Table of Contents

| # | Document | What it covers |
|---|----------|----------------|
| — | [README.md](README.md) (this file) | Index, reading paths, conventions, known doc drift |
| 01 | [01-getting-started.md](01-getting-started.md) | Install, CLI, project layout, first module end-to-end, Docker dev, subdirectory installs, pitfalls |
| 02 | [02-modules-and-lifecycle.md](02-modules-and-lifecycle.md) | Module anatomy, lifecycle hooks, versions/tags, `dist.php`, API/bind/events, permission gates |
| 03 | [03-routing-and-requests.md](03-routing-and-requests.md) | Routing APIs, routed info, `xhr()`, superglobals policy, worker-mode request handling |
| 04 | [04-database.md](04-database.md) | Connections, Statement builder, Simple Syntax, transactions, migrations, ORM, injection discipline |
| 05 | [05-templates.md](05-templates.md) | Real template syntax, modifiers, blocks, plugin format, DOM builder, XSS discipline |
| 06 | [06-packages-and-deployment.md](06-packages-and-deployment.md) | `package.php`, compose, autoload isolation, standalone packages, phar, Docker reality, deployment checklist |
| 07 | [07-security-guide.md](07-security-guide.md) | Crypt, 2FA, CSRF, sessions, bridge trust, threads, dependency trust, hardening checklist |
| 08 | [08-coexistence.md](08-coexistence.md) | Same-host PHP+foreign-app routing: why `.htaccess` claims everything, `exclude_paths` sibling declarations, edge-proxy topology, the impossibility boundary |
| 11 | [11-permissions.md](11-permissions.md) | `razymod/permissions`: fail-closed doctrine, config keys, decision grammar, API surface, `{@can}` templates, `permission.denied` audit event, razit migration |
| 12 | [12-module-lifecycle.md](12-module-lifecycle.md) | Derived readiness (`moduleReady`), `provision` (deploy/wizard/none), route `ready` gates with framework 503/302, `module status/enable/disable/wizard-token`, the token-gated wizard door, honest `module.installed` |

*(09 and 10 are intentionally unassigned: 09 is reserved by the OAuth dossier
(`architecture/OAUTH-SOCIALITE-HTTP.md` S5 → `manual/09-social-login.md`); 10
is held. Numbering follows the dossiers' own reservations, not first-available.)*

---

## Choose your reading path

### Agency developer — "I run many client sites from one codebase"
1. [01-getting-started.md](01-getting-started.md) — full walkthrough (~30 min).
2. [02-modules-and-lifecycle.md](02-modules-and-lifecycle.md) — how `dist.php` maps sites to versioned modules.
3. [06-packages-and-deployment.md](06-packages-and-deployment.md) — per-distributor dependencies and one-phar deploys.
4. [07-security-guide.md](07-security-guide.md) §bridge — before wiring two sites together.

### Module author — "I build `vendor/my-module` for reuse"
1. [02-modules-and-lifecycle.md](02-modules-and-lifecycle.md) — anatomy, hooks, API/bind/events discipline.
2. [05-templates.md](05-templates.md) — shipped syntax + the built-in `->escape` modifier (v1.0.3-beta+; no auto-escaping — discipline still required).
3. [04-database.md](04-database.md) — values only via `assign()`; identifier rules.
4. [03-routing-and-requests.md](03-routing-and-requests.md) — placeholders, `xhr()`, statelessness in worker mode.
5. [12-module-lifecycle.md](12-module-lifecycle.md) §9 — **hands-on tutorial**: a module's
   first run end to end — declarations, route gates, both migration doors, the live
   503/302 answers. Every expected output was taken from an actual run.

### Operations — "I deploy and babysit this thing"
1. [06-packages-and-deployment.md](06-packages-and-deployment.md) — the Docker reality table is mandatory reading.
2. [07-security-guide.md](07-security-guide.md) §checklist — non-root, HTTPS, gated bridges, health endpoint.
3. [01-getting-started.md](01-getting-started.md) §CLI — to know what the dev commands actually do.
4. [12-module-lifecycle.md](12-module-lifecycle.md) §4 + §9 — `module status` as the deploy
   gate (non-zero exit = pending/unreachable), and the wizard-token door for first-run setup.

### AI agent — "I generate Razy code"
1. Read [`skills/RAZY-AI-RULES.md`](../skills/RAZY-AI-RULES.md) and [`AGENTS.md`](../AGENTS.md) **first** —
   the Golden Rules (RZ-001…RZ-014) are stable, quotable, and lint-enforced.
2. This manual is the API reference companion; the rules pack is the discipline layer.
3. Self-check every generated example against the verified surface in the rules pack
   ("Verified API surface (2026-07)" section) before proposing it.

---

## How this manual relates to the rest of the docs

| Surface | Status | Trust level |
|---|---|---|
| [`readme.md`](../readme.md) | Honest Edition (v2, 2026-07; drift items below since fixed where marked ✅) | High — same verification standard as here |
| `manual/` (these pages) | v1 (2026-07) | High — code-verified, cited |
| [`skills/RAZY-AI-RULES.md`](../skills/RAZY-AI-RULES.md) | v1.1 | High — authoritative for *how you must write code*; the two inline event-firing examples were corrected (2026-07 follow-up) |
| [`AGENTS.md`](../AGENTS.md) | current | High — the short-form rule table |
| `demo_modules/**` | working code | High for *shapes* (module.php, package.php, controllers); **low for template modifier syntax** (below) |
| `changelog/` | current | Medium — changelog entries can describe fixes later reverted (see `RAZY-ANALYSIS-REPORT.md` §8.1) |
| `documentation/` (generated HTML site) | legacy | **Do not trust** — pre-1.0 generator output with known API drift |
| `RAZY-ANALYSIS-REPORT.md` (repo root) | audit (2026) | High — security/isolation/benchmark audit these pages link to freely |
| `site/` and `demos/` (referenced by readme's doc map) | shipped (2026-07) | High — zero-build docs site + lint-clean golden demos (were [planned] when this table was written) |

---

## Known documentation drift (verified, reported, not fixed here)

These contradictions exist **today** in files outside `manual/`. Verified 2026-07:

> **Status — 2026-07 follow-up (post-publication fixes):** ✅ resolved: #1 (readme Quick
> Start rewritten to the real `build .` → `set -i` flow; `help.inc.php` now mirrors the
> actual 24 commands — zombies removed), #3 (readme & rules-pack event snippets now use
> `trigger(...)->resolve(...)` + qualified `listen` names), #4 (`site/` and `demos/`
> shipped), #5 (`RZY__*` claim removed from readme), #6 (`rewrite` dropped from Quick
> Start), #8 (`Razy.phar publish` now fails with an actionable "use `pkg publish`" hint —
> `src/main.php` dispatch guard), #9 (`VERSION` synced to `1.0.3-beta`), #10 (the 9
> shipped modifier plugins' `{$var|modifier}` docblocks corrected to `->modifier`), #11
> (readme says "13 hooks + await callbacks"). 🔶 in flight: #2 (legacy `|upper` demos —
> `demo_modules` migration in progress). ⛔ still open: #7 (dead `integer()->primary()`
> migration docblocks in `Controller.php`/`Migration.php` — needs src fix + the same in
> `TModifier.php`'s `truncate:50` example was fixed). The `->escape` modifier these notes
> reference as "patch" **is now built-in** (v1.0.3-beta).

1. **README Quick Start says `php Razy.phar init dist mysite`.** No `init` command exists:
   `src/system/terminal/` ships 27 command files and none is `init` (see
   [01-getting-started.md](01-getting-started.md) §CLI for the real flow — `build` then `set -i`).
   `src/system/terminal/help.inc.php`'s own command list is also stale in both directions
   (lists `fix`/`man`/`commit`/`query`/`update` which have no command file).
2. **Template demos use legacy `|` modifier syntax.** e.g.
   `demo_modules/core/template_demo/default/view/demo_variables_modifiers.tpl:1` (`{$text|upper}`)
   and `demo_variables_chain.tpl` (`{$raw_input|trim|capitalize}`). Under the current engine
   `|` is a **fallback chain** (`Template/Entity.php:388-396`), so `{$text|upper}` renders
   `upper` as a dead alternative and outputs the raw value. Use `->upper` — see
   [05-templates.md](05-templates.md).
3. **README event example passes an array as `trigger()`'s second argument**
   (`$this->trigger('post:onPublished', ['id' => $post['id']])`, readme Core Concepts).
   The actual signature is `trigger(string $event, ?callable $callback = null): EventEmitter`
   (`Controller.php:339`). The verified firing pattern is
   `$emitter = $this->trigger('event'); $emitter->resolve($payload);` — from the working
   `demo_modules/core/event_demo/default/controller/event_demo.php:63-64`. Same issue in
   `skills/RAZY-AI-RULES.md` RZ-008's snippet.
4. **README doc map references `demos/` and `site/`** — neither directory exists; the demos
   live in `demo_modules/`, the site is planned.
5. **README security table row "secrets via env overrides (`RZY__*`)"** — a global `env()`
   helper exists (`src/system/bootstrap.inc.php:294`) but no `RZY__*` auto-override mechanism
   exists anywhere in `src/` (verified by full-tree grep). Config values come from PHP arrays
   you edit.
6. **README "Quick Start" also runs `php Razy.phar rewrite mysite`** — `rewrite` regenerates
   webserver rewrite rules (`.htaccess`/Caddyfile output); it is not what makes the module
   loadable after you add it to `dist.php` (that is `compose`, then serve). Harmless, but do
   not rely on `rewrite` for module wiring.
7. **Migration docblock examples are dead code.** `Controller.php:620-643` and
   `Database/Migration.php:35-47` show `$table->integer('id')->primary()->autoIncrement()` —
   no such methods exist in `src/` (tree-wide grep). The real API is
   `$table->addColumn('id=type(auto)')`-style simple-syntax (`Table.php:162`,
   `tests/MigrationTest.php:196`) — see [04 §5](04-database.md).
8. **`publish` is a stub** — `publish.inc.php:16-17` says the standalone command was removed;
   use `php Razy.phar pkg publish`. (`help.inc.php` additionally lists a nonexistent
   `update` command.)
9. **Version skew:** `VERSION` file says `1.0.2-beta`, `composer.json` says `1.0.3-beta` —
   trust neither blindly; check `php Razy.phar version` on the running binary.
10. **Docblock paths include `.php`, which registration args must NOT.** `Agent.php:267`
    (addLazyRoute example) shows `./controller/1/2/3/test.php` — that is the *resolved file*,
    not the registered path. `ClosureLoader` appends `.php` itself (`ClosureLoader.php:130`),
    so registering `'api/x.php'` looks for `api/x.php.php` → never loads. Rules box in
    [02 §6.1](02-modules-and-lifecycle.md).
11. **README says "14 hooks".** `Controller` declares **13** overridable `__on*` methods; the
    14th "phase" in the README diagram is the `await`-callback stage, not a method — full
    table in [02 §2](02-modules-and-lifecycle.md).

---

## Conventions used in these pages

- **`src/...:line`** — the claim was checked at that line at writing time.
- **[planned]** — roadmap item; no runtime code exists (cross-tenant process isolation,
  Helm charts, `Dockerfile.tenant`, full `spawnPHPCode` **removal** (deprecated
  v1.0.3-beta, still functional), Agent-level `lang()` helper, …).
  Note: `/_razy/health`, `/_razy/metrics`, the `escape` modifier, `Razy\Scheduler`,
  `Razy\WorkerPool`, `Queue\RedisQueueStore`, and raw
  K8s manifests (`deploy/k8s/`) now have runtime code pending the next release (unreleased —
  they left this list in the 2026-07 hardening/feature rounds).
- ***illustrative*** — example composes verified APIs into a new snippet; the APIs are real,
  the combination is not copied from a demo.
- **Golden Rules** — RZ-001…RZ-014 from [`skills/RAZY-AI-RULES.md`](../skills/RAZY-AI-RULES.md);
  violations are lint errors (`php tools/lint-module-discipline.php`). Quote the ID in PRs.
- Module code format is `vendor/package` (`validate` enforces it;
  [`readme.md`](../readme.md) Golden Rules table).

## Definition of done (same as `AGENTS.md`)

```bash
composer quality                                        # cs + phpstan + phpunit
php tools/lint-module-discipline.php sites/<dist> vendor/module shared/module --format=json
php Razy.phar validate <dist>
```

All three green — or the failure explained. Never ship an API command without a test
(RZ-014).
