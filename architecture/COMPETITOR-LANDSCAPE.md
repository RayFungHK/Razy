# COMPETITOR-LANDSCAPE — Benchmarking Targets, Real Gaps, and the Benchmark Verdict

**Status: ANALYSIS-ONLY** (2026-09). Companion to [ERP-GENERALIZATION.md](ERP-GENERALIZATION.md)
— that dossier lists gaps seen from ONE APP's copy-paste archaeology; this one lists gaps
seen from the FRAMEWORK MARKET's current state. Where both lists name the same row (CSRF,
i18n wiring, mail), that's the gap you can believe twice.

Sources: endoflife.date API (fetched 2026-09, cited inline), this repo's code inventory
(file:line for every Razy-side claim — including two "gaps" this author assumed were missing
and found SHIPPED: `Scheduler/` + `schedule` CLI, `scaffold`), and the 2026-07
`RAZY-ANALYSIS-REPORT.md` audit whose benchmark fairness verdict (§6.2) still stands.

## 1. The benchmarking landscape, 2026-09

| Frame | Current | PHP floor | Notes for Razy |
|---|---|---|---|
| PHP | **8.5.10** (8.5 released 2025-11-20, EOL 2029); 8.4.25; **8.2 EOLs 2026-12-31** | — | Razy `composer.json:15` says `^8.2`; CI matrix `ci.yml:19` runs **8.2/8.3/8.4 — no 8.5 job** |
| Laravel | **13.32.0** (13.0 2026-03-17, supports PHP 8.3–8.5); 12.69 security-only; **11 hit EOL 2026-03-12** | 8.3 (v13) | the benchmark suite's opponent (**Laravel 11 + Octane/Swoole**) is EOL as of this year |
| Symfony | **8.1** (2026-05); **7.4 LTS** supported to 2028, EOL 2029 | 8.2 (7.4) | the LTS pool Razy's enterprise pitch quietly competes against |
| FrankenPHP | GH fetch 404'd through the sandbox proxy this session — **version deliberately NOT claimed here**; the benchmark's Caddyfile/worker story is repo-pinned anyway | — | pin whatever the re-run uses (audit protocol rule: pinned toolchain) |

**Category honesty first** (the readme already says it at `readme.md:540` — "Choose
Laravel/Symfony when: single large app; you need the broad package ecosystem"): Razy is not a
Laravel substitute. Its benchmarkable claim is the **agency/multi-distributor niche** —
many client sites, one codebase, one phar — where Laravel's answer is a tenancy package bolt-on.
Gap analysis below therefore splits into three very different buckets; collapsing them is how
roadmaps die.

## 2. Bucket A — SHIPPED-UNWIRED (Razy's signature gap class)

The engine exists in the phar, tests pass, and the app-facing door is missing or unadvertised,
so production apps hand-roll what they already run. The ERP evidence makes this measurable
(Every row: ERP did the hand-roll while this framework's code sat loaded in the same phar).

| Shipped (file:line) | Unwired reality | Cost paid by the app |
|---|---|---|
| `Razy\Csrf\{CsrfMiddleware,CsrfTokenManager}` | no default-on for mutating routes; session issues no cookie by default; queue-admin hand-rolled double-submit **inside a first-party module**; ERP: **0 checks across ~270 mutating routes** | live exposure — ERP-GENERALIZATION C5, the single most urgent row on either list |
| `Razy\Notification\{NotificationManager,MailChannel,DatabaseChannel}` | no SMTP-config story ("credentials live where?" — nothing says) | ERP rebuilt the whole mail hub by hand (C12) |
| `Razy\Translation\{Translator,FileLoader}` | zero module wiring (`registerPack` is an app-protocol) | ERP: 47 registration copies + drifted aliases (C11) |
| `Security\OAuth2` + `MicrosoftProvider` | shipped inside the phar the ERP runs | ERP hand-rolls Office365 incl. a hand-rolled state cookie |
| `Razy\Session` | unused by the flagship app | session logic re-implemented in `erp/user` |
| `Validation\FormRequest` | near-zero adoption; no route-rail | 25 list-controllers hand-collect `$_GET` with drifting aliases (C3a) |
| `Razy\WorkerPool` (v1.0.3) | zero app adoption | — (adoption gap, not a wiring defect) |

**Why this bucket exists**: v1.x ships components as *classes*; the missing product is the
**door** (config key, route rail, package.php manifest — the exact pattern `provision` proved:
one declared key making a shipped capability unavoidable and lint-visible). An honest
framework-wide "door audit" (for every `src/library/Razy/*` subsystem: what config/manifest/rail
makes it usable from a module without writing hub code?) is itself a cheap, high-yield audit —
this bucket is the cheapest gap-class to close because the code already exists, is tested, and
the only deliverable is wiring + docs + one example module per door.

## 3. Bucket B — true absent (build-or-declare decisions)

| Capability | Nearest competitor | Razy today | Verdict input |
|---|---|---|---|
| Request-level debug UI (WDT/toolbar, per-request timeline, view/storage inspection) | Symfony Web Profiler, Telescope, Debugbar | `Profiler.php` is a **checkpoint sampler** (6 methods, `Profiler.php:56-204`) built for benchmark post-mortems — no request-level UI, no dev-overlay | real DX gap for the agency audience; medium build, high retention value |
| Asset pipeline (versioning/busting, bundler integration) | Laravel Vite integration | `Asset` handling serves files (loadAsset path/URL helpers); no content-hash/version step, no manifest.json, no bundler hook (grep: nothing) | real gap, and one the `shell`-manifest idea (ERP-GENERALIZATION C4) should absorb rather than bolt on |
| First-class observability (metrics endpoint, structured event log) | Octane telemetry, Symfony Monolog channels + OTel | `error_log`-shaped audit lines + health endpoint (manual/07) exist; no metrics, no OTel | medium; the K8s story (`deploy/k8s`) already implies Prometheus |
| Validation as a route rail (declare-once, inject-validated) | Laravel FormRequest / Symfony validator+resolver | pieces exist (Bucket A) — the *rail* is the absent part | same absent object as Bucket A FormRequest — count once |
| Migration *generation* (diff DB → migration file) | Doctrine migrations diff, Laravel (via packages) | `migrate`/`rollback`/`--status` ship; nothing writes the up() for you | nice-to-have; RZ-018 (ERP-GENERALIZATION C13a) matters more |

Found present while writing this (do not let folklore re-list them as gaps):
**scheduler** (`Scheduler/{Scheduler,Job,Lock,CronExpression}` + `terminal/schedule.inc.php`),
**scaffold generator** (`terminal/scaffold.inc.php:6-19` — module skeleton from one command),
**module registry/client** (`RepositoryManager`), **HTTP client**, **Caddyfile/rewrite compilers**,
**bridge signing**, **package signature verification**.

## 4. Bucket C — intentional trade-offs (gaps by design; defend, don't close)

| "Gap" | The doctrine behind it |
|---|---|
| No composer ecosystem of packages | RZ-015: core stays dependency-free; distribution is the module repository, and one phar is the supply-chain posture (audited, README map) |
| ORM not at Eloquent/Doctrine breadth | the framework's own path is Contract-packs + `paginate/lazyGroup` (`architecture/ORM-CONTRACT-PACKS.md`); the ERP survey's list-family asks (C3) are specific rails, not a re-build |
| Frontend is templates + assets, not Livewire/Vite-app | same page as above: the admin-shell manifest direction (C4) keeps the framework in the *wiring* business, not the SPA business |
| Multi-app distributor vs single-app tenancy | **this is the product** — Laravel needs a tenancy package for what `dist.php` + phar + per-dist config does natively; benchmark framing should lean INTO this, not Laravel's home turf |

## 5. Should the benchmark be updated? — YES, and it is a FIX-FIRST job

The existing suite (`benchmark/`, 6 k6 scenarios, FrankenPHP worker vs Octane/Swoole) has
three compounding problems as of today:

1. **The opponent is EOL.** The suite and README table run **Laravel 11** (EOL 2026-03-12)
   and label v1.0.x-era data. At minimum re-run against **Laravel 13 + Octane's FrankenPHP
   driver** — same runtime on both sides kills the Swoole-vs-FrankenPHP runtime asymmetry
   (the "Heavy CPU 2.3× Laravel" row measured *coroutines vs workers*, not frameworks).
2. **The audit's four symmetry defects are unfixed** (RAZY-ANALYSIS-REPORT.md §6.2, still
   accurate — verify before re-litigating): Razy benchmark endpoints built HTML with
   `str_repeat` instead of the template engine (`benchmark/razy/standalone/controller/app.php:43`),
   persistent-connection asymmetry, Laravel scenario 1–4 raw data absent from the repo,
   unpinned toolchain. **Re-running the old harness would propagate the exaggeration the
   audit already reprimanded** ("5×" → the README's current caveated table, `readme.md:393-411`).
3. **v1.1 changed the request path and nobody measured it.** Every matched route now passes
   `evaluateReadinessGate()` (`RouteDispatcher.php:522`) — memoized, yes, but there is **zero
   regression data** for: gated-yes (probe hit), vacuous-yes (no migrations declared — the
   common path), and the 503/302 refusals. A framework that just shipped a per-request
   predicate MUST show its per-request cost or the first skeptic will.

### The re-run protocol (absorbs audit §6.3's 10 rules, adds v1.1 rows)

- **Opponents**: Razy worker (FrankenPHP, repo `Caddyfile.razy` pinned) vs Laravel 13 +
  Octane **FrankenPHP driver** vs a plain **PHP-FPM baseline** for both (audit recommendation);
  one table row per runtime pair, no mixing.
- **Symmetry law**: both sides through their real template engine + real ORM; identical
  container limits; ini byte-identical (the suite already does this right — keep); Laravel
  `config:cache/route:cache/view:cache` (already done right — keep).
- **New scenarios for v1.1**: `07_gated_route_ready` (gate passes, memo hot),
  `08_gated_route_vacuous` (no-migration module — the default case), `09_gate_refusal_503`
  (cheap negative path), `10_module_scale_boot` (worker boot + first-request latency at
  5/25/50 modules — Distributor's honest cost curve, the number nobody has the nerve to
  publish today and everybody asks for).
- **Artifacts**: raw k6 JSON for BOTH sides into `benchmark/results/` or the run does not count;
  toolchain versions recorded per run; then — and only then — update `readme.md:395-411` and
  `deploy/k8s/README.md`'s measured anchors.
- **Cadence**: manual/nightly, not PR-gated (timing noise); a `worker-boot` smoke (latency
  budget) *could* gate PRs later if the curve is stable.
- **Sizing**: fixing the Razy-side endpoints + pinning + Laravel 13 harness + full run is
  roughly the same effort as the original 2026-07 suite — it exists, it runs on docker
  compose + MySQL + k6; it is a scheduled task, not a coding marathon. **The one thing not
  to do is re-run the old asymmetric harness to get fresher dates on inflated numbers.**

## 6. Reading order the two dossiers jointly support

If the next signed dossier picks by (evidence × urgency): **CSRF rail** appears at the top of
BOTH surveys (ERP live exposure + Bucket A here) → **database one-door** (433 sites, ERP C2;
the moduleReady/CLI DB doors already half-build it) → **doors audit** for Bucket A (one
package.php/config-key per shipped engine) → **benchmark fix-and-rerun** (§5, restores the
repo's most externally-visible number to earned honesty) → then Bucket B's debug-UI / asset
pipeline, which are agency-DX plays, not emergencies.
