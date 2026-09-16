# FORMREQUEST-RAIL — the validation family finally gets a door (DRAFT, awaiting sign-off)

Date: 2026-09. Method: the CSRF-RAIL door-audit template applied to the next bucket-A
subsystem named in COMPETITOR-LANDSCAPE.md (`src/library/Razy/Validation/`).
Nothing here is decided until Q1–Q5 are signed. All evidence verified this session with
`file:line`.

## 1. Evidence

**E1 — Zero consumers, zero documentation.** `git grep FormRequest` across src (beyond
its own definition), modules/, playground/, demo_modules/, manual/: **no consumer
exists**, and the manual has **no chapter on the Validation family at all** (grep
`Validator|FormRequest|Validation` over manual/03 + manual/07: empty). The engine —
6 core classes, 14 rules, NestedValidator, 50 tests (`tests/FormRequestTest.php`) —
shipped in the v0.5 era and has been waiting for a caller ever since. CSRF at least had
a manual section explaining its wiring; validation has never had a sentence.

**E2 — `messages()` is a dead hook the docblock teaches.** `FormRequest.php:320` defines
`protected function messages()`, the usage docblock (:33-45) instructs overriding it —
and no code in `src/library/Razy/Validation/` ever calls it. Message text comes solely
from `ValidationRuleInterface::message($field)` (`FieldValidator.php:137`). Custom
messages are the single most-used FormRequest feature in the Laravel-shaped world;
here it silently does nothing.

**E3 — `fromGlobals()` lies about `$_FILES`.** Docblock `:91` says
"(`$_POST` + `$_GET` + `$_FILES`)"; implementation `:97` is `array_merge($_GET, $_POST)`.
No rule of the 14 (`Rule/` listing) validates files at all. The lie is from birth (v0.5
header).

**E4 — 403 and 422 conflate.** `passes()` (`:152-155`) ANDs authorization into the
validation verdict; `fails()` cannot tell "you may not" from "your data is wrong";
`errors()` fakes 403 as a pseudo-field `_authorization` (`:190-192`).

**E5 — No last mile.** The taught usage (docblock `:48-53`) is five lines of boilerplate
per handler — `::fromGlobals()`, `fails()`, return string, `validated()` — repeated at
every one of the ERP's 433 mutation handlers, with the door entirely in handler
diligence. There is no Controller helper; contrast the CSRF rail where one call
(`csrfField()`) rode the whole surface.

**E6 — The JSON story is backwards.** `errorsAsJson()` returns a **string** — and the
docblock literally teaches `return $request->errorsAsJson()` (`:51`), which emits
validation errors with no status code, no Content-Type, and outside the house
`xhr()->responseAsBody()` convention. Every other rail (419 CSRF, 503 readiness, 302
wizard) has a named, self-explaining envelope; validation has none.

**E7 — The engine underneath is genuinely good.** Lazy cached validation (`:132-147`),
`prepareForValidation`/`defaults` hooks, `only/except/has/filled`, NestedValidator for
structures, 231-line Validator at phpstan level 5, 50 passing tests. The problem was
never the engine; it is the door, the dead hook, and the documentation silence.

## 2. Decisions (each with the recommendation marked)

### Q1 — Door shape
**Recommendation: a Controller helper, not route middleware.**
`$this->validated(UserRequest::class)` (final, Controller): resolves
`fromGlobals()`/`fromJson()` by request Content-Type, runs authorize + validate.
Pass → returns `validated()` (handlers get clean typed input in one line).
Fail → the helper answers and dies: 403 or 422 in the house envelope (Q4); the handler
never runs, mirroring how the CSRF wrapper's 419 works — one call on the rail, zero
diligence at the call site.
Rejected: route-level `->validated(UserRequest::class)` declaration — validation is a
property of the handler's needs, not of the route (one route, many payload shapes);
and rejected is leaving it manual (E5's five-line tax is how subsystems die).

### Q2 — The dead `messages()` hook
**Recommendation: wire it, do not delete it.** Validator gains a messages map
(`field.rule` → text) applied over `$rule->message($field)` defaults. The docblock
teaches it, Laravel-shaped authors expect it, and the plumbing target is a single map
lookup in FieldValidator's error path. Deleting the hook would be honest but pays 20
years of expectation into a doc change.

### Q3 — `$_FILES`
**Recommendation: fix the lie, do not build file validation.** The docblock loses
`$_FILES`; file/image validation stays out (0 of 14 rules touch files; upload security
is its own subject — see §4). Recording this honestly beats shipping a half-surface.

### Q4 — Answers: two named envelopes, split verdicts
**Recommendation:** authorize-fail → **403** `{"error":"forbidden","message":…,"fix":…}`;
validation-fail → **422** `{"error":"validation-failed","errors":{field:[msgs]},"fix":…}`.
Same envelope family as 419 (`csrf-token-mismatch`) and 503 (`module-not-ready`): every
number names itself, `fix` carries the repair, tokens never leak. `isAuthorized()`
stays queryable for handlers that want to branch instead of answering (the helper
answers; the primitives stay usable).

### Q5 — Documentation first-appearance
**Recommendation:** manual/03 gains "Validating input" (helper usage, rules map,
`messages()`, `prepareForValidation`, envelope shapes) — the Validation family's first
manual section, same "manual teaches the rail" standard as manual/07 §4.
`tests/FormRequestTest` + a live dogfood pass (the playground's demo modules answer a
422 on a bad form field, captured for the manual) close the E1 gap for good.

## 3. Milestones

| # | Scope | Tests |
|---|---|---|
| M0 | Honesty pass: docblock `$_FILES` lie removed, `messages()` wired through Validator, 403/422 verdicts split (helper-free primitives) | +~8 units (messages override, verdict split) |
| M1 | The door: `Controller::validated()` + 403/422 envelopes (Q1/Q4), XHR-consistent | door units incl. pass-through returns validated data; fail answers with named envelope and does not reach the handler |
| M2 | manual/03 "Validating input" + playground live dogfood (bad-field POST → real 422 capture) | source pins + live outputs in the manual |

Each milestone: own commit(s), full gates (both phpstan configs, cs-fixer, suite —
now on PHPUnit 11.5), phar rebuilt on src change, push only on word.

## 4. Do not build

- No file/image upload validation (Q3; separate subject, separate dossier if ever).
- No string-rule syntax (`'name|required|min:2'`) — the object-rule design is the
  house's typed, phpstan-checked choice; a string parser would undercut it.
- No route-middleware validation declaration (Q1 rejected).
- No auto-binding/DI of FormRequest (Razy handlers are bound closures; the helper call
  is the last mile).
- No rule-set expansion beyond what M0–M2 need (the 14 rules serve current consumers;
  demand-driven growth, not Laravel-parity).

## 5. Sign-off

**SIGNED 2026-09 — Q1–Q5 ALL per recommendation** (Controller::validated() helper /
wire `messages()` / doc-fix only, no files / named 403+422 envelopes / manual/03 + live
dogfood), M0→M2 in order. Execution tracked as goal-a2d4c43e.

> **As-built (M0, 2026-09):** messages() wired via a `field.ruleName` map (ruleName =
> lcfirst'd short class name) with the override applied at FieldValidator's
> message-build time — an OPTIONAL third parameter, so the 293-test validation surface
> and NestedValidator saw zero edits. One honest self-own en route: the first docblock
> pin's needle appeared inside my own honesty comment about the lie; converted to the
> house positive-form pin (assert the wiring exists, not that the old string is gone).

> **As-built (M1, 2026-09):** the door shipped on the established HttpException
> control-flow (thrower sends, dispatcher ends gracefully) — a FAIL physically cannot
> continue into the handler, stronger than "returns null" as ever drafted here. The door
> test then caught the milestone's real prize before any consumer existed:
> `XHR::responseAsBody()` only STORED its body — emission needed a `sendEnvelope()` tail
> that **zero call sites repo-wide ever called** (and the docblock pointed at a
> `ContextHandler` class that does not exist): queue-admin and oauth had been shipping
> EMPTY bodies for their whole JSON error surface since birth. Doctrine applied — an API
> used wrong by 100% of its callers IS the bug: responseAsBody now emits-and-dies
> directly, sendEnvelope survives as an idempotent array-mode pull; eight first-party
> call sites revived with zero module edits (queue-admin 1.2.1 — also realigning its
> module.php/package.php version desync slipped at CSRF-L4 — and oauth 0.1.1, patches).
> manual/01's page that had TAUGHT the two-step chain (and blamed the readme for
> omitting it) got corrected — the readme's usage turned out to be the future-correct
> one. The phpstan pushback on a defensive `$_GET ?? []` was right: superglobals always
> exist; the unset-superglobal landmine belonged to a test tearDown and died there.

> **As-built (M2, 2026-09):** manual/03 §6 "Validating input" is the family's first
> manual chapter ever; manual/01's XHR section rewritten for the one-call emission.
> Live dogfood on the armed playground: `demo/csrfdemo` save now runs
> `$this->validated(CsrfdemoSaveRequest::class)` — token-valid POST `data=hello` → 200
> through BOTH doors, token-valid POST `data=a` → real 422 with the custom
> `messages()` text verbatim on the wire ("At least 2 characters, please."), captured in
> the chapter. Queue-admin's revived body proved live too: `act` with valid token now
> answers `{"ok":false,"error":"no database connection available…"}` where M1-pre
> answered 200-with-nothing.
