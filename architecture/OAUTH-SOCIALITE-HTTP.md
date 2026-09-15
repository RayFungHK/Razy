# OAUTH-SOCIALITE-HTTP — HTTP Groundwork for Socialite-Style Login

Research + gap analysis + decision on the HTTP layer and an OAuth 2.x login design.
Code beats docs: every local claim cites `path/File.php:line` actually read; every external
claim cites a primary-source URL actually fetched (failures named in §10/Sources).
Companion: `architecture/PORTING-VALUE.md:40` flagged "OAuth social login (Socialite) —
HTTP client primitive **NOT VERIFIED**"; `architecture/ROUTE-COEXISTENCE.md:433` repeats it as
still open. This dossier closes that probe. Generated 2026-09. Status: **complete**.

## 0. Headline correction (the premise was half wrong)

The research question assumes Razy has no HTTP client and no OAuth code. It has **three
disconnected islands** instead, and none of them is wired to anything:

| Existing asset | Size | Reality (verified) |
|---|---|---|
| `Razy\Http\HttpClient` (+ `HttpResponse`, `HttpException`, `RequestBody`) | 4 files / 32,583 B; `HttpClient.php` 811 lines | Fluent cURL wrapper with timeouts, `CURLOPT_PROTOCOLS`, redirect cap, JSON/form/multipart, Bearer + Basic — and **zero production callers**: `grep 'Razy\\Http\\' src/` hits only its own docblocks (`HttpClient.php:27-47`, `HttpResponse.php:24`) and `tests/HttpClientTest.php` (798 lines) |
| `Razy\OAuth2` | 436 lines | Authorization-code flow **already written** (`getAuthorizationUrl` `:251-272`, `getAccessToken` `:283-301`, `refreshAccessToken` `:312-329`, `verifyState` `:217-228`) — no PKCE, no persistence of `state`, body-param secrets only, its own private cURL (`:341-376`, `:398-435`), **no tests, no callers** |
| `Razy\Office365SSO extends OAuth2` | 441 lines (`:29`) | A real provider pack: Entra endpoints (`:71-72`), `login_hint` (`:129`), `id_token` claims + `iss` regex validation (`:286-305`), session array shape (`:315-337`), HTTPS-only cURL (`:235-236`, `:387-388`) — the closest in-repo prior art to a Socialite provider |
| `Razy\Auth\*` guard layer | `AuthManager` 219, `CallbackGuard` 137, `Gate` 412, `GenericUser` 97, `Hash` ~80, `Authenticator` (TOTP/HOTP) | Laravel-shaped guards exist (`Razy\Contract\GuardInterface:25-70`, `AuthenticatableInterface`) — tested (`tests/AuthTest.php` 1256 lines) but **never used by the runtime or any module**; no `login()`/`logout()` on the contract |

So the real question is not "what groundwork is missing" but: **(a) consolidate 31 hand-rolled
cURL sites onto the one client that already exists, (b) finish the half-built OAuth2 core to
2026 spec (PKCE/state/redirect-uri), (c) decide where the provider pack + user provisioning
live** — and the users/identity persistence gap (§5 G12) is the actual blocker, not HTTP.

## 1. Outbound HTTP inventory (A1)

`grep 'curl_init' src/` → **31 call sites in 11 files**; plus 2 `allow_url_fopen` stream paths
and 1 non-HTTP (`FTPClient`/`SFTPClient` use `ftp_*`/`ssh2_*`, `WebSocket\Client.php:88` opens a
raw socket — out of scope).

| # | Site | Purpose | Timeouts | `CURLOPT_PROTOCOLS` | Redirect cap | HTTPS gate |
|---|---|---|---|---|---|---|
| 1-10 | `src/system/terminal/publish.inc.php:62,102,133,174,204,246,276,325,371,409` | GitHub Contents API / tags / releases / asset upload (`githubGetTags :58-84`, `githubCreateTag :97-112`, `githubPutFile`, `githubCreateRelease`, `githubUploadReleaseAsset`) | **none** | **none** | **none** (`FOLLOWLOCATION` unset) | none (URLs are hardcoded `https://api.github.com` `:60,100`) |
| 11-17 | `src/library/Razy/RepoInstaller.php:176,220,264,313,508,597,653` | releases API, tags, HEAD checks, ZIP download to handle | **none** | yes `:180-181` etc. | `MAXREDIRS 5` `:182` | `isSecureUrl` on download only (`:625`, opt-out `:624`) |
| 18 | `src/library/Razy/RepositoryManager.php:585-609` | `index.json`/`manifest.json` fetch | **none** | **none** | `FOLLOWLOCATION` on, no cap | **yes** `isSecureUrl` `:590` |
| 19 | `src/system/terminal/install.inc.php:47-58` | `httpGet` helper (curl-less fallback `:48` `@file_get_contents`) | 15 s / 10 s `:54-55` | `HTTPS\|HTTP` `:56` | `MAXREDIRS 5` `:53` | yes `:44` |
| 20-21 | `install.inc.php:425-435`, `:599-609` | phar + dep download | 60 s `:432` | `HTTPS\|HTTP` `:429` | 5 `:431` | none at the curl (upstream only) |
| 22 | `src/system/terminal/pkg.inc.php:629-639` | standalone phar download | 60 s | `HTTPS\|HTTP` | 5 | none at the curl |
| 23 | `src/system/terminal/sync.inc.php:315-325` | pack update download | 60 s | `HTTPS\|HTTP` | 5 | none at the curl |
| 24 | `src/library/Razy/PackageManager/HttpTransport.php:83-114` | `download()` composer dist → `CURLOPT_FILE` | **none** | **none** | `FOLLOWLOCATION` on, no cap | none (`PackageManager.php:258` guards the *metadata* URL) |
| 25 | `HttpTransport.php:54-61` | `fetchMetadata()` via **stream wrapper** (`file_get_contents`, needs `allow_url_fopen`) | ctx `timeout 30` `:57` | n/a | n/a | none |
| 26 | `src/library/Razy/OAuth2.php:341-376` (`httpGet`), `:398-435` (`httpPost`) | OAuth user-API + token exchange | 30 s `:348,:405` | `HTTPS\|HTTP` `:346,:403` | **not set** (redirects not followed) | soft: `trigger_error` on non-HTTPS URL setters `:143-145,:163-165` |
| 27-28 | `src/library/Razy/Office365SSO.php:233-242`, `:385-398` | Graph GET/POST | 30 s | **`CURLPROTO_HTTPS` only** `:235-236,:387-388` | not set | yes (strictest in repo) |
| 29 | `src/library/Razy/SSE.php:138-178` | server-side SSE consumer (write-fn streaming) | optional `:152` | `HTTPS\|HTTP` `:142-143` | 5 `:144` | none |
| 30 | `src/library/Razy/Package/PackageRunner.php:809-827` | healthcheck `httpCheck` (stream wrapper) | ctx `timeout 5` `:814` | n/a | n/a | none |
| 31 | `src/library/Razy/Http/HttpClient.php:643-748` | the general client | 30 s / 10 s `:662-663` (defaults `:77,:82`) | `HTTP\|HTTPS` `:672-673` | 5 `:677` | **none** — `isSecureUrl` never called; `withoutVerifying()` is public `:257-262` |

**Hardening inconsistencies (the actual findings):**

- **Two policies exist and no client obeys either.** The S2-era pattern is
  `ArchiveSafety::isSecureUrl` (`src/library/Razy/ArchiveSafety.php:158-177`: HTTPS-only,
  plain-HTTP only with explicit opt-in, scheme-less = local) fronted for packs by
  `PackageVerifier::assertSecureUrl` + `insecureTransportAllowed()`
  (`src/library/Razy/PackageVerifier.php:160-167,:173-181`, env `RAZY_ALLOW_INSECURE_TRANSPORT`).
  It is honoured at only **6 sites** (`install.inc.php:44`, `RepoInstaller.php:625`,
  `PackageManager.php:258`, `PackageVerifier.php:164`, `RepositoryManager.php:590`) — the other
  ~25 call sites never see it, including every `publish.inc.php` helper and the OAuth path.
- **`publish.inc.php` is the worst-duplicated cluster**: 10 near-identical curl blocks, zero
  timeouts, zero protocol restriction, zero redirect cap, bearer token in a header built by
  string concatenation (`:64-68`).
- **Timeout asymmetry**: `RepoInstaller` has redirect caps but no timeout anywhere (7 sites);
  `RepositoryManager::httpGet` has an HTTPS gate but no timeout (`:596-606`) → a hung registry
  blocks `search`/`install`/`sync` indefinitely.
- **`CURLOPT_SSL_VERIFYPEER` is only explicitly set to `true`** on the 4 download sites
  (`install.inc.php:428,602`, `pkg.inc.php:632`, `sync.inc.php:318`) — i.e. verification is
  PHP-default everywhere else, and the one client that could centralise it exposes a public
  off-switch (`HttpClient.php:257`).
- **Duplication of intent**: `install.inc.php:47-58` vs `RepositoryManager.php:585-609` vs
  `RepoInstaller.php:176-184` vs `HttpClient::executeRequest` are four different "GET a URL"
  implementations; the OAuth pair (`OAuth2.php:341-435`) is a fifth.

## 2. Dependency posture and the phar constraint (A2)

- `composer.json:14-19` `require` = `php ^8.2`, `ext-zip`, `ext-curl`, `ext-json` — **zero
  third-party runtime deps**; `require-dev` `:20-24` = phpunit / php-cs-fixer / phpstan.
  `composer.lock`: `packages` = **0**, `packages-dev` = **62**. No Guzzle anywhere
  (`Get-ChildItem -Filter 'Guzzle*'` → empty). `vendor/psr/` holds only
  `container`, `event-dispatcher`, `log` — transitive dev-deps, never shipped.
- **House pattern for "standards" is in-house contracts, not PSR packages**:
  `src/library/Razy/Contract/Log/LoggerInterface.php`, `Contract/SimpleCache/PsrCacheInterface.php`,
  `Contract/Container/PsrContainerInterface.php`, `Contract/EventDispatcher/PsrEventDispatcherInterface.php`
  — PSR-shaped interfaces under `Razy\Contract\*`, zero dependency cost. That precedent is the
  strongest argument in this dossier.
- **The phar contains only `src/`**: `build.php:40` `$phar->buildFromDirectory(__DIR__ . '/src')`,
  GZ per-file compression `build.php:50`. Current `Razy.phar` = **744,287 bytes** vs
  `src/library/Razy` = 293 files / 2,073,020 B uncompressed (~2.8:1). **A root-composer
  `require` never reaches the shipped phar.**
- Third-party code reaches a project only through the **RZ-007 compose channel**: a module's
  `package.php` `require` → `php Razy.phar compose` → `PackageManager` extracts PSR-4 into
  `SYSTEM_ROOT/autoload/<dist>/…` and writes `autoload/lock.json`
  (`PackageManager.php:325-336`; observed lock shape `playground/autoload/lock.json` —
  `{dist: {vendor/pkg: {version, timestamp}}}`, **no digest field**; observed tree
  `playground/autoload/appdemo/{League,Nette,Symfony,Dflydev,Psr}/…`, transitive deps resolved
  automatically incl. `symfony/deprecation-contracts`, `symfony/polyfill-php80`).
- **Ordering hazard (decisive)**: framework classes resolve via the bootstrap SPL loader
  (`src/system/bootstrap.inc.php:59-70`: `SYSTEM_ROOT/library` → `PHAR_PATH/library`), while
  compose-installed libraries resolve only through the **distributor** autoloader
  (`Application.php:130-134` registers it after a domain match → `Distributor::autoload:212-214`
  → `Distributor\ModuleScanner::autoload:209-216,:270-271` → `SYSTEM_ROOT/autoload/<dist>`).
  **Framework-core code inside the phar therefore cannot depend on a compose-channel class at
  boot** — a Guzzle-typed HTTP client in `src/library/Razy/` would be `class not found` for any
  project that has not run `compose` for that distributor.
- Standalone packages have a separate, real Composer path (`Package\PackageRunner:93-145`
  `installPrerequisites()` → `./runtime/autoload/<pkg>/<ver>/vendor/` + `composer install`) —
  available to `razy.pkg.json` **prerequisites**, not to framework core (`PackageManifest.php:43`).

**Cost of "just add a dependency" here is therefore not bytes, it's architecture**: it either
bloats nothing (because it can't ship in the phar) or it makes framework core conditionally
broken. RZ-007 stays clean only while core stays dependency-free.

## 3. Adjacent primitives OAuth needs (A3)

| Primitive | Exists | Evidence | Gap for OAuth |
|---|---|---|---|
| **Session (driver-based)** | ✅ `Razy\Session\Session` 328 lines, 5 drivers (`Array/File/Database/Redis/Null`), flash data, `setId()` | `Session.php:32-108`; `Contract/SessionInterface.php:82` | **Emits no cookie and reads none**: `start()` generates a fresh id when empty (`:77-79`) and never touches `$_COOKIE`/`setcookie` (grep: zero hits across `Session/`); `SessionConfig`'s cookie fields (`:38-50`: `name/lifetime/path/domain/secure/httpOnly/sameSite`) are **decorative** — only read back via `getConfig()` `:286`, never applied. Cross-request state via this Session requires app-supplied ids. |
| **Session (native)** | ✅ `Distributor::setSession()` → `session_set_cookie_params([path,host-only,secure,httponly,Lax])` + `session_name($code)` + `session_start()` | `Distributor.php:251-268`, called from `matchRoute()` `:283`; same in `Standalone.php:305-318`; changelog `v1.0.3-beta.md:10` (`RELATIVE_COOKIE_PATH`) | `$_SESSION` **is** available on web routes with a correct cookie — but no framework code uses it (`grep '\$_SESSION' src/` → one docblock, `CallbackGuard.php:31`). |
| **CSRF / token compare** | ✅ `Csrf\CsrfTokenManager` (`hash_equals` `:104`, `random_bytes(TOKEN_BYTES)` `:163`) | stores in a `SessionInterface` → inherits the no-cookie problem; `razymod/queue-admin` documented exactly this and shipped a **double-submit cookie** helper instead (`modules/queue-admin/default/controller/support/csrf.php:5-13,:19-51`) | Precedent for "state that needs no session wiring" exists in-repo. |
| **CSPRNG / HMAC / timing-safe** | ✅ `random_bytes` 20+ sites; `hash_hmac('sha256', …)` + `hash_equals` + env-gated enable in `BridgeSignature.php:72,:93,:137`; Ed25519 sodium in `PackageSignature.php:79-124`; `Crypt.php:62-95` encrypt-then-MAC; `Auth\Auth\Hash` = `password_hash/verify` | cited | Everything a signed-state token or an `appsecret_proof` HMAC needs already exists; **no OAuth 1.0a HMAC-SHA1 signer** (grep `hash_hmac\('sha1'|oauth_` → 0). |
| **Cache (TTL store)** | ✅ `Cache.php:143` static `get/set`, File/Redis/Apcu/Null adapters | cited | Viable server-side store for `state`/`code_verifier` keyed by session id — still needs a cookie to bind the browser. |
| **Rate limiting** | ✅ `RateLimit\RateLimiter` + middleware (`RateLimiter.php:39` docblock literally uses `login:user@example.com`) | cited | Callback/authorize abuse guard is available, not yet pointed at auth routes. |
| **Routing** | ✅ `Agent::addRoute:330`, `addLazyRoute:279`, `group:419`; `Module::addRoute:694`, `addLazyRoute:966`; module-owned routes proven by `razymod/queue-admin` (`controller/queueadmin.php:29-33`, RZ-013 comment `:26-28`) | cited | Fine for `/oauth/authorize` + `/oauth/callback`. |
| **Redirect helper** | ⚠️ `Controller::goto()` issues `header('location: …', true, **301**)` + `RedirectException` (`Controller.php:313-318`); `RouteDispatcher.php:484` bare `Location` | cited | **Wrong verb for OAuth**: permanent caching of an authorize redirect is a real-world bug; OAuth 2.1 §1.6 permits any method **except 307** (fetched draft, §1.6). Needs a 302 path. |
| **Config (per module, per dist)** | ✅ `Controller::getModuleConfig()` → `Module::loadConfig()` → `SYSTEM_ROOT/config/<dist>/<ModuleClass>.php` (`Controller.php:292-295`, `Module.php:1052-1056`); site config scaffold `src/asset/setup/config.inc.php.tpl:1-7` | cited | No secret-store convention (client secrets would sit in a PHP config file) → Q5. |
| **Events (post-login hook)** | ✅ `Agent::listen:166` / `observe:200`, `Controller::trigger():339-342` (`EventEmitter::resolve()` = broadcast) | cited | Enough to publish `social.user_resolved`; the receiver (user store) does not exist. |
| **Users / identity persistence** | ❌ **none in framework** | `GuardInterface` has `check/guest/user/id/validate/setUser` (`:32-69`) and **no `login()`/`logout()`**; `AuthManager.php:31-32` docblock advertises `SessionGuard`/`TokenGuard` — **no such classes exist**; `Auth\GenericUser:31-60` is an in-memory array holder; zero `users` table/migration anywhere (`Database\Migration.php:36` uses `users` only in a docblock example); `grep 'AuthManager|GuardInterface|OAuth2' modules/` → **no matches** | This is the blocking adjacent gap (§5 G12): social login without an identity row to write to. |
| **Docs** | ❌ `manual/` has **zero** OAuth/`Razy\Http` mentions (grep) | while `readme.md:56` advertises "built-in … OAuth2" and `CLASS-CATALOG.md:72-73` lists both classes | Documented-but-absent is worse than absent for an AI-assisted repo. |

## 4. Where each piece belongs (A4)

AGENTS.md prime directive 2 ("modules are separate legal entities"; sanctioned surfaces =
`addAPICommand`+`api()`, events, bridge commands) plus RZ-001/006/008/013 give a clean split:

| Piece | Layer | Why |
|---|---|---|
| `Razy\Http\Client` (transport + hardening) | **framework** `src/library/Razy/Http/` | Cross-cutting infra; already lives there; must be callable from terminal code that runs before any distributor exists (§2 ordering hazard) |
| `Razy\Security\OAuth2` core (auth URL, state, PKCE, token exchange, error mapping, refresh) | **framework** | Security primitives (`random_bytes`, `hash_equals`, HMAC) are framework-resident everywhere else (`BridgeSignature`, `PackageSignature`, `CsrfTokenManager`); a module cannot be the security layer for other modules (RZ-008) |
| Provider pack (GitHub/Google/FB endpoints, param quirks, user mapping) | **framework, thin** (`Razy\Security\OAuth\Provider\*`) | Providers are protocol facts, not tenant policy; keeping them in core gives modules a stable semver surface (RZ-012) |
| `razymod/oauth`: client-id/secret config, provider enable/disable, **user mapping → identity store**, routes `/oauth/{provider}/authorize|callback`, post-login event trigger | **module** | Per-dist secrets and per-app identity mapping are distributor-local state (RZ-006/RZ-008); `razymod/queue-admin` proves the module shape works end-to-end (`module.php:12-17`, `queueadmin.php:24-46`, `:56-59`) |
| `OAuthException` | keep | exists (`src/library/Razy/Exception/OAuthException.php`, tested `tests/ExceptionTest.php:632-664`) |

## 5. Gap table

| # | Capability | Current state (evidence) | Required state |
|---|---|---|---|
| G1 | One HTTP client in production use | `Razy\Http\HttpClient` exists (811 lines) with **0 framework callers**; 31 hand-rolled `curl_init` in 11 files (§1) | Every outbound call routes through one client; `grep curl_init src/` = 1 file |
| G2 | Timeout policy | missing at 19 sites (all 10 `publish.inc.php`, all 7 `RepoInstaller`, `RepositoryManager.php:596-606`, `HttpTransport.php:90-108`) | mandatory connect+total timeout, no unset default |
| G3 | Transport policy centralised | `isSecureUrl` honoured at 6 of ~27 HTTP sites; `HttpClient` allows `CURLPROTO_HTTP` (`:672`) and public `withoutVerifying()` (`:257`); `OAuth2` warns instead of refusing (`:143-145`) | HTTPS-only inside the client (env opt-in parity with `PackageVerifier::insecureTransportAllowed():173-181`); no public verify-off switch for credential-bearing requests |
| G4 | PKCE | **absent** (`grep 'code_verifier|code_challenge|S256' src/` → 0 hits) | RFC 7636 S256 always on (RFC 9700 §2.1.1; GitHub supports **S256 only**) |
| G5 | `state` bound to the user agent | generated (`OAuth2.php:193`, 16 random bytes) but lives only on the instance (`:50`); caller must persist; the driver Session emits no cookie (§3) | one-time `state` (or signed state) bound to the browser, `hash_equals`-compared, single-use |
| G6 | OAuth 1.0a | absent (grep) | only if a 1.0a provider is ever required → S4, deferred (🔶) |
| G7 | Client auth flexibility | body-param `client_secret` only (`OAuth2.php:289-295`); `HttpClient::withBasicAuth:225` unused by OAuth | body params **and** RFC 6749 §2.3.1 Basic, per provider |
| G8 | Token-response tolerance | `OAuth2::httpPost` forces `json_decode` (`:429-432`) | parse by Content-Type with `application/x-www-form-urlencoded` fallback (GitHub's *default* body is urlencoded — official docs, §7) |
| G9 | Provider abstraction | one subclass (`Office365SSO:29`); no contract | 4-method provider contract + manager (Socialite's real surface: `getAuthUrl/getTokenUrl/getUserByToken/mapUserToObject`, 5.x source) |
| G10 | redirect_uri discipline | constructor string (`OAuth2.php:65-70`), never validated against the callback actually hit; no `iss` check outside `Office365SSO:300` | exact-match registered URI; reject `state`/`iss` mismatch before touching the code |
| G11 | `id_token` honesty | `parseJWT` decodes without verifying and says so (`OAuth2.php:87-88,:96-113`); `Office365SSO::validateIdToken` checks `aud`/`exp`/`iss` only (`:286-305`) | either verify signature (JWKs) or **never call it validation**; Google's own doc mandates signature + `iss`/`aud`/`exp`/`hd` checks (§7) |
| G12 | Identity persistence | no users table/store/concept; `GuardInterface` has no `login/logout`; no `SessionGuard`; zero module usage (§3) | decision first (Q1) — a social login that cannot persist "who" is a demo |
| G13 | Tests on the OAuth path | `tests/` has **zero** `Razy\OAuth2`/`Office365SSO` coverage (only `OAuthException`, `ExceptionTest.php:632-664`) — contradicting `changelog/v1.0.2-beta.md:192` | RZ-014: behavioural tests per published command, incl. RFC 7636 Appendix B vector |
| G14 | Docs | `manual/` = 0 OAuth mentions; `tests/Razy-Feature-TestCases.md:4392-4398` documents an API that does not exist (`new OAuth2()` + array arg) | manual section + correct signatures at ship time |
| G15 | Correct redirect verb | `Controller::goto` = 301 (`:313-318`) | 302 for authorize hand-off (307 explicitly disallowed by OAuth 2.1 §1.6) |

## 6. Decision: internal client vs Guzzle/PSR-18 vs a slim package

What a "Socialite-equivalent" actually costs, measured against the real library
(fetched, branch `5.x` — `develop`/`1.x` are 404):

| Socialite 5.x fact | Value | Source |
|---|---|---|
| OAuth2 base class | `src/Two/AbstractProvider.php`, 13,533 B (~530 lines approx) | repo tree API + raw fetch |
| Whole `src/` | 33 files, 82,863 B; `src/Two/` 17 files 54,743 B; `src/One/` 5 files 7,785 B | `api.github.com/repos/laravel/socialite/git/trees/5.x?recursive=1` |
| Methods a provider must implement | exactly **4** (`getAuthUrl($state)`, `getTokenUrl()`, `getUserByToken($token)`, `mapUserToObject(array $user)`) | raw `src/Two/AbstractProvider.php` |
| State | `Str::random(40)` → `$this->request->session()->put('state', …)`; check = `session()->pull('state')` + `hash_equals` (`hasInvalidState()`) | same file |
| PKCE | opt-in (`enablePKCE()`), `getCodeVerifier() = Str::random(96)`, `getCodeChallenge() = rtrim(strtr(base64_encode(hash('sha256',$v,true)),'+/','-_'),'=')`, method `S256`; `code_verifier` pulled from session on token POST | same file |
| HTTP | `use GuzzleHttp\Client; … $this->httpClient = new Client($this->guzzle);` and `setHttpClient(Client $client)` — **hard Guzzle type, no PSR-18 `ClientInterface` anywhere** | same file |
| Runtime deps (`require`) | `php ^8.1`, `ext-json`, `firebase/php-jwt`, **`guzzlehttp/guzzle ^6|^7|^8`**, `illuminate/{contracts,http,support}`, **`league/oauth1-client ^1.11`**, `phpseclib/phpseclib ^4.0` | raw `composer.json` |
| OAuth 1 | thin delegate to `league/oauth1-client` (`use League\OAuth1\Client\Server\Server;`) | raw `src/One/AbstractProvider.php` |
| `psr/*` | **none** | same `composer.json` |

Conclusion: the *abstraction* is small (4 methods, ~530 lines); the *dependency graph* is the
expensive part — Socialite cannot be reused here at all because it is welded to Guzzle **and**
`Illuminate\Http\Request`/session, both of which Razy deliberately does not have.

| | (i) Internal `Razy\Http\Client` | (ii) Guzzle + PSR-18 | (iii) Slim PSR-18 package |
|---|---|---|---|
| What | Harden existing `HttpClient` (+`HttpResponse` 307 lines, `HttpException` 53, `RequestBody` 28) and make it the single door; add in-house `Razy\Http\ClientInterface` | `guzzlehttp/guzzle` in the HTTP layer | e.g. `php-http/curl-client` / vendored `psr/http-client` |
| LOC to write | ~150–250 (HTTPS gate, mandatory timeouts, Content-Type-aware parse, Basic-auth for token endpoints, `isSecureUrl` call) + migrate 31 sites | ~200 adapter code, **plus** learning/config surface | ~200 adapter code |
| Phar size delta | +3–6 KB compressed against **744,287 B** today (`build.php:40` only packs `src/`, `:50` GZ) | **impossible in the phar** — `buildFromDirectory('src')` never sees `vendor/`; would have to live in `SYSTEM_ROOT/autoload/<dist>/` (compose channel) with the boot-ordering break of §2 | same non-shippability; contracts alone = 17 files / ~54 KB uncompressed, zero logic (`psr/http-client` 4 files 1,915 B, `psr/http-message` 7 files 47,392 B, `psr/http-factory` 6 files 4,843 B) |
| Transitive closure | 0 | **9 packages** (guzzle, psr7, promises, psr/http-client, psr/http-message, psr/http-factory, symfony/deprecation-contracts, symfony/polyfill-php80, ralouphie/getallheaders) — and the compose lock already shows it resolves transitives (`playground/autoload/lock.json`) | `php-http/curl-client` is *heavier* than Guzzle: `ext-curl`, `php-http/discovery ^1.6`, `php-http/httplug ^2.0`, `php-http/message ^1.2`, `psr/http-factory-implementation`, `symfony/options-resolver` |
| Supply chain (project ships phars directly, `composer.lock` `packages: 0`) | unchanged — 0 | new attack surface on every install; Guzzle lists 15 advisories on Packagist; `allow_url_fopen`-free but cURL-optional → two TLS code paths | same, plus a thinner maintainer |
| RZ-007 | untouched | framework core becomes dependent on a per-distributor `compose` run (violates the spirit of "core ships in the phar") | same |
| PSR interop | lossy — provide an optional **module-owned** PSR-18 adapter if a user needs one | native | native |
| Honest downside | bespoke surface to maintain; no middleware ecosystem; every quirk is our bug | version conflicts with userland Guzzle in the same project are *guaranteed* eventually | worst of both: dependency cost without Guzzle's maturity |

**Recommendation: (i), specifically (i′) — harden `Razy\Http\HttpClient`, add an in-house
`Razy\Http\ClientInterface` (mirroring the `Razy\Contract\*` precedent in §2), and keep PSR-7/17/18
strictly out of the phar.** Reasons: (1) the phar/compose split makes external HTTP libs
structurally unusable from framework core, not merely heavy; (2) the S2-era single-door pattern
(`ArchiveSafety` → `PackageVerifier::assertSecureUrl` → 6 call sites) is already the house answer
for "one place hardening lives" — G1/G2/G3 close only if the same trick is applied to *all*
outbound HTTP; (3) `require: 0` is a marketable property of this project
(`manual/01-getting-started.md:14` states it) and one dependency spends it permanently; (4) the
OAuth work needs ~4 client features (form-encoded POST, Accept control, Basic auth, non-JSON
response parse), all of which `HttpClient` is within ~50 lines of already (`:287-302` formats,
`:225-230` Basic, `:780-782` default Accept, `:740-748` response path).

## 7. OAuth flow design sketch for Razy

```
[razymod/oauth]  /<alias>/authorize/{provider}      (addLazyRoute, queue-admin pattern :29-33)
   └─ framework: Razy\Security\OAuth2->redirect($provider, $config)
        1. code_verifier = base64url(random_bytes(32))            # 43-char, RFC 7636 §4.1
        2. state = one-time token, HMAC-signed, bound to UA       # see below
        3. Location: 302 to authorize URL with code_challenge(S256)+state
           (NOT Controller::goto — that is 301, Controller.php:313-318)

[browser ⇄ provider consent]

[razymod/oauth]  /<alias>/callback/{provider}?code=…&state=…      ($_GET read is sanctioned;
                                                                   queue-admin.act.php:43-48 pattern)
   └─ framework: Razy\Security\OAuth2->consume($request, $config)
        4. state: single-use compare with hash_equals (BridgeSignature.php:93 pattern),
           reject before any network call; map provider error → OAuthException (RFC 6749 §5.2)
        5. exact-match redirect_uri (the one registered) — never derived from the request
        6. POST token endpoint: form-encoded, Accept: application/json,
           code_verifier, client auth = body params (GitHub/FB) or Basic (generic RFC servers)
        7. parse by Content-Type (json | urlencoded)                 # GitHub default body is urlencoded
        8. user-info call with Authorization: Bearer → mapUserToObject (4-method provider)
        9. id_token: decode + aud/exp/iss(/nonce/hd) checks; if unverifiable → say "unverified",
           never "validated" (§5 G11)
  10. $this->trigger('social.user_resolved')->resolve($payload)      # Controller::trigger:339-342
      → module/app writes or looks up its own identity row (Q1)
```

**Where state + verifier live** (the real design fork, because §3 proved the driver Session has
no cookie):

| Option | Mechanism | Verdict |
|---|---|---|
| A. native `$_SESSION` | available on web routes (`Distributor.php:251-268`, `session_start()` at `:264`, cookie host-only/Lax/httponly) | ✅ best UX **when** the request went through `matchRoute()`; but a CLI/worker-path or a module that bypasses it breaks silently |
| B. driver `SessionInterface` | `CsrfTokenManager`'s approach | ❌ unusable as-is: no cookie, fresh id per request (`Session.php:77-79`) — same trap `razymod/queue-admin` documented and worked around (`support/csrf.php:5-13`) |
| C. **signed state (recommended default)** | `state = base64url(payload) \|\| HMAC-SHA256(payload, server secret)` where payload = `{nonce, provider, redirect_uri_hash, ts}`; verify with `hash_equals`, keep the used-nonce set in `Razy\Cache` for its TTL | ✅ stateless-capable (Socialite's own `stateless()` escape hatch exists because SPA/API flows have no session), mirrors `BridgeSignature`'s env-gated HMAC + constant-time compare (`BridgeSignature.php:32-45,:72,:93`); RFC 9700 §4.7.1 explicitly accepts "signing state values" as the protection against tampering/swapping |
| D. double-submit cookie, queue-admin style | `setcookie` + header compare (`support/csrf.php:19-51`) | 🔶 acceptable for an admin shell, weaker than C for a login entry point |

Recommend **C with A as an opportunistic fast path**; document that a `SameSite=Lax` host-only
cookie plus `hash_equals` is the floor. User provisioning stays **out** of the framework (Q1).

## 8. Milestones

Sizing: XS ≤ ½ d, S ≤ 2 d, M ≤ 5 d. Every step is RZ-007-clean (no `autoload/` edits),
RZ-012-clean (additive signatures / new classes only), and RZ-014-clean (tests ship with code).

| Step | Change | Files / classes | Size + tests |
|---|---|---|---|
| **S0 Decide + label (day 0)** — ✅ **SHIPPED 2026-09-17** (same-day sign-off: Q1–Q5 all per recommendation; ADR-1 in `PORTING-VALUE.md` (which also closes its own stale Tier-2 'NOT VERIFIED' row + pre-flight #2 'still open' row); CLASS-CATALOG labels applied; RZ-015 in rules-doc + AGENTS table) | Adopt this decision as an ADR line in `PORTING-VALUE.md`; mark `OAuth2`/`Office365SSO` "unwired, untested" in `CLASS-CATALOG.md:72-73`; no API change | `architecture/*.md`, `CLASS-CATALOG.md` | XS · 0 tests |
| **S1 One door (HTTP)** | Harden `HttpClient`: HTTPS-only default via `isSecureUrl` + `RAZY_ALLOW_INSECURE_TRANSPORT` parity (`PackageVerifier.php:173-181`), mandatory timeouts, `MAXREDIRS 3`, Content-Type-aware body parse (JSON **and** urlencoded), `Accept` control, `withBasicAuth` usable on token calls, `redirect(int $status)` helper (302); new `Razy\Http\ClientInterface` + `HttpTransportException`; **migrate** `publish.inc.php` (10 sites), `RepositoryManager.php:585-609`, `RepoInstaller` (7), `HttpTransport.php:83-114`, `OAuth2.php:341-435`, `Office365SSO` (2), `install/pkg/sync` downloads | `src/library/Razy/Http/{HttpClient,HttpResponse,ClientInterface,HttpTransportException}.php`, `src/system/terminal/publish.inc.php`, `src/library/Razy/{RepositoryManager,RepoInstaller,OAuth2,Office365SSO}.php`, `src/library/Razy/PackageManager/HttpTransport.php` | **M (~4–5 d)** · extend `tests/HttpClientTest.php` (798 lines today, option-assertion style `:686`) + `tests/HttpTransportPolicyTest.php` for the URL gate; refactor is behaviour-preserving so existing 147 test files are the regression net <br>✅ **SHIPPED 2026-09** (pending one S2-residual): hardening core + `ClientInterface` + `HttpTransportException` (12 tests `HttpClientHardeningTest`); EVERY migrated file pinned by `S1CallerMigrationTest` — `publish.inc.php` ×10, `RepoInstaller` ×7, `RepositoryManager`, `HttpTransport`, plus `install` (helper + 2 downloads, curl-less stream fallback preserved by design), `pkg`, `sync`. Client gained `raw_body`/`sink`/`progress` to make migration lossless. Residual: `OAuth2`/`Office365SSO`'s five cURL sites die with their S2/S3 rewrites (Q3 — they get replaced, not migrated); `SSE` out of scope (long-lived connections, absent from the migration list too) |
| **S2 OAuth2 core** | New `Razy\Security\OAuth2` (rewrite in place or new namespace) on `HttpClient`: PKCE S256 always-on, state gen+bind (option C, A fast-path), single-use nonce via `Cache`, `redirect_uri` exact-match, RFC 6749 §5.2 error mapping, refresh, provider contract (4 methods) + registry | `src/library/Razy/Security/OAuth/{OAuth2,ProviderInterface,StateSigner,TokenResponse,Provider/*}.php` (names TBD), `Exception/OAuthException.php` reuse | **M (~5 d)** · `tests/OAuth2Test.php`: RFC 7636 **Appendix B vector** (`dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk` → `E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM`), verifier length 43–128, `plain` never offered, state mismatch + replay, urlencoded token body (GitHub shape), Basic-vs-body auth, error JSON → exception; **no network** — inject the client (the reason `ClientInterface` exists) <br>✅ **SHIPPED 2026-09** — naming/finality calls: files as sketched plus `OAuthConfig` + `ProviderRegistry`; test file landed as `OAuth2CoreTest.php` (28 tests, zero sockets). Deviations, disclosed: (1) the contract is **5** methods — `authorizeParams` exists because Google/FB quirks attach to the authorize request; (2) the A (`$_SESSION`) fast path is deliberately **left to the module layer** — core implements C-only rather than branching on session plumbing the dossier itself proved unreliable; (3) PKCE verifier custody rides the same Cache nonce record (state carries only the nonce — the verifier never touches the browser, only its S256 challenge). Legacy `Razy\OAuth2` heart-swap landed here (Q3): cURL sites die, urlencoded token bodies fixed; the §48 phantom constructor in `tests/Razy-Feature-TestCases.md` corrected in the same commit. The zero-network tests caught a real core bug pre-ship (`base64_encode` mis-arity in Basic auth) — the seam earning its keep on day one |
| **S3 Provider pack: GitHub + Google** | `GithubProvider` (S256-only, `Accept: application/json`, `api.github.com/user`, scopes `read:user user:email`), `GoogleProvider` (`accounts.google.com/o/oauth2/v2/auth`, `oauth2.googleapis.com/token`, `access_type=offline`, `prompt`, `hd`, claims via `sub` **not** email); `Office365SSO` re-expressed on the new core, deprecated-but-supported | `src/library/Razy/Security/OAuth/Provider/{GithubProvider,GoogleProvider}.php`, `Office365SSO.php` | S–M (~3 d) · fixture-driven tests per provider (request-shape assertions + `sub`/`aud`/`exp`/`iss` cases) <br>✅ **SHIPPED 2026-09** — plus a third provider the re-expression demanded (`MicrosoftProvider`, tenant-aware, Graph object-id identity), `OAuth2::verifyIdTokenClaims` (structure-checked, signature-NEVER — the G11 honest label enforced in code), and the legacy SSO's last two cURL sites died: framework-wide hand-rolled cURL now exists only in `HttpClient` + the excluded `SSE` streamer. 19 fixture tests `OAuthProvidersTest`, zero sockets; Google answered via `openidconnect.googleapis.com/v1/userinfo` (the OIDC-published fixed endpoint — discovery itself stays on the Do-NOT-build list) |
| **S4 OAuth 1.0a — only if demanded** | minimal HMAC-SHA1 signer (base string per RFC 5849 §3.4.1, `%20` encoding §3.6, `Authorization: OAuth …` §3.5.1) | `src/library/Razy/Security/OAuth1/Signer.php` | S (~2 d) · RFC example vectors + `tests/OAuth1SignerTest.php`. 🔶 **deferred by default** — no 1.0a provider is on this project's radar; do not pre-build it |
| **S5 `razymod/oauth` module** | routes + per-dist config + provisioning event + docs; ships as a pack (`module.php` `module_code` `razymod/oauth`, semver RZ-012) | `modules/oauth/{module.php,default/package.php,default/controller/*.php,default/controller/support/*.php}`, `manual/09-social-login.md` | M (~4 d) · module lint clean (`tools/lint-module-discipline.php`), `phpstan.neon` no suppression, implemented `__onAPICall` allow-list (queue-admin shape `:56-59`) <br>✅ **SHIPPED 2026-09** — lint 0/0 (3 justified `lint-allow: RZ-003`), modules-phpstan extended to cover it, allow-list publishes exactly one read-only command; Q1 enforced as SCHEMA (`package.php` without any migration key) and the env-reference discipline (Q5) is pinned by test, not folklore. `manual/09-social-login.md` carries the event contract and the G11 honest-label law |

**Do NOT build** (explicit scope-kill list): full OIDC RP (discovery + JWK fetch/cache/verify) —
decode-only with a loud "unverified" label instead; device-flow; DPoP / mTLS sender-constrained
tokens; token introspection/revocation UIs; refresh-token vaults; "remember me"; social graph
APIs (anything beyond *read profile*); multi-issuer mix-up machinery beyond a single stored
`iss`; async/HTTP2 client; PSR-7/17/18 vendoring; OAuth 1.0a before a real provider needs it;
a framework users-table (Q1 — app/module territory).

## 9. Open questions for the maintainer

> **SIGN-OFF 2026-09-17:** the maintainer chose **every question per recommendation**
> (banners below), and **authorised the build scope S0–S3 + S5** (S4 OAuth 1.0a stays
> deferred per §8's own rule — no named provider). RZ-015 is law from this date.

- **Q1 — Who owns the user row?** Nothing in `src/` or `modules/` persists an identity
  (`GuardInterface:32-69` has no `login/logout`; no `users` migration anywhere).
  *Recommendation*: framework ships the guard seam + fires `social.user_resolved`
  (`Controller::trigger:339-342`); the identity table lives in the app or a future
  `razymod/accounts`. Do **not** add a `users` table to core — it collides with RZ-008 and with
  every host app's schema. (This is the same queue the permission/`Gate` layer sits in —
  `Auth/Gate.php` exists with no identity to authorize.)
  → **DECIDED 2026-09-17: per recommendation** — guard seam + `social.user_resolved`
  event only; no `users` table in core, ever (same doctrine as PERMISSION-MODULE Q1).
- **Q2 — State binding default**: signed stateless `state` (option C) as default with `$_SESSION`
  fast path, or require apps to install session-cookie wiring first?
  *Recommendation*: signed state as default; it is the only option that works for a module that
  cannot assume cookie plumbing — the exact reason `queue-admin` shipped double-submit
  (`support/csrf.php:5-13`).
  → **DECIDED 2026-09-17: per recommendation** — signed stateless state is the default;
  `$_SESSION` is an optional fast path, never a prerequisite.
- **Q3 — Fate of `Razy\OAuth2` / `Office365SSO`**: they are advertised (`readme.md:56`,
  `CLASS-CATALOG.md:72-73`) but untested and unwired, and `tests/Razy-Feature-TestCases.md:4392-4398`
  documents a constructor that does not exist.
  *Recommendation*: keep both class names (RZ-012 additive), reimplement internals on the client,
  add S2's tests, and correct the feature-test doc in the same commit.
  → **DECIDED 2026-09-17: per recommendation** — names survive (RZ-012), internals are
  replaced; the `tests/Razy-Feature-TestCases.md:4392-4398` phantom constructor is
  corrected in the S2 commit that makes the truth possible (CLASS-CATALOG labels
  already applied in S0).
- **Q4 — Formal dependency policy**: codify "framework core = zero third-party runtime deps,
  forever; PSR-18/7 interop lives in modules or standalone packages (`PackageRunner:93-145`)".
  *Recommendation*: yes, one paragraph in `skills/RAZY-AI-RULES.md` as a new rule ID — the
  absence of this rule is what makes "just add Guzzle" look cheap.
  → **DECIDED 2026-09-17: SHIPPED as RZ-015** (RAZY-AI-RULES section + AGENTS.md short
  table; human rule — the module lint tool has nothing core-scoped to scan there).
- **Q5 — HTTPS strictness + secret storage**: default-deny `http://` in the new client would
  break local/plain-HTTP mirrors; and `client_secret` would sit in
  `SYSTEM_ROOT/config/<dist>/<Module>.php` (`Module.php:1052-1056`) with no secret convention.
  *Recommendation*: reuse `RAZY_ALLOW_INSECURE_TRANSPORT=1` as the *only* escape hatch (parity,
  one env var) and read provider secrets from env with the config file holding a reference, not
  the literal secret — matching the `RAZY_BRIDGE_SECRET`/`RAZY_REGISTRY_*` precedent
  (`BridgeSignature.php:32-45`).
  → **DECIDED 2026-09-17: per recommendation** — `RAZY_ALLOW_INSECURE_TRANSPORT=1` is
  the single escape hatch; provider secrets live in env, config holds references only.

## 10. Doc-drift ledger (code wins)

| # | Doc/comment claim | Code reality (winner) |
|---|---|---|
| D1 | `changelog/v1.0.2-beta.md:192` — "behavioural tests for … `OAuth2`" | zero `OAuth2`/`Office365SSO` tests exist; only `OAuthException` is covered (`tests/ExceptionTest.php:632-664`) |
| D2 | `AuthManager.php:31-32` registers `SessionGuard` / `TokenGuard` | neither class exists anywhere in `src/` |
| D3 | `tests/Razy-Feature-TestCases.md:4392-4398` — `new OAuth2()` then `getAuthorizationUrl([...])` | ctor requires 3 strings (`OAuth2.php:65-70`); `getAuthorizationUrl()` takes no args (`:251`) |
| D4 | `readme.md:56` "built-in … OAuth2" | no OAuth documentation in `manual/` (grep = 0 hits); zero runtime callers |
| D5 | `SessionConfig.php:26-50` documents cookie name/path/domain/sameSite | nothing applies them; only `Distributor::setSession():254-262` sets cookie params, hardcoded, for the *native* session |
| D6 | `architecture/OFFICIAL-REPO-INSTALL.md:116,332` — `RepositoryManager::httpGet` "`:452-467`, no transport guard / allows `CURLPROTO_HTTP`" | since the audit it gained an `isSecureUrl` gate at **`:590`** (file now 623 lines — line refs drifted); timeout is still missing |
| D7 | `architecture/PORTING-VALUE.md:40,69` — HTTP client "NOT VERIFIED … absence makes this XL" | client **exists** but is unwired ⇒ real work is M, not XL; closes the probe |
| D8 | `CLASS-CATALOG.md:72-73` lists both OAuth classes with no caveat | no tests, no callers, no PKCE, no docs (S0 fixes) |

---

## Sources

### Local (`file:line` read in this pass)

- HTTP inventory: `src/system/terminal/publish.inc.php:58-124` (+`curl_init` at `:62,102,133,174,204,246,276,325,371,409`);
  `src/library/Razy/RepoInstaller.php:176-184,220-228,264-272,313-321,508-516,597-605,653-669,:624-625`;
  `src/library/Razy/RepositoryManager.php:585-609`; `src/system/terminal/install.inc.php:44-58,425-435,599-609`;
  `src/system/terminal/pkg.inc.php:629-639`; `src/system/terminal/sync.inc.php:315-325`;
  `src/library/Razy/PackageManager/HttpTransport.php:49-114`; `src/library/Razy/SSE.php:138-178`;
  `src/library/Razy/Package/PackageRunner.php:809-827`.
- Client + transport policy: `src/library/Razy/Http/HttpClient.php:60-170,255-302,332-355,617-748,800-809`;
  `Http/HttpResponse.php:34-109`; `Http/RequestBody.php:10-27`; `Http/HttpException.php` (53 lines);
  `src/library/Razy/ArchiveSafety.php:137-177`; `src/library/Razy/PackageVerifier.php:140-181`.
- OAuth/SSO today: `src/library/Razy/OAuth2.php:29-113,122-129,138-169,191-228,251-301,312-329,341-376,398-435`;
  `src/library/Razy/Office365SSO.php:29,71-72,105-129,233-242,264-337,347-369,385-398`;
  `src/library/Razy/Exception/OAuthException.php` via `tests/ExceptionTest.php:31,60,632-664`.
- Adjacent: `src/library/Razy/Session/Session.php:32-108,286`; `Session/SessionConfig.php:17-64`;
  `Session/SessionMiddleware.php:34-57`; `Distributor.php:251-268,283`; `Standalone.php:305-318`;
  `Csrf/CsrfTokenManager.php:89,104,163`; `BridgeSignature.php:32-45,72,93,137`;
  `PackageSignature.php:39,79-124`; `Crypt.php:62-95`; `Cache.php:143`;
  `RateLimit/RateLimiter.php:39`; `Auth/AuthManager.php:21-130`; `Auth/CallbackGuard.php:21-137`;
  `Contract/GuardInterface.php:25-70`; `Contract/AuthenticatableInterface.php:21-42`;
  `Controller.php:282-342`; `Module.php:47,581,600,694,966,1052-1056`;
  `Agent.php:55,166,200,279,330,419`; `modules/queue-admin/module.php:12-17`;
  `modules/queue-admin/default/controller/queueadmin.php:24-59`;
  `.../controller/queueadmin.act.php:14-58`; `.../controller/support/csrf.php:1-52`.
- Distribution/deps: `composer.json:14-34`; `composer.lock` (`packages: 0`, `packages-dev: 62`);
  `build.php:20-50`; `Razy.phar` = 744,287 B; `src/system/bootstrap.inc.php:59-70`;
  `src/library/Razy/Application.php:130-134,164-166`; `Distributor.php:212-214`;
  `Distributor/ModuleScanner.php:209-216,270-271`; `PackageManager.php:147,205,258,325-336`;
  `playground/autoload/lock.json` (+tree); `Package/PackageRunner.php:93-145`;
  `changelog/v1.0.3-beta.md:10`, `changelog/v1.0.2-beta.md:39,161,192`, `changelog/v0.5.4.md:91-92`;
  `manual/01-getting-started.md:12-14,68,106`; `readme.md:56`; `CLASS-CATALOG.md:72-73`;
  `tests/HttpClientTest.php:371-760`; `tests/Razy-Feature-TestCases.md:4385-4459`.

### External — fetched primary sources

**Socialite** (branch `develop`/`1.x` → 404; `5.x` is the default per API):
`https://api.github.com/repos/laravel/socialite` ·
`https://api.github.com/repos/laravel/socialite/git/trees/5.x?recursive=1` ·
`https://raw.githubusercontent.com/laravel/socialite/5.x/composer.json` ·
`https://raw.githubusercontent.com/laravel/socialite/5.x/src/Two/AbstractProvider.php` ·
`src/One/AbstractProvider.php` · `src/SocialiteManager.php` (all OK).
⚠️ `src/AbstractProvider.php` and `src/Contracts/StateStore.php` do **not** exist in 5.x (404).

**Specs**: `https://www.rfc-editor.org/rfc/rfc6749.txt` ✅ (§1–§5.2 head; ⚠️ §5.2 JSON example +
§10.12/§10.16 truncated) · `https://www.rfc-editor.org/rfc/rfc7636.txt` ✅ complete incl.
Appendix B vector · `https://www.rfc-editor.org/rfc/rfc5849.txt` ✅ complete ·
`https://www.rfc-editor.org/rfc/rfc9700.txt` ✅ (§1–§2, §4.1–§4.14; truncated at §4.15) ·
`https://datatracker.ietf.org/doc/draft-ietf-oauth-v2-1/` ✅ (**draft-ietf-oauth-v2-1-16**,
last updated 2026-09-02, WG milestone "Dec 2026 — Submit to IESG", IESG state *I-D Exists*;
⚠️ body truncated at §2.3.1 — the fetched range covers §1.5/§1.6/§1.8/§2.3.1 but **not** §10.1).
⚠️ Subagent attempts on `draft-ietf-oauth-v2-1-13.txt/.html` failed (proxy 403); only `-16`
above is primary-verified.

**Providers**: GitHub — `https://docs.github.com/en/apps/oauth-apps/building-oauth-apps/authorizing-oauth-apps`
(live, nav-truncated) + canonical `https://raw.githubusercontent.com/github/docs/main/content/apps/oauth-apps/building-oauth-apps/authorizing-oauth-apps.md`
and `…/scopes-for-oauth-apps.md` ✅; ⚠️ `_data/reusables/apps/oauth-auth-vary-response.md` and
`state_description.md` → 404. Google — `https://developers.google.com/identity/protocols/oauth2/web-server`,
`…/oauth2/native-app`, `…/oauth2/limited-input-device`,
`https://developers.google.com/identity/openid-connect/openid-connect`,
`https://developers.google.com/identity/gsi/web/guides/verify-google-id-token` ✅ (truncation noted;
PKCE quote taken from the native-app page). Facebook — `developers.facebook.com` live pages are
404 or JS-empty; verified via Internet Archive captures of the official pages:
`web.archive.org/web/20210902171408/…/facebook-login/manually-build-a-login-flow/`,
`/20210813194704/…/facebook-login/security`,
`/20200903061344id_/…/graph-api/securing-requests/` (appsecret_proof),
`/20210526011024/` + `/20191121171325id_/…/facebook-login/access-tokens` ✅;
⚠️ the literal `grant_type=fb_exchange_token` snippet could not be fetched.

**PSR / Guzzle**: `https://www.php-fig.org/psr/psr-18/` + `/psr/18/meta/`, `/psr/psr-7/` (+meta;
body truncated), `/psr/psr-17/` (+meta), `/psr/psr-3/` ✅ · GitHub trees for
`php-fig/http-client`, `php-fig/http-message`, `php-fig/http-factory` ✅ ·
raw `composer.json` for `guzzlehttp/guzzle@7.15.5`, `guzzlehttp/psr7@2.13.1`,
`guzzlehttp/promises@2.5.3`, `php-http/curl-client@master` ✅ ·
`https://packagist.org/packages/psr/http-client`, `/psr/http-message`, `/guzzlehttp/guzzle`,
`/guzzlehttp/psr7`, `https://packagist.org/providers/psr/http-client-implementation` ✅ ·
`https://docs.guzzlephp.org/en/stable/` + `/overview.html` + `/psr7.html` ✅.

**Status: complete.** Local claims verified at the cited lines; the three external areas with
partial coverage are named inline (OAuth 2.1 draft body, RFC 6749 §10.x, Facebook live docs).
