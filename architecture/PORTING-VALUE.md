# Porting Value Assessment — Third-Party Modules → Razy

Generated 2026-07 from the B/C/A feature rounds. Every "existing primitive" claim below
was exercised in-session (tests green); every "not verified" claim is an explicit
unknown, not an oversight (prime directive: code beats docs).

## Evaluation basis

Primitives Razy already ships (all code-verified this line of work):

| Primitive | Location / evidence |
|---|---|
| Queue (2 stores: DB + Redis) | `Queue\QueueManager`, `Queue\DatabaseStore`, `Queue\RedisQueueStore` |
| Scheduler + cron + locks | `Scheduler\Scheduler`, `CronExpression`, `Lock/{File,Redis}Lock`, `schedule` CLI |
| i18n translator | `Translation\{Translator,FileLoader,Pluralizer}` |
| Health / metrics endpoints | `Health.php`, `Metrics.php` mounted in `src/main.php` |
| Session drivers (Redis) | `Session\Driver\RedisDriver` (inject-a-client family) |
| Bridge HMAC, CSRF, 2FA, sessions | `BridgeSignature` + `RAZY_BRIDGE_SECRET`; security guide §"real crypto/2FA/CSRF/session primitives" |
| Mailer / SSE / XHR / Profiler | io demo modules (`demo_modules/io/{mailer,sse,xhr,dom}_demo`) |
| Persistent process pool | `Razy\WorkerPool` (Windows/Linux, verified) |
| Template plugin system | `src/plugins/Template/*`, `registerPluginLoader` (governance: rules-doc Appendix E) |
| Validation rule surface | `ValidationRule` + `Validator::field()->rule()` (contract pinned by `tests/ValidationCustomRuleTest.php`) |

## Tier 1 — port now (high value × zero primitive gap)

| Candidate (origin) | Razy form | Effort | Rationale |
|---|---|---|---|
| **Queue dashboard** (Laravel Horizon) | module exposing per-queue pending/reserved/buried views; `QueueStoreInterface::count()` already returns exactly this | S–M (~3–5 d) | Queue just landed; the dashboard is its missing face. Per-distributor queue views are a differentiator Laravel lacks. |
| **Roles/permissions** (spatie/laravel-permission) | tables + `can()` service, **per-distributor scope is a free architectural win** | S–M (~4 d) | Killer fit for multi-distributor platforms. PREREQS LARGERLY MET (verified 2026-07, ORM dossier §4.3): `AuthManager` + `Gate::policy` keyed per model class exist and are tested (`Auth/`, `tests/AuthTest.php`); remaining survey is role→principal wiring only. |
| **Notifications** (Laravel) | notifier + mail/SSE/db channels — all three channel primitives already exist | M (~5–7 d) | Fan-out across distributors is a natural story. |
| **Feature flags** (Laravel Pennant) | module flag store + template modifier (`->whenFlag`) via the plugin system | S (~2 d) | Small; pairs with metrics/scheduler; template-plugin contract is pinned. |
| **Settings store** (spatie/laravel-config) | per-distributor key/value with typed casts | S | Fits per-distributor config discipline (RZ-008 clean). |

## Tier 2 — high value, missing prerequisite (survey first)

| Candidate | Gap | Effort |
|---|---|---|
| **API tokens** (Laravel Sanctum) | auth stack exists (`AuthManager`/`Gate`, tested — ORM dossier §4.3); remaining survey is narrower than feared | M (−1 d: pre-answered) |
| **Media library** (spatie/laravel-medialibrary) | GD/Imagick conversion config layer; RZ-006 file sovereignty is actually a plus (media per module data path) | M–L |
| **OAuth social login** (Socialite) | RESOLVED by [`OAUTH-SOCIALITE-HTTP.md`](OAUTH-SOCIALITE-HTTP.md): HTTP primitive exists (`Razy\Http\HttpClient`) but unhardened; in-house client + OAuth2 core chosen (option i′). S0–S3+S5 **AUTHORISED 2026-09-17** — see ADR-1 below | M (survey closed) |
| **Job batches / retry UI** | pure Queue extension (batch columns on the store) | S–M; ship with dashboard |

## Reframed (looks like a port, isn't — or already exists)

- **Backup** (spatie/laravel-backup): core is a `mysqldump` shell-out → **RZ-011 conflict**.
  Honest port = pure-PHP chunked export + `ArchiveSafety` (exists). Demoted.
- **Telescope/Debugbar**: profiler demo exists; full-depth instrumentation is core
  engineering (XL), not a module port. Spec it as a Profiler extension instead.
- **Reverb / websockets**: SSE primitive already present.
- **Octane/worker**: covered by `deploy/` pack + `WorkerPool`.
- **Breeze/auth scaffolding**: session/2FA/CSRF exist; what is missing is cookbook docs,
  not code.

## Generational investment (decide separately)

**Admin panel** (Filament/Nova class): highest strategic value for "every distributor
wants a backoffice", but a template-engine + forms + asset pipeline generational build.
If ever started, begin by specifying a forms/validation DSL — the pinned
`ValidationRule` contract is its seed.

## Wave plan

- **Wave 1 (~2–3 weeks)**: queue-dashboard + roles/permissions + settings store
  (all S–M, zero primitive gaps; permission starts with the auth hook survey).
- **Wave 2**: notifications + media library.
- **Pre-flight surveys**:
  1. auth hook-site surface — **DONE** (ORM dossier §4.3: AuthManager/Gate::policy per
     model class, tested); role→principal wiring remains for the permission module design;
  2. HTTP client existence (→ Socialite go/no-go) — **DONE** (OAuth dossier: `HttpClient`
     exists, cURL-backed; hardened in-house per ADR-1, not replaced).

## Standing caveats

- Everything from the 2026-07 rounds is **unreleased** (VERSION 1.0.3-beta pending tag).
- Dashboard/permission module work inherits RZ-014: every published API command ships
  tests from day one.
- Multi-distributor scoping changes data models (add `distributor_id` discipline early);
  retrofitting it later is the expensive mistake to avoid.

## ADR-1 (2026-09-17) — Social login: in-house OAuth2 on a hardened HttpClient

**Decided** (OAuth dossier Q1–Q5, all per recommendation): (i′) in-house — harden
`Razy\Http\HttpClient` (S1), build `OAuth2` core on it with PKCE S256 always-on and
signed stateless `state` default (S2), GitHub+Google provider pack with
`Office365SSO` re-expressed on the new core (S3), shipped to consumers as
`razymod/oauth` (S5). Framework core takes **zero third-party runtime dependencies —
now codified as RZ-015**. No `users` table in core (Q1: guard seam +
`social.user_resolved` event, identity stays app-side); old class names survive
(Q3: RZ-012 additive, internals replaced); `RAZY_ALLOW_INSECURE_TRANSPORT=1` is the
single insecure-transport escape and provider secrets live in env with config
holding references (Q5). OAuth 1.0a signer (S4) stays deferred — no named provider.
