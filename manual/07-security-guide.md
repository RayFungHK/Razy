# 07 — Security Guide

Razy's isolation is **cooperative, not adversarial** — read that once and everything below
falls into place. All modules in one distributor run **in the same PHP process with the
same OS privileges**; isolation is naming discipline plus a small set of enforced fences.
The framework gives you real crypto/2FA/CSRF/session/thread primitives — the security of an
app is mostly your use of them.

Verified sources: `Crypt.php`, `Authenticator.php`, `Csrf/`, `Session/`,
`ThreadManager.php`, `Container.php`, `Module/CommandRegistry.php`, `Controller.php`, plus
the 2026 audit (`RAZY-ANALYSIS-REPORT.md`) and the rule pack
([`skills/RAZY-AI-RULES.md`](../skills/RAZY-AI-RULES.md)).

---

## 1. What the framework *does* enforce

| Fence | Mechanism | Verified |
|---|---|---|
| DI fence | Resolving framework internals (`Razy\Application`, `Container`, `Module`, …) from module code throws `SecurityException` | `Container.php` `blockedAbstracts` `:135`, `blockAbstracts()` `:185-188`, throw `:167`; class list wired during module init (AGENTS.md RZ-005; 14-class inventory in `RAZY-ANALYSIS-REPORT.md` §5) |
| API-name collision | Duplicate `api_name` across vendors → `ModuleLoadException` naming both modules | `Distributor/ModuleRegistry.php:166-173` |
| Command grammar | `/^#?[a-z]\w*$/i` names; duplicate registration throws | `Agent.php:65-67`, `Module/CommandRegistry.php:61-63` |
| Template file contract | A closure file that doesn't return a `Closure` → `ModuleLoadException` | `Module/ClosureLoader.php:136-138` |
| Process hygiene | Child processes scrub dangerous env vars (see §7) | `ThreadManager.php:59-67, 667` |

Everything else in this page is **your responsibility by design** — and each section marks
the exact trap.

## 2. Encryption — `Razy\Crypt`

Two statics, that's the whole API (verified in `src/library/Razy/Crypt.php`):

```php
use Razy\Crypt;

$sealed  = Crypt::encrypt($plaintext, $key);            // Crypt.php:83
$sealed  = Crypt::encrypt($plaintext, $key, toHex: true);
$plain   = Crypt::decrypt($sealed, $key);               // Crypt.php:37
```

- AES-256-CBC **encrypt-then-MAC** (HMAC-SHA256), fresh random IV per call, MAC compared
  with `hash_equals` — verified in `RAZY-ANALYSIS-REPORT.md` §7.1 crypto review of this file.
- **Tampered/corrupt input returns `''`** — check for the empty string; it is the failure
  signal (audit-verified behaviour).
- **Keys are raw bytes, there is no KDF**: use `random_bytes(32)` (or better, a KDF you run
  yourself — `sodium_crypto_pwhist`/Argon2 via `sodium_*` — when deriving from passwords).
  The same key must be used for encrypt/decrypt; store it outside the repo, read via
  `env()` (`bootstrap.inc.php:294`).

```php
$key = hex2bin(env('APP_CRYPTO_KEY') ?? '');   // 32 random bytes, provisioned per install
if (strlen($key) !== 32) throw new RuntimeException('APP_CRYPTO_KEY missing');
$sealed = Crypt::encrypt(json_encode($tokenData), $key);
$data   = json_decode(Crypt::decrypt($sealed, $key) ?: '', true);   // '' = tampered/wrong key
```
*illustrative* composition; the two calls and the `''`-on-failure contract are verified.

## 3. 2FA — `Razy\Authenticator`

RFC-compliant TOTP/HOTP (audit: RFC 4226/6238, `hash_equals` constant-time compares at
`Authenticator.php:232/:277`, `random_bytes` secret generation `:95`). Static API (verified
signatures):

```php
use Razy\Authenticator;

$secret = Authenticator::generateSecret();                    // :89
$codes  = Authenticator::generateBackupCodes();               // :111 (8 codes × 8 chars default)
$otp    = Authenticator::getCode($secret);                    // :143 current TOTP
$ok     = Authenticator::verifyCode($otp, $secret);           // :205 (windowed compare)
$uri    = Authenticator::getProvisioningUri($secret, 'user@example.com', 'MyApp'); // :303
$qr     = Authenticator::getQrCodeDataUri($uri);              // :385
```

HOTP variants (`getHotpCode` `:170`, `verifyHotpCode` `:256`, `getHotpProvisioningUri`
`:338`) and base32 helpers (`:411/:445`) are present. Treat the secret as a credential:
encrypt it at rest (`Crypt`, above) or better — a dedicated KMS key.

## 4. CSRF — `Csrf/`

```php
use Razy\Csrf\CsrfMiddleware;
use Razy\Csrf\CsrfTokenManager;

$manager = new CsrfTokenManager($session);    // ctor: SessionInterface (CsrfTokenManager.php:61)
$mw      = new CsrfMiddleware(
    tokenManager:     $manager,          // required
    onMismatch:       null,              // ?Closure — default throws TokenMismatchException
    excludedRoutes:   ['webhooks/*'],    // array of exempt route patterns
    rotateOnSuccess:  true,              // rotate token after successful validation
    tokenExtractor:   null,              // custom (?string) extraction from context
);                                        // ctor verified: Csrf/CsrfMiddleware.php:81-87
```

Wire it like any middleware — globally (`Agent::middleware`, `Agent.php:387`) or per-group
(`group()->middleware`) ([03](03-routing-and-requests.md)). Semantics verified from the
class docblock: **safe methods (GET/HEAD/OPTIONS) pass through; POST/PUT/PATCH/DELETE
require a valid token** (default extraction: form field + header). Tokens are
`random_bytes`-sourced and compared with `hash_equals`
(`Csrf/CsrfTokenManager.php` — verified by audit read; mismatch →
`Csrf\TokenMismatchException`).

The wiring snippet above is *illustrative* (no shipped demo wires it); every constructor
argument is verified.

## 5. Sessions — `Session/`

`Session\SessionConfig` defaults (verified `Session/SessionConfig.php:40-49`):

| Setting | Default | Action |
|---|---|---|
| `httpOnly` | **true** | keep |
| `sameSite` | **'Lax'** | keep (or 'Strict' where UX allows) |
| `secure` | **false** | **flip to `true` on HTTPS-only sites** |
| `lifetime` | 0 (browser session) | set deliberately for your product |
| `path` / `domain` | `'/'` / `''` (host-only) | keep tight |
| GC | 1440s / 1/100 | tune under load |

```php
$session->start();                       // Session/Session.php:69
$session->regenerate();                  // :150 — REGENERATE ON LOGIN (fixation defence)
$session->destroy();                     // :113 — logout
```

Session ids are `bin2hex(random_bytes(20))` (`Session.php:326`, verified). The framework
exposes these classes; **how you obtain the instance in handlers is app wiring** — a common
pattern is one module owning session lifecycle and exposing `api()` commands, rather than
`$_SESSION` sprinkles (RZ-008: no shared statics across modules).

## 6. The bridge & API gates — the sharpest trap

Facts, each verified:

1. **Both permission gates default to ALLOW** — `__onAPICall` returns `true`
   (`Controller.php:173-176`), `__onBridgeCall` returns `true`
   (`Controller.php:187-190`). Every peer module in the distributor can call any
   `addAPICommand` you register until you gate it.
2. **Bridge transport is unauthenticated local IPC** (`php Razy.phar bridge <json_payload>`
   spawns into the target site, `bridge.inc.php:14` usage; transport is a local
   `proc_open`). Anyone who can execute the CLI on the host can invoke any
   ungated bridge command; source strings are **spoofable** — allow-list as identity
   hygiene, not as authentication. **Now hardened (unreleased):** `executeBridgeCommand`
enforces an HMAC-SHA256 envelope (`Razy\BridgeSignature`: canonical-binds source +
module + command + args + ts + nonce, ±60s window, `hash_equals`) whenever
`RAZY_BRIDGE_SECRET` is set — self-declared-source spoofing then fails verification.
The CLI `bridge` command stays local-IPC operator trust (shell access = code-exec trust).
3. Bridge HTTP/CLI execution does consult the gate before running
   (`Module/CommandRegistry.php:168-176` `executeBridgeCommand` → `__onBridgeCall`), so the
   only thing missing is your implementation.

Mandatory (RZ-002/RZ-008 in AGENTS.md "Known framework traps"):

```php
public function __onAPICall(ModuleInfo $module, string $method, string $fqdn = ''): bool
{
    // $module = the REQUESTING module. Allow-list, default-deny:
    return $module->getCode() === 'acme/consumer' && $method === 'getThing';
}                                            // signature verified Controller.php:173

public function __onBridgeCall(string $sourceDistributor, string $command): bool
{
    return ['clientb@1.0.0' => ['getSetting']]
        [$sourceDistributor] ?? [] and in_array($command,
        ['getSetting'], true);               // signature verified Controller.php:187
}
```

Also: never register an API/bridge command that returns raw secrets, DB credentials, or
another tenant's data — the gate protects invocation, not payload semantics.

### The ability layer — `Razy\Auth\*` (published surface; distinct vocabulary)

The word "permission" in this repo's *module* docs means the two gates above
(`__onAPICall`/`__onBridgeCall`): **module-to-module invocation control**. The
`Razy\Auth\*` namespace is a different axis — **what a logged-in actor may do**:

| Piece | Role |
|---|---|
| `Gate` | ability engine: `define`/`policy`/`allows`/`authorize`, **default-deny** for undefined abilities, single-slot `before()`/`after()` + appended `addBefore()`/`addAfter()` lists (lists compose across modules; first non-null `before` short-circuits) |
| `GateFactory` | one Gate per distributor, memoized by name; `flush()`/`forget()` for worker mode |
| `AuthManager` | named guards + default-guard delegation |
| `SessionGuard` | persists ONLY the actor's identifier under a session key and hydrates via an **app-provided resolver** — the framework never owns the user row (maintainer decision, `architecture/PERMISSION-MODULE.md` Q1). No session started (CLI) = request-lifetime storage, fail-closed to guest |
| `CallbackGuard`, `GenericUser`, `Hash`, `AccessDeniedException` | closure-delegating guard, array-backed actor, hashing helpers, typed denial |

**Stability (RZ-012, maintainer decision Q2):** this layer is a *published
surface* — additive-only from here; `razymod/permissions` and `razymod/oauth`
(their dossiers) are its first real consumers. Until those ship, the layer is
tested and stable but **unwired**: nothing in the framework core populates a
Gate — wiring is your app's/bootstrap's job (one `AuthManager` + guard per
distributor, `GateFactory::make($distCode, $auth, builder)`). CLI requests have
no session ⇒ `Gate` denies guests ⇒ CLI authorization needs an explicit
`forUser()` actor; that is fail-closed by construction, not a bug.

## 7. Threads — `ThreadManager` (via `$agent->thread()`, `Agent.php:304`)

| API | Line | Safety |
|---|---|---|
| `spawn(callable $fn, array $args = [])` | `:93` | **preferred** — code never leaves PHP as text |
| `spawnPHPFile(string $phpCode, ?string $phpPath = null, ...)` | `:168` | OK — despite the name the **first arg is code** (verified param `$phpCode`); it writes a randomized `0600` temp file and runs `php <file>` (no eval chain, audit-verified) |
| `spawnPHPCode(string $code, ...)` | `:141` | ⚠️ child runs `eval(base64_decode(...))` (`:152-153` verified) — **never with input-derived code** (RZ-011) |
| `spawnProcessCommand(...)` | `:228` | raw process launch — `escapeshellarg` everything |
| `await($id, ?int $timeoutMs)` / `joinAll` / `status` / `setMaxConcurrency` / `getThread` | `:262/:294/:314/:333/:345` | coordination |

Hygiene (verified `ThreadManager.php:59-67`, filter `:667`): child env is scrubbed of a
**block-list** — `LD_PRELOAD`, `DYLD_INSERT_LIBRARIES`, `PHPRC`, `PHP_INI_SCAN_DIR`, …
A block-list is not an allow-list: don't pass attacker-influenced env, and don't rely on
the scrub alone for hostile input (audit §process review). Threads are **processes**, not
sandboxing — same OS user, same filesystem. Tenant isolation by process boundary is
**[planned]** (Phase 1-5 in `RAZY-ANALYSIS-REPORT.md` §9; nothing wired today).

## 8. Dependency & supply-chain trust

- **Zero runtime Composer dependencies** (verified `composer.json`: only `php` + exts;
  Razy ships its own implementations) — the smallest attack surface in its class, but…
- `install`/`compose` pull third-party code; the extraction path's **zip-slip finding is
  now fixed** (`Razy\ArchiveSafety`: every entry name validated against `..`/absolute/
  wrapper forms *before* `extractTo`, symlinks purged post-extract, temp dirs randomised
  0600, plain-HTTP URLs rejected unless `RAZY_ALLOW_INSECURE_TRANSPORT=1`) — but the
  machine running `install`/`compose` is still code-exec-trusted: install only trusted
  repos, pin versions, diff `vendor/module/` (`[06 §3](06-packages-and-deployment.md)`).
- `autoload/` + `autoload/lock.json` are generated — hand edits are both a bug and a
  backdoor vector (RZ-007).
- Phar-packaged modules (`pack`) run with full module privileges: same trust discipline
  ([06 §4](06-packages-and-deployment.md)).

## 9. Hardening checklist (aggregated)

Framework-implemented (today): Crypt/2FA/CSRF/session-primitives/DI fence/command
grammar/env scrub (`:59-67`/`:667` verified). **App-level required:**

- [ ] `__onAPICall` default-deny on every module with sensitive commands (§6)
- [ ] `__onBridgeCall` allow-list on every bridge module (RZ-002) — default-allow is a trap
- [ ] Set `RAZY_BRIDGE_SECRET` (OS secret store) to activate HMAC enforcement on
      `executeBridgeCommand` — unsigned/self-declared-source calls are then denied
      (`Razy\BridgeSignature`; sender uses `signedPayload()`)
- [ ] Templates: every user value escaped (`->escape` built-in v1.0.3-beta+/DOM/`htmlspecialchars`) — RZ-004
      ([05 §4](05-templates.md))
- [ ] SQL: values only via `assign()`/ORM params; no `getSearchTextSyntax($userText)`;
      identifier whitelists — RZ-003 ([04 §7](04-database.md))
- [ ] `SessionConfig` `secure => true` on HTTPS; `regenerate()` on login; `destroy()` on logout (§5)
- [ ] Uploads validated, stored under `getDataPath()` only (RZ-006)
- [ ] Secrets via `env()` + OS-level secret store; never in `dist.php`/`package.php`
- [ ] Non-root deploy, HTTPS, real webserver; probe the shipped `/_razy/health`
      (`Razy\Health` — default tier leaks nothing; verbose/deep tiers need
      `RAZY_HEALTH_VERBOSE`/`RAZY_HEALTH_TOKEN`) ([06 §6 checklist](06-packages-and-deployment.md))
- [ ] `composer quality` + lint + `validate` green (RZ-014) before any release
- [ ] Audit findings now SHIPPED (this repo, unreleased→v1.0.3-beta): `escape` modifier,
      zip-slip/HTTPS extraction hardening (`Razy\ArchiveSafety`), bridge HMAC
      (`Razy\BridgeSignature`), health endpoint (`Razy\Health`), non-root Docker —
      remaining open items are the ⚠️ rows of the [readme Security Posture](../readme.md#security-posture-honest-edition)

## 10. Related documents

- [`SECURITY.md`](../SECURITY.md) — reporting process
- [`RAZY-ANALYSIS-REPORT.md`](../RAZY-ANALYSIS-REPORT.md) — full 2026 audit
  (isolation, security findings, benchmarks) — the source of every "audit-verified" claim above
- [`skills/RAZY-AI-RULES.md`](../skills/RAZY-AI-RULES.md) — RZ-001…RZ-014 + Appendix C escape reference (built-in since v1.0.3-beta)
- [`readme.md`](../readme.md) — Security Posture table (honest version, same findings listed)

Next: back to the [index](README.md).
