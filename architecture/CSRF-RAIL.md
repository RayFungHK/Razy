# CSRF-RAIL — Arming the Engine That Already Ships

**Status: DRAFT — awaiting maintainer sign-off.** Q1–Q6 each carry a recommendation;
milestones L0–L4 activate only after sign-off. Rails inherited from the doctrine this repo
already lives by: fail-loud, ONE door per policy, no unannounced BC (L3 route-gate precedent),
declarations beat string lists, events announce but never carry truth.

The two surveys agree on the top row: `ERP-GENERALIZATION.md` C5 (**0 CSRF checks across
~270 mutating routes** in the flagship app — the only *active security exposure* on either
list) and `COMPETITOR-LANDSCAPE.md` Bucket A (engine shipped, unwired). This dossier turns
that into a door.

## 1. Evidence (all verified this session)

**The engine is real and complete.**
`CsrfTokenManager` — 32 random bytes, session-stored under `_csrf_token`
(`Csrf/CsrfTokenManager.php:51,56`), `token/validate/regenerate/hasToken/clearToken`
(:74–:141), `hash_equals` comparison. `CsrfMiddleware` — safe methods (GET/HEAD/OPTIONS)
pass; POST/PUT/PATCH/DELETE need a token in field `_token` or header `X-CSRF-TOKEN`
(`Csrf/CsrfMiddleware.php:55-66`); mismatch → HTTP **419** (`:213`) or custom `onMismatch`
closure; optional `rotateOnSuccess` + `excludedRoutes` ctor patterns. `TokenMismatchException`
carries the 419. Tests exist (`tests/CsrfTest.php`). This is a working synchronizer-token
package — nothing here is a build request for the engine.

**The door does not exist, and three witnesses prove it:**
1. `manual/07-security-guide.md:103`: *"The wiring snippet above is illustrative (no shipped
   demo wires it)"* — the manual itself says so.
2. `razymod/queue-admin` — a FIRST-PARTY module — hand-rolled its own double-submit cookie
   (`modules/queue-admin/default/controller/support/csrf.php`, consumed by 3 closures) with
   this signed confession in its docblock: *"`CsrfTokenManager` stores its token in a
   SessionInterface, and the framework Session subsystem emits NO cookie anywhere … cookie
   wiring is app-operator territory (SessionMiddleware is installed globally by the site,
   never by a module). A self-contained module shell must not assume that wiring."*
3. The confession is TRUE, re-verified today: the entire `src/library/Razy/Session/` tree has
   zero `session_start` and zero `setcookie` — a fully custom driver-based session that never
   issues its own cookie. Without the cookie there is no session identity across requests,
   hence no session-bound CSRF token, hence the hand-roll. (`SessionMiddleware` exists and
   documents app-side installation — `Session/SessionMiddleware.php:31`.)

**The app-side cost**: ERP ships ~270 mutating routes; a case-insensitive sweep app-wide finds
the string `csrf` **4 times — all four are OAuth `state` comments, zero checks**
(`ERP-GENERALIZATION.md` §4 appendix). Laravel's answer (default-on `VerifyCsrfMiddleware` +
`@csrf`) is the single most-used line in that ecosystem; Razy shipped the same engine and
shipped it unplugged.

**The wiring surface already exists**: `RouteDispatcher::addGlobalMiddleware` (:604),
per-module `Route::middleware` (`Route.php:141`) via `Agent::middleware`,
`MiddlewareGroupRegistry` (DI-whitelisted at `Module.php:1230`; `CsrfMiddleware` appears in
its docblock example at `:33` — the docblock is more real than the runtime). What's missing is
exactly: (a) a session that can carry identity, (b) a config key that arms the middleware at
boot, (c) a token surface for forms/XHR, (d) a declarative exempt with a reason, (e) rails that
shame the unarmed state loudly.

## 2. Decisions

### Q1 — Arming default: on-out, opt-in, or off-loud?
The BC cliff: flipping default-on would break every form of every existing app the moment
they upgrade the phar — unannounced BC, the same class the route gate refused (L3, shipped
as explicit opt-in). But default-off-with-noise is how we got here (shipped, silent, dead).

**Recommendation: `csrf` dist config key, three states, upgrade-neutral.**
`'csrf' => 'off'` — today's behavior, but `validate <dist>` and `module status` print an
**UNARMED warning line** (never a blocking exit — arming is the operator's deployment
decision, the tool just refuses to be quiet about it). `'csrf' => 'on'` — global
CsrfMiddleware for all mutating routes. `'csrf' => 'on' + exempt declarations` is the target
state; **`scaffold` (new dists/new modules) and the manual's getting-started emit `on` from
day one**, so every NEW project is armed by default and only upgrades sit in the warned state.
(Every Razy-app pattern in one line: no silent breakage, no silent danger.)

### Q2 — Token model: session synchronizer (main) + the cookie fix it needs
**Recommendation: fix the root cause — `SessionConfig` gains a `cookie` section and
`Session::start()` issues the cookie** (httponly, path, SameSite configurable, defaults
`Lax`, `expires/lifetime` already session-shaped), making `CsrfTokenManager`'s
SessionInterface actually mean a per-client identity. This is the Session subsystem adopting
a responsibility it always conceptually owned (its docblock promise) — for anyone already
wiring php-native sessions nothing changes (they never used this subsystem — grep proves it).
Double-submit stays the DOCUMENTED alternative for deliberately stateless surfaces
(queue-admin's pattern is valid, promoted to a documented recipe, and queue-admin itself
migrates to the main door at L4). No new token models (no JWT-style stateless crypto, no
per-form TTLs).

### Q3 — Exempt surface: Route-entity declaration, mandatory reason
**Recommendation:** `->csrfExempt('webhook: signature-verified upstream X (see ops runbook)')`
on the same Route entity that already carries `ready` gates — stored as a `csrf_exempt`
field beside `ready_gate` (`RouteDispatcher::evaluateReadinessGate` shape precedent). The
string-list `excludedRoutes` ctor param stays for BC but the MANUAL stops teaching it
(string path lists = the 15-whitelist-copy disease). **A mutating route with neither token
reach nor an exempt-with-reason can't exist once armed** — because arming covers every
mutating route by construction; the only author decision left is the exemption, and reasons
are checkable: `validate <dist>` errors on exempt-without-reason (the wizard-without-
migrations rule, same rail shape). Webhooks route: exempt + HMAC verification is the pointed
answer (bridge-secret precedent), documented in manual/07, not enforced here.

### Q4 — Token surfacing: explicit helpers, zero template black magic
**Recommendation:** `Controller::csrfToken(): string` and `Controller::csrfField(): string`
(returns the hidden `_token` input with the current token, htmlspecialchars-safe). Templates
place `{$csrfField}` (raw, author-controlled) in their forms; XHR clients read
`<meta name="csrf-token" content="{$csrfToken}">` and send `X-CSRF-TOKEN`. **No auto-injection
into every `<form>` tag**: the template engine does not parse HTML it hands out, and
auto-modifying rendered output is the same `{$var}` raw-output class of risk RZ-004 documents —
an explicit, greppable token is also the AI-readable surface this repo optimizes for.
(A future `->csrf` template modifier is a 20-line addition once the door exists; not in scope.)

### Q5 — Mismatch answers + one event
**Recommendation:** keep the engine's **419** for HTML (non-XHR): small framework page,
`Retry-After: 0`, message "token missing or expired — refresh the page and retry" (never
echo the submitted token anywhere). XHR/Accept:json gets the JSON envelope
`{"error":"csrf-token-mismatch", "fix":"refresh the page and resend the X-CSRF-TOKEN header"}`
(XHR detection reuses the gate's 503-JSON shape, L3). Custom `onMismatch` closures remain for
apps wanting redirects. Fires `csrf.failed` event (payload: module, route, via — same envelope
lineage as `module.installed`), so auditors can subscribe (denied-attempt log without storage,
permission.denied doctrine). `rotateOnSuccess` defaults ON at the door (login-style rotation —
the ctor default exists; the door sets the good one).

### Q6 — Rails surface (validate/status, lint, benchmark)
`validate <dist>`: ✗ error for exempt-without-reason; ⚠ warning for `csrf => off` ("UNARMED").
`module status`: no column change (keep the table honest); the warning lives with validate —
a 7th column would dilute the READY contract. No new RZ rule yet (the door is structural —
route coverage is by-construction, not by-diligence; lint can't grep "handler checks token"
and shouldn't try). One benchmark scenario from COMPETITOR-LANDSCAPE §5 gets its number here:
the armed middleware's per-request cost rides the `07_gated_route_*` re-run (no separate suite).

## 3. Milestones (each: own commit(s), gates green, phar rebuilt, push only on word)

| # | Deliverable | Touches | Evidence it worked |
|---|---|---|---|
| **L0** | Session cookie door: `SessionConfig` cookie section, `Session::start()` emits it; `SessionMiddleware` gains boot-time optionality | `Session/*` + tests | a browser round-trip keeps one PHPSESSID-shaped-razysid cookie WITHOUT app-side setcookie (the queue-admin confession becomes false by construction) |
| **L1** | The door: dist key `csrf` (off/on; off = UNARMED warning in validate), boot wiring (SessionMiddleware + CsrfMiddleware global registration when armed), `Controller::csrfToken()/csrfField()`, `csrf.failed` event | `Distributor` boot, `Controller`, validate | armed dist: POST without token → 419 HTML / JSON envelope; with token → passes; scaffold emits armed dist |
| **L2** | `->csrfExempt(reason)` on Route entity (field + dispatcher bypass check next to the gate evaluation), validate error for reasonless exempt, XHR meta recipe in manual | `Route`, `RouteDispatcher`, validate | webhook exempt works; reasonless exempt ✗ in validate; 419/JSON answers verified live in playground (this dossier's own tutorial standard) |
| **L3** | Playground dogfood: armed `appdemo` variant (new `demo/csrfdemo` module: form + JSON echo + exempt webhook trio), full live walkthrough recorded | playground + `tests/` source pins | live 419→token→200 loop + exempt 200 + UNARMED warning on the unarmed default — all as real terminal/browser output, pasted into the manual |
| **L4** | queue-admin migrates to the door (hand-roll `support/csrf.php` deleted, double-submit kept as documented recipe), manual/07 §4 rewritten (door version), tutorial section in `manual/12` style, ERP substitution appendix (270-route arming sequence for the next operator: flip key → grep forms → add `{$csrfField}` → deploy) | module + manual + changelog | queue-admin UI survives its own dogfood (the only first-party CSRF implementation dies) |

**Rails honored:** derived/declarations beat strings; one door (boot-time middleware, not
handler diligence); no BC surprise (off-until-armed + UNARMED noise); events announce only;
fail-loud everywhere a config lie could hide.

## 4. Do not build

- No CAPTCHA/2FA coupling (that's `Authenticator`'s), no per-form token TTLs, no token
  encryption/stateless mode (the session door IS the identity fix), no CSRF package ecosystem,
  no auto HTML-rewriting injection (Q4), no lint that greps handler bodies (Q6), no
  double-submit-as-default (it stays the documented stateless recipe, honest about its
  subdomain caveat in the manual).

## 5. Sign-off

- [ ] Q1 `csrf` dist key, off-default-for-upgrades + UNARMED warning + armed-by-default for new scaffolds
- [ ] Q2 session-synchronizer main door + Session cookie fix (double-submit demoted to documented recipe)
- [ ] Q3 `->csrfExempt(reason)` Route declaration; ctor `excludedRoutes` BC-kept but untaught; validate errors reasonless
- [ ] Q4 `csrfToken()/csrfField()` helpers, no auto-injection
- [ ] Q5 419/JSON answers, `rotateOnSuccess` on at the door, `csrf.failed` event
- [ ] Q6 validate warning/error surface, no new lint rule, benchmark rides the §5 re-run
- [ ] L0–L4 milestone shape accepted
