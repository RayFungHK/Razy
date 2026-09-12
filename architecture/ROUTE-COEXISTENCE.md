# Route Coexistence — Sharing a Domain with Non-Razy Apps

Generated 2026-07. Prime directive applied throughout: every Razy claim below was read
from source at the cited `path:line`; matching semantics were additionally confirmed by
executing the shipped classes (`Razy\Util\PathUtil`, `Razy\Util\StringUtil`,
`Razy\Distributor\RouteDispatcher`) under PHP 8.3.1 in a read-only probe. Where a doc or
comment contradicts the code, the code wins and the contradiction is logged in §2.6
(doc drift). External claims about Apache/Caddy behavior cite official docs only.

**Question under study** (translated from the operator report): *"If the PHP app's
routes and a Python app share one domain, the path is very likely stolen by PHP's
`.htaccess` — especially `lazyRoute` and routes with wildcards."* This document
verifies that report against the code, enumerates the collision surface, and evaluates
improvement directions that remain RZ-013-clean (routes only through `Agent`; generated
rewrite files stay machine-owned — `skills/RAZY-AI-RULES.md:332-339`).

---

## 1. Current state (evidence)

### 1.1 Request lifecycle, end to end

```
HTTP request (Host: example.com, path: /some/deep/path)
│
├─ Apache httpd, .htaccess at the Razy install root (machine-owned; §1.3)
│   ├─ set ENV BASE = URL prefix of the Razy install            htaccess.tpl:4-5
│   ├─ ^\w+/shared/(.*)$ → shared/$1  [L]   (GLOBAL, host-blind) htaccess.tpl:8
│   ├─ domain gate: %{HTTP_HOST} → %{ENV:RAZY_DOMAIN}           htaccess.tpl:11-22
│   │    (exact domains, aliases, then wildcard `*` ⇒ RAZY_DOMAIN=*)
│   ├─ per distributor (gated on RAZY_DOMAIN, scoped to its mount route_path):
│   │    ├─ webassets: ^<mount>webassets/<alias>/(.+?)/(.+)$     [END]  htaccess.tpl:27-30
│   │    ├─ data:      ^<mount>data/(.+)$ → data/<domain>-<dist>/  [L]  htaccess.tpl:31-34
│   │    └─ FALLBACK:  ^<mount>(.*)$ → %{ENV:BASE}index.php [L]        htaccess.tpl:35-42
│   │        iff distributor fallback=true (Distributor.php:70,123)
│   │        AND !-f AND !-d AND !-l (htaccess.tpl:37-39)
│   │        AND denylist $1 !^(index\.php|robots\.txt|sites|system|shared|
│   │                       plugins|asset|repository\.inc\.php|config\.inc\.php|
│   │                       sites\.inc\.php)                        (htaccess.tpl:40)
│   └─ else → normal Apache handling (Alias/proxy/static/file win)
│
├─ Caddy/FrankenPHP alternative: per-domain site block, php_server/worker is the
│   LAST, UNSCOPED handler of each site block                    caddyfile.tpl:40-47
│   (wildcard domain `*` compiles to a `:80` catch-all site  CaddyfileCompiler.php:176-178)
│
└─ PHP front controller (deployed index.php → phar main.php)    asset/setup/index.php:32-62
    ├─ bootstrap: HOSTNAME / PORT / RELATIVE_ROOT / URL_QUERY    bootstrap.inc.php:84-151
    │    • RELATIVE_ROOT = URL distance docroot→SYSTEM_ROOT       bootstrap.inc.php:90-123
    │    • URL_QUERY = REQUEST_URI minus RELATIVE_ROOT, tidy'd     bootstrap.inc.php:142-151
    │      WITH forced trailing slash (tidy(..., true, '/'))  → every path gains "/"
    ├─ /_razy/health, /_razy/metrics answered PRE-DISPATCH         main.php:165-177 (worker)
    │    exact-match on the path only                             main.php:260-271
    │    (Health.php:25,34-40; Metrics.php:42,52-58)
    ├─ Application::host(HOST:PORT) → matchDomain()                Application.php:117-144,689-748
    │    order: exact fqdn → exact domain → alias → one-label      Application.php:696-744
    │    wildcard (`*`→`[^.]+`) → bare `*` default site
    ├─ Application::query(URL_QUERY) → Domain::matchQuery          Application.php:435-449
    │    distributor = LONGEST tidied path prefix of binding       Domain.php:122-152
    │    (pre-sorted deepest-first, Domain.php:69-81; str_starts_with
    │     against prefix that always ends '/', so "/shop/" does
    │     NOT claim "/shopping")
    ├─ Distributor::matchRoute → RouteDispatcher::matchRoute       Distributor.php:277-306
    │    routes sorted by slash-depth, deepest first               RouteDispatcher.php:419-424
    │    standard route: preg_match of pre-compiled regex          RouteDispatcher.php:440-452
    │      regex = /^(TIDIED_ROUTE)(rest-of-path)/ — NO end anchor RouteDispatcher.php:126
    │    lazy route:  str_starts_with(urlQuery, "/alias/route/")   RouteDispatcher.php:453-459
    │      prefix built as '/' + tidy(alias + route, trailing '/') RouteDispatcher.php:254
    │    first match wins; extra trailing segments become extra    RouteDispatcher.php:450-452,457-458
    │    positional args (spread into the closure)
    └─ no match → Error::show404() = HTTP 404 + <h1>404</h1> +      ErrorRenderer.php:38-52
         NotFoundException; reached via main.php:190-215/292-298.
         THERE IS NO FALLBACK BACK TO THE WEB SERVER AT THIS POINT.
```

### 1.2 Who generates what (`php Razy.phar rewrite`)

- CLI entry: `src/system/terminal/rewrite.inc.php` — flags `--caddy`, `--no-worker`,
  `--document-root=PATH` (default `/app/public`) at :30-40; scans `sites.inc.php`
  domain bindings (:57-79); Apache is the default mode (:116-133), Caddy is opt-in
  (:95-115).
- Apache: `Application::updateRewriteRules()` → `Routing\RewriteRuleCompiler::compile()`
  writes `.htaccess` next to `sites.inc.php` (i.e. the install root; `RAZY_PATH` or
  `SYSTEM_ROOT`) — Application.php:502-512, rewrite.inc.php:120-127,
  RewriteRuleCompiler.php:77-94 (atomic tmp+rename at :88-91).
- Caddy: `Application::updateCaddyfile()` → `Routing\CaddyfileCompiler::compile()`
  writes `Caddyfile` — Application.php:529-539, CaddyfileCompiler.php:52-74.
- **IIS `web.config`: no generator exists.** Filesystem sweep of the repo found no
  `web.config` anywhere, and `rewrite.inc.php:10-12` documents exactly two output
  modes (Apache, Caddy).
- **The output is runtime-enforced machine property**: `loadSiteConfig()` records the
  `.htaccess` md5 (Application.php:400-408) and `validation()` — registered as a
  shutdown function on every request (main.php:286-288; body at
  Application.php:663-678) — compares
  it and **regenerates the file whenever the checksum differs**
  (Application.php:663-678). A hand-added `RewriteCond` is therefore erased at the end
  of the next request. RZ-013 (`skills/RAZY-AI-RULES.md:332-339`) plus this watchdog
  means every fix below MUST change the generators, never the output files.

### 1.3 Generated Apache file, byte-exact template

`src/asset/setup/htaccess.tpl` (whole file, 43 lines) — the load-bearing lines:

```
1:  RewriteEngine on
4:  RewriteCond $0#%{REQUEST_URI} ^([^#]*)#(.*)\1$          # BASE = install prefix
5:  RewriteRule ^.*$ - [E=BASE:%2]
8:  RewriteRule ^\w+/shared/(.*)$ shared/$1 [L]             # global, no host gate
...
28:     RewriteCond %{ENV:RAZY_DOMAIN} ={$domain}
29:     RewriteRule ^{$route_path}webassets/{$mapping}/(.+?)/(.+)$ {$dist_path} [END]
32:     RewriteCond %{ENV:RAZY_DOMAIN} ={$domain}
33:     RewriteRule ^{$route_path}data/(.+)$ {$data_path} [L]
36:     RewriteCond %{ENV:RAZY_DOMAIN} ={$domain}
37:     RewriteCond %{REQUEST_FILENAME} !-f
38:     RewriteCond %{REQUEST_FILENAME} !-d
39:     RewriteCond %{REQUEST_FILENAME} !-l
40:     RewriteCond $1 !^(index\.php|robots\.txt|sites|system|shared|plugins|asset|repository\.inc\.php|config\.inc\.php|sites\.inc\.php)
41:     RewriteRule ^{$route_path}(.*)$ %{ENV:BASE}index.php [L]
```

Real generated instances confirm it: `test-razy-cli/.htaccess:17-25` shows distributor
`mysite` mounted at `/` producing `RewriteRule ^(.*)$ %{ENV:BASE}index.php [L]` — the
whole-host claim; `playground/.htaccess:15-20` is an older-generation instance of the
same claim. `{$route_path}` is the distributor's mount prefix from `sites.inc.php`,
`''` for root mounts (RewriteRuleCompiler.php:166).

### 1.4 Generated Caddy file

`src/asset/setup/caddyfile.tpl` (whole file, 50 lines): one site block per domain
(addresses incl. aliases; `*` → `:80` at CaddyfileCompiler.php:174-188), `root *
{$document_root}`, then handle blocks for webassets (:13-21) and data (:22-30), a
host-blind `@shared path /*/shared/*` handler (:31-39), and finally `php_server` —
**unscoped, in both worker and standard mode** (:40-47; compiler emits it per site at
CaddyfileCompiler.php:152-159). Even when a distributor is bound to a sub-path, the
Caddyfile's `php_server` still claims the whole host; only the PHP layer later decides
(`Domain::matchQuery` returning null → 404). Apache generation, by contrast, embeds
`{$route_path}` into every claim (§1.3) — an **Apache/Caddy asymmetry**.

The catch matters because of what `php_server` is: per the official FrankenPHP docs it
is equivalent to a route with `try_files {path} {path}/index.php index.php` — i.e.
*any* request that is not a file on disk is rewritten onto the PHP entry and (worker
mode) handled by the worker ([FrankenPHP configuration docs](https://frankenphp.dev/docs/config/),
section "Caddyfile config"). The docs also expose the fix primitives: `php_server
[<matcher>]` accepts a path matcher, and a worker may declare `match <path>` ("all
requests starting with /api/ will be handled by this worker", overriding `try_files`) —
same URL as above, sections "Matching the worker to a path" and the `php_server`
options block.

`deploy/Caddyfile` (the hand-hardened worker image config) follows the same shape: an
explicit `handle /_razy/health` block (:132-137) and then a catch-all `handle { ...
php_server }` (:139-157); `deploy/README.md:77-80` states the deployment discipline —
"the Ingress only forwards `/` and `/_razy/health`", routes stay in `Agent` +
`rewrite` (RZ-013). The dev container image is even simpler: `reverse_proxy php:9000`
whole-host (`.docker/Caddyfile:4-5`).

### 1.5 Where the mount point lives today

| Layer | Primitive | Evidence |
|---|---|---|
| Install location | `RELATIVE_ROOT`: Razy may sit in a docroot *subdirectory*; `URL_QUERY` and `BASE` env respect it | bootstrap.inc.php:90-123,142-151; htaccess.tpl:4-5 |
| Distributor mount | `sites.inc.php` binding `'domain' => ['/prefix' => 'dist']` | asset/setup/sites.inc.php.tpl:34-40; docs example at documentation/pages/sites-configuration.html:29-44 ("longest matching prefix wins") |
| Mount CLI | `php Razy.phar set <fqdn[/path]> <dist>` writes the binding, then `updateSites()` | system/terminal/set.inc.php:7,14,54-69,105-113 |
| PHP matching | prefix claim, deepest-first, trailing-slash-anchored (`/shop/` ≠ `/shopping`) | Domain.php:74-81,133-148; PathUtil.php:37-40 |
| Apache generation | every generated rule carries `{$route_path}` — claims are already prefix-scoped | htaccess.tpl:29,33,41; RewriteRuleCompiler.php:166-172 |
| Per-dist fallback off | `dist.php 'fallback' => false` → NO front-controller rule for that distributor; server-level 404 instead | Distributor.php:70,123; RewriteRuleCompiler.php:181-186; asset/setup/dist.php.tpl:31-34 |
| Module URL space | lazy routes shadow-routes mount under the **module alias**; standard routes mount at the **distributor root** (absolute, author-provided) | RouteDispatcher.php:254,226 vs :165-201; Module.php:939-942 |
| Site-level segment | `Agent::reserve('api')->addRoute(...)` registers standard routes at `/api/*` without the alias prefix | Agent.php:437-453,546-556; Routing/ReservedRouteRegistrar.php:18-47; tests/AgentTest.php:1002-1021 |
| Framework probes | `/_razy/health`, `/_razy/metrics`: exact-path match inside PHP, pre-dispatch — NOT in any generated rewrite file | Health.php:25,34-40; Metrics.php:42,52-58; main.php:165-177,260-271; no `_razy` string in asset/setup templates (grep over src: only Health/Metrics/PackageTrait) |

There is **no notion of a reserved/excluded path for foreign apps** anywhere: the only
"exclusion" in the claim chain is the hard-coded loop-guard denylist at
`htaccess.tpl:40`, which lists Razy's own entry files and directories, not operator
siblings. (It is a `[L]`-loop breaker per the Apache docs' front-controller loop
example — [Per-directory Rewrites §"The [L] flag and looping"](https://httpd.apache.org/docs/current/rewrite/htaccess.html#loops)
— not a coexistence feature.)

### 1.6 Matching semantics, verified by execution

Probe (real `compileRouteRegex`, `PathUtil::tidy` from the repo, PHP 8.3.1):

```
compileRouteRegex:
  '/user/'                    → /^(\/user\/)((?:.+)?)/
  '/'                         → /^(\/)((?:.+)?)/
  '/route_demo/user/(:d)'     → /^(\/route_demo\/user\/(\d+)\/)((?:.+)?)/
  ':a/:a'                     → /^([^\/]+\/[^\/]+\/)((?:.+)?)/

standard-route claims (route → matched URLs):
  '/user'   ⊢ /user/1/edit            (rest '1/edit' → extra positional args)
  '/'       ⊢ EVERYTHING: /api-py/v1/items, /username, /anything/deep ...

lazy-route claims:
  alias=shop route=''  → '/shop/' ⊢ /shop/, /shop/a/b ; NOT /shoppy/x, /shopping-list

distributor claims (mount → prefix → url):
  mount '/'    prefix '/'      ⊢ /api-py/v1 → claimed
  mount '/shop' prefix '/shop/' ⊢ /shopping/list NOT claimed, /api-py/v1 NOT claimed
```

Reading of the semantics (code anchors):

- `setRoute` tidies every standard route to end with `/` (RouteDispatcher.php:167), then
  `compileRouteRegex` emits `'/^(' . route . '((?:.+)?)/'` — **no `$` end-anchor**
  (RouteDispatcher.php:126), and the tail group is re-split on `/` into extra closure
  arguments (RouteDispatcher.php:448-452). A standard route is therefore a
  *segment-anchored prefix* that swallows everything below it while still enforcing its
  own internal captures.
- Lazy routes are `str_starts_with` against `/alias/route/` (RouteDispatcher.php:453-459;
  prefix built at :254) — pure prefix, no captures; "addLazyRoute: Relative to module
  alias, prefix matching, no capture" is the demo module's own summary
  (`demo_modules/core/route_demo/default/controller/route_demo.php:11-13`).
- Route tokens (`(:d)`, `(:a)`, `:w`, `:[regex]`, `{n,m}`) compile to `\d+`, `[^/]+`,
  etc. via `compileRouteRegex` (RouteDispatcher.php:116-127) — BUT the route string is
  otherwise interpolated into a PCRE **raw** (`preg_replace` only escapes `/` at :126),
  so any regex metacharacter an author writes is live. `addRoute('.*')` is a
  whole-distributor claim.
- `URL_QUERY` gets a forced trailing slash (`tidy(..., true, '/')`,
  bootstrap.inc.php:151; worker twin at main.php:160) — this is what makes the
  segment-anchoring of §1.6 hold at runtime.
- On total miss: `Application::query()` → `Error::show404()` (Application.php:445-447;
  ErrorRenderer.php:38-52 sends `HTTP/1.0 404` then throws `NotFoundException`). PHP
  never *declines* a request it already owns.

---

## 2. Failure-mode analysis

The operator's report decomposes into five distinct leaks. FM-1/FM-2 are the reported
bug; FM-3..FM-6 are adjacent claims the same code makes.

### FM-1 — Root-mounted distributor claims the whole host (the reported theft)

`sites.inc.php` binding `'example.com' => ['/' => 'dist']` + default
`fallback => true` (Distributor.php:70,123) ⇒ generated
`RewriteRule ^(.*)$ %{ENV:BASE}index.php [L]` for that host
(RewriteRuleCompiler.php:181-186 → htaccess.tpl:41; instance:
`test-razy-cli/.htaccess:20-25`). A Python sibling on the same host reaches Apache as a
*proxy location* (`ProxyPass /api-py/ …`) or as an off-docroot vhost — in both cases
there is **no file or directory at `<Razy docroot>/api-py`**, so the `!-f/!-d/!-l`
conditions pass, the denylist has no entry for it (htaccess.tpl:40), and PHP receives
`/api-py/v1/items`. On the Caddy/FrankenPHP path the theft is even unconditional:
`php_server`'s `try_files … index.php` claim is host-wide
([FrankenPHP config docs](https://frankenphp.dev/docs/config/); caddyfile.tpl:40-47).
*Partial mitigation already in code:* a sibling that physically sits **inside the Razy
docroot as a real directory** survives by the `!-d` condition — which explains why some
coexisting setups "work" and others silently break.

### FM-2 — Wildcard/lazy amplification inside PHP (the "lazyRoute eats it worse" half)

Once stolen, `/api-py/v1/items` meets the route table:

- Any module registering a root catch-all — e.g. the shipped demo pattern
  `$agent->addRoute('/', 'index')`
  (`demo_modules/demo/hello_world/default/controller/hello_world.php:34`) — compiles to
  `/^(\/)((?:.+)?)/` and **matches every path in the distributor** (probe, §1.6). Its
  own comment calls this "the module's root URL" (hello_world.php:28-32) — that is
  doc drift D6 below; `Module::addRoute` does not prefix the alias
  (Module.php:694-714) and `setRoute` stores it verbatim (RouteDispatcher.php:165-201).
- Broad patterns (`:a/:a`, any literal regex, `reserve('api')`-style segments) likewise
  cross the foreign prefix: standard routes are distributor-root **absolute**
  (route_demo.php:7-13 "CRITICAL: LEADING SLASH - absolute path from site root").
- The unanchored tail (§1.6) means even a *legitimate* deep-looking route such as
  `/user/profile` also serves `/user/profileX/anything` — foreign namespaces that
  merely share a prefix with any Razy route get absorbed.
- A lazy route at a module root is prefix-bound to `/alias/` (RouteDispatcher.php:254,
  454) — so it cannot, by itself, reach *outside* its alias; the theft at host level is
  FM-1. But inside a stolen request, depth-sorted first-match
  (RouteDispatcher.php:419-430) can hand the Python path to a lazy handler at
  `/alias/<anything>` the moment the foreign mount was chosen *under* that alias (e.g.
  Python mounted at `/shop/reports` below a Razy lazy route on alias `shop` route
  `''`): `str_starts_with('/shop/reports/x', '/shop/')` → true. **Confirmed:** a
  module-root lazy route swallows every deeper path the web server already handed PHP,
  including foreign sub-app paths that live "under" the alias's URL space.
- Worst observable outcome: not a clean 404, but a **200 from the wrong app** (demo
  catch-alls render pages; `__onEntry`/middleware run side effects; a stale lazy handler
  can 500). Monitoring that only tracks "not 5xx" will not see the theft.

### FM-3 — `shared` rule is host-blind and prefix-blind

`RewriteRule ^\w+/shared/(.*)$ shared/$1 [L]` (htaccess.tpl:8) has **no domain gate and
no distributor mount**: on every host the file is active for, ANY path whose second
segment is `shared` is rewritten into Razy's shared-module directory. A Python sibling
mounted at `/portal/shared` (or any app exposing `/<anything>/shared/...` asset paths)
is stolen *even when everything else is mounted correctly*. Caddy twin:
`@shared path /*/shared/*` (caddyfile.tpl:31-39), emitted unconditionally into every
site block (CaddyfileCompiler.php:147-150).

### FM-4 — The denylist is a loop guard, not an exclusion API

`RewriteCond $1 !^(index\.php|robots\.txt|sites|system|shared|plugins|asset|...)`
(htaccess.tpl:40) exists so the `[L]` pass re-entry stops on `index.php` (Apache
per-directory loop semantics —
[htaccess docs §loops](https://httpd.apache.org/docs/current/rewrite/htaccess.html#loops)).
Its alternatives are prefix-anchored without `$` — `^asset` also exempts `asset-foo`,
`^sites` also exempts `sites-admin` — so foreign paths *accidentally* survive when they
happen to start like an internal name, and Razy makes no promise about it. Accidental
behavior ≠ coexistence contract.

### FM-5 — Probes don't reach PHP in sub-path mounts

`/_razy/health` / `/_razy/metrics` are answered **inside** PHP
(Health.php:34-40, Metrics.php:52-58) but appear **in no generated rewrite file**
(grep: no `_razy` in `asset/setup` templates). With a distributor mounted at `/shop`,
the fallback rule is `^shop/(.*)$` (htaccess.tpl:41) — `/…/health` never reaches
`index.php`, so the documented health/metrics endpoints 404 at the web-server level in
shared-root Apache layouts (they only work where a distributor owns `/`, or under the
hand-written `deploy/Caddyfile` which adds the block manually, deploy/Caddyfile:132-137).
Kubernetes-style probes on a path-coexisting host therefore fail silently.

### FM-6 — Host-gate semantics differ between .htaccess and PHP

`domainToPattern` expands `*` to `.+` (RewriteRuleCompiler.php:45-51, esp. :51), so
Apache's `RAZY_DOMAIN` gate lets `a.b.example.com` claim the `*.example.com` bindings —
while PHP's `matchDomain` wildcard uses `[^.]+` (one label only,
Application.php:723-738) and throws `ConfigurationException("No domain matched…")`
for deeper labels (Application.php:139-140). A sibling at `a.b.example.com` sharing the
docroot/vhost gets a PHP error page instead of its own app. (The seed guard at
Application.php:316-321 limits *which* dists may be seeded onto apex wildcards, but the
generated gate is broader than the matcher.)

### 2.6 Doc drift discovered (code wins)

| # | Claim (source) | Code reality |
|---|---|---|
| D1 | ":a Match any characters" — Agent.php:312-313 | compiles to `[^/]+` — segment-scoped, not "any" (RouteDispatcher.php:119-122; demo doc states the truth: route_demo.php:16) |
| D2 | `dist.php 'internal_bridge' => [... 'path' => '/__internal/bridge']` — documentation/pages/sites-configuration.html:84-88,106 | no `internal_bridge`/`__internal` string anywhere in `src/` (grep across src); dead or unshipped doc |
| D3 | `reserve` "segment declared in dist.php `reserve`" — Agent.php:433-435 docblock | nothing parses `reserve` from dist.php (grep); reservation is code-side `Agent::reserve()` only |
| D4 | `playground/.htaccess:18` denylist contains `library` and lacks the domain gate | current template differs (htaccess.tpl:40 has no `library`); stale generated artifact — fine (machine-owned), but it documents rule churn |
| D5 | demo comment: `addRoute('/')` = "match the module's root URL" — hello_world.php:28-32 | registers a distributor-root catch-all (Module.php:694-714; probe §1.6) — teaches the FM-2 footgun |
| D6 | AGENTS.md "repo README/manual are code-verified as of 2026-07" | `manual/`, `docs/`, `documentation/` contain zero occurrences of subdomain/same-domain/nginx/reverse-proxy/path-prefix guidance (grep) — deployment *coexistence* is simply undocumented |

---

## 3. Options

All options assume generated output stays machine-owned (RZ-013,
`skills/RAZY-AI-RULES.md:332-339`; watchdog Application.php:673-677 makes anything else
self-erasing). "Files to change" lists touch-points, not a commit plan.

### (a) Mount-prefix per distributor — "claim only `/<prefix>/…`"

**Mechanism.** Already the native Apache model: bind the distributor to a path prefix
(`php Razy.phar set example.com/app mydist`, set.inc.php:54-69) and every generated
claim carries `{$route_path}` (htaccess.tpl:29,33,41; RewriteRuleCompiler.php:166) —
foreign root paths then fall through to normal Apache processing untouched. **The work
is the Caddy generator**: scope each site block's PHP claim so a sub-path mount does
not front-control the host. Concretely, emit per mount
`@razy_<id> path /<prefix> /<prefix>/*` + `handle @razy_<id> { rewrite … /index.php; php_server }`
(matcher support: `php_server [<matcher>]`, [FrankenPHP config docs](https://frankenphp.dev/docs/config/)),
or keep one `php_server` and use the worker-level
`worker { file …; match /<prefix>/* }` directive (same doc: "all requests starting with
/api/ will be handled by this worker"). Non-claimed paths would then be 404/static —
proxying them is option (b)/(c) territory.

| | |
|---|---|
| Files | `src/asset/setup/caddyfile.tpl`, `src/library/Razy/Routing/CaddyfileCompiler.php:134-159`, `tests/CaddyfileCompilerTest.php` (multi-path case exists: :503-525) |
| Trade-offs | (+) Zero config surface on Apache — it is already correct; (+) PHP layer already prefix-scoped (Domain.php:143; `/shop/` ≠ `/shopping`, probe §1.6); (−) site URLs gain `/<prefix>`; assets must honor `RAZY_URL_ROOT` (they do: bootstrap.inc.php:136-137, Distributor.php:626-633); (−) Caddy match semantics differ worker vs standard mode (match overrides try_files — docs above); (−) FM-5 probes still stranded → pair with health rule |
| Feasibility | High. The route_path plumbing exists on the Apache side; Caddy compiler already iterates per-path (CaddyfileCompiler.php:127-145) and would just emit one matcher block per mount instead of one tail `php_server` |

### (b) Declared sibling-exclusion list — operator config → generator emits passthrough

**Mechanism.** New operator-declared list, e.g.
`'exclude_paths' => ['/api-py', '/reporting']` consumed by both compilers.

Apache emission (per host or global section, before distributor blocks):
```
# ── Operator-declared sibling applications (never claimed) ──
RewriteCond %{REQUEST_URI} ^/api-py(/|$) [OR]
RewriteCond %{REQUEST_URI} ^/reporting(/|$)
RewriteRule ^ - [L]
```
`REQUEST_URI` is the correct variable here because per-directory patterns have the
directory prefix stripped ([htaccess docs §"What URL does the rule see?"](https://httpd.apache.org/docs/current/rewrite/htaccess.html#path-stripping)),
and `[L]` on a `-` target breaks this ruleset without an internal redirect
([flags doc](https://httpd.apache.org/docs/current/rewrite/flags.html#flag_l)).
If the sibling is a *proxy* and the operator wants the rewrite layer to do the hop, the
`[P]` flag exists but pulls `mod_proxy` and carries the SSRF warning Apache prints for
it ([flags §P](https://httpd.apache.org/docs/current/rewrite/flags.html#flag_p)) —
prefer `Alias`/`ProxyPass` in the vhost (option (c)) and keep the Razy file to
"do-not-claim".
Caddy emission: a `handle`/named-matcher passthrough ahead of the scoped PHP handler —
with one honest limit: a Caddyfile *must* name an upstream to proxy, so the generator
can only emit "don't claim" (final `respond 404`) unless the operator also supplies an
upstream URL, which then makes Razy's generated file the app-graph owner — a bigger
responsibility decision (open question Q3).

| | |
|---|---|
| Files | new key parse beside `fallback` in `Distributor.php:104-126` (per-dist) or `Application::updateSites()/loadSiteConfig()` (site-level, Application.php:379-423,306-367); `htaccess.tpl` (new `exclusion` block before `rewrite`), `RewriteRuleCompiler.php:77-141`; `caddyfile.tpl`/`CaddyfileCompiler.php:109-161`; `sites.inc.php.tpl` (template-written file, Application.php:550-604) if site-level; compiler tests mirroring `CaddyfileCompilerTest` |
| Trade-offs | (+) Direct cure for FM-1; stays machine-owned (deterministic regeneration keeps the checksum watchdog happy, Application.php:673-677); (+) replaces the accidental FM-4 behavior with a contract; (−) one more machine-owned key; (−) exclusion is prefix-granular only — fine for real siblings; (−) site-level vs per-dist placement is a genuine fork (Q1) |
| Feasibility | High; both compilers already loop over distributors/paths and templates are block-based (`newBlock` API used throughout RewriteRuleCompiler.php:118-186) |

### (c) Front-proxy-first as the recommended production topology

**Mechanism.** No framework change. A reverse proxy in front splits paths; Razy's
generated file is used only where Razy owns the docroot root. Worked artifacts to add
under `deploy/` (e.g. `deploy/coexistence/`): Caddy edge example
`handle /api-py/* { reverse_proxy 127.0.0.1:8000 }` + `handle { php_server }`
(ordered `handle` blocks are the documented Caddy composition primitive — the
FrankenPHP docs' own worker-match section describes the same scoping idea,
[docs](https://frankenphp.dev/docs/config/)); nginx equivalent
`location ^~ /api-py/ { proxy_pass …; }`. Apache-only variant: vhost
`ProxyPass /api-py/ http://127.0.0.1:8000/` + `Location`-based handling beats
`.htaccess` claims by ordering (`mod_rewrite` docs themselves point at
"When not to use mod_rewrite"/`FallbackResource` for exactly these cases,
[flags see-also](https://httpd.apache.org/docs/current/rewrite/flags.html),
[htaccess §RewriteBase](https://httpd.apache.org/docs/current/rewrite/htaccess.html#rewritebase)
noting `FallbackResource` is "simpler and more efficient" for plain front-control).
Consistent with the existing stance in `deploy/README.md:77-80` (edge forwards only
`/` and `/_razy/health`; RZ-013 discipline preserved).

| | |
|---|---|
| Files | docs-only: `deploy/` samples + a manual/docs page (the D6 gap) |
| Trade-offs | (+) zero risk, zero code, strongest isolation; (+) gives siblings *hosts/proxies*, not scraps; (−) requires an edge operator owns (not always available on shared Apache hosts); (−) two more artifacts (edge config) to keep honest; (−) does not help operators whose only lever is the generated `.htaccess` — that is (b) |
| Feasibility | Immediate (documentation + samples only) |

### (d) In-PHP interop fallback on router miss — and its hard limit

**Mechanism (what is possible).** Today, miss ⇒ unconditional PHP 404
(Application.php:445-447 → ErrorRenderer.php:38-52). An opt-in "miss handler" could:
(1) **redirect** (`302` to the sibling origin with the same path) — cheap, visible URL
change, breaks API semantics; (2) **internal reverse-proxy passthrough** — PHP relays
the request to a declared upstream and streams the response.

**What is impossible at that layer, stated plainly.** Once `.htaccess` claimed the
request, PHP *is* the response owner; there is no mechanism to "return" the request to
Apache's normal handler chain — no `mod_rewrite` re-entry after PHP runs, no
`ErrorDocument`-style hand-back to a proxied location. Also: headers/body already
stream through `index.php`, worker mode keeps state across requests
(main.php:116-256), and the repo has **no verified HTTP-client primitive**
(architecture/PORTING-VALUE.md:40 lists it as "NOT VERIFIED", Socialite-class gaps
"implicit XL"), plus relaying arbitrary paths to an upstream is the SSRF shape Apache
itself warns about for `[P]` ([flags §P Security Warning](https://httpd.apache.org/docs/current/rewrite/flags.html#flag_p)).
**Conclusion:** treat (d) as *documentation of the boundary* now; any real miss-handler
is a separate, explicitly-configured feature, never an implicit behavior.

| | |
|---|---|
| Files (if ever built) | miss branch at `Application::query()`/main.php 404 sites; new dist.php key beside `fallback`; HTTP relay primitive (must be built + gated); tests per RZ-014 |
| Trade-offs | (+) rescues only the "PHP already owns it" subset of FM-1; (−) cannot fix FM-1 at its root; (−) SSRF/streaming/worker-state hazards; (−) risk of two apps disagreeing about who owns a path |
| Feasibility | Low value/high risk — document-only recommended |

### (e) Subdomain-first as the documented default (path-mount = explicit opt-in)

**Mechanism.** The model the repo already lives by — per-domain distributor bindings
(documentation/pages/sites-configuration.html:29-44; production-sample runs one dist per
host, `production-sample/sites/...`; `matchDomain` exact-first, Application.php:696-721;
Caddy site blocks are per-domain, CaddyfileCompiler.php:109-161). Formalize: manual
says "sibling on the same box ⇒ `pyapp.example.com`"; path-mounting a distributor is
documented as the opt-in that requires (a)+(b) to be safe.

| | |
|---|---|
| Files | docs-only + optionally a `rewrite` summary line printing "claims host-level" when a dist is bound to `/` |
| Trade-offs | (+) matches existing code grain — zero behavioral change; (+) cleanest failure domain separation; (−) DNS + TLS per app (wildcard ACME solves both at cost of scope); (−) unusable where customers own one path on a shared apex |
| Feasibility | Immediate (docs) |

### (f) Bonus — catch-all route audit in `validate` (guardrail for FM-2)

`validate` and `routes --regex` already walk the route table
(system/terminal/validate.inc.php; routes.inc.php:5-22, incl. `--regex` which prints
compiled patterns). A warning when a standard route compiles to a distributor-root
claim (`^(\/)`-equivalent, §1.6) or when a lazy route at a module root shadows a
*sibling* distributor's mount prefix turns FM-2's silent 200-from-wrong-app into a
build-time finding. Files: `validate.inc.php` (+ maybe `RouteDispatcher` accessor for
compiled regex — already stored per route, RouteDispatcher.php:196).
Trade-offs: (+) RZ-013 clean, tiny, catches the demo footgun class (D5); (−) heuristic —
legitimate catch-alls need an allow-comment, same convention as `lint-allow`
(`skills/RAZY-AI-RULES.md:387`).

---

## 4. Recommendation (phased, smallest-first)

> **Status (2026-07): Phases 0–3 shipped** (docs+samples; `exclude_paths` both
> generators; Caddy claim scoping + health handle; `validate` route audit +
> `rewrite` host-claim summary — option (f), functional probes over compiled
> regexes, `dist.php` `route_audit_allow` opt-out). Q5 answered for the
> Caddy half below; Q1–Q3 answered in §5.

1. **Phase 0 — documentation only (this week).** Publish the coexistence page (fills
   D6): "same host ⇒ proxy-split at the edge or separate subdomain" (options c+e), the
   FM-1/FM-2 mechanism, and the FM-5 probe caveat. Touches `manual/`/`docs/` and a
   `deploy/coexistence/` sample; no framework code, no generated-output change.
2. **Phase 1 — declared exclusions (option b).** Add the machine-owned `exclude_paths`
   concept to both generators + tests; regenerate on `rewrite`. This is the only change
   that *fixes the reported theft* while staying inside the RZ-013 contract, and it
   subsumes FM-3/FM-4 hygiene (the `shared` rule and denylist become generator-informed
   rather than incidental).
3. **Phase 2 — Caddy claim-scoping (option a).** Scope `php_server` per mount via
   matcher / `worker match` so the Caddyfile matches Apache's prefix discipline;
   also emit the `/_razy/health` block the generated file currently lacks (FM-5).
4. **Phase 3 — validate guardrail (option f).** Catch-all audit + host-claim summary in
   `rewrite` output. Cheap insurance for FM-2's PHP-side half.
5. **Do not build (d) implicitly.** Document the impossibility boundary instead;
   revisit only against a concrete customer with a verified HTTP relay primitive.

### External sources used

- Apache [RewriteRule Flags](https://httpd.apache.org/docs/current/rewrite/flags.html)
  — `[L]` vs `[END]` in per-directory context, `[P]` proxy + SSRF warning (fetched).
- Apache [Per-directory Rewrites](https://httpd.apache.org/docs/current/rewrite/htaccess.html)
  — prefix stripping in `.htaccess` patterns, `[L]` loop mechanics, `FallbackResource`
  pointer (fetched).
- [FrankenPHP — Configuration](https://frankenphp.dev/docs/config/) —
  `php_server` ⇔ `try_files {path} {path}/index.php index.php`, `php_server
  [<matcher>]`, worker `match <path>` (fetched). (Caddy's own site was unreachable from
  this environment; the php_server equivalence is quoted from FrankenPHP's docs, which
  is the implementation the generated Caddyfile targets.)

---

## 5. Open questions for the maintainer

| # | Question | Why it matters |
|---|---|---|
| Q1 | **ANSWERED (Phase 1 shipped): host-level in `sites.inc.php`** — an exclusion describes the shared host, not one distributor; `Routing\ExcludePaths` documents the decision, read path `Application::loadSiteConfig()` → both compilers. | Decides compiler API + watchdog behavior |
| Q2 | **ANSWERED (Phase 1 shipped): denylist stays a pure loop-guard**; the declared `exclude_paths` list is the coexistence contract (no reliance on accidental exemptions). | FM-4's accidental exemptions disappear either way; pick deliberately |
| Q3 | **ANSWERED (Phase 1 shipped): NO `reverse_proxy` emission** — the generated file only de-claims excluded paths (php_server matcher); upstream wiring stays edge-operator-owned. | Ownership boundary of machine-generated config |
| Q4 | Host-gate truth: align `.htaccess` `*`-expansion to `[^.]+` (RewriteRuleCompiler.php:51 → match Application.php:729) or widen PHP to `.+`? | FM-6; changing PHP affects `matchDomain` for everyone |
| Q5 | **ANSWERED — Caddy half (Phase 2 shipped)**: scoped hosts (all mounts sub-path) emit `handle /_razy/health { header no-store; php_server }` before the claim, pattern from deploy/Caddyfile:132-137; root mounts already reach the probe. **Apache variant stays open** — Phase 2 scope was the Caddy generator; sub-path mounts on Apache still strand the probe (FM-5). | FM-5 closed where it was the reported gap; Apache half needs its own decision |
| Q6 | `reserve` — was a dist.php `reserve` key intended (docblock says so, Agent.php:433-435; nothing parses it)? Fix code or docblock (D3)? | Affects §3(a)/(f) design surface |
| Q7 | Is `internal_bridge.path` (documentation/pages/sites-configuration.html:84-88) a planned feature or stale doc (D2)? If planned, it needs generator support like everything else | Prevents a second host-level claim appearing without exclusion support |
| Q8 | Should `^\w+/shared/` (htaccess.tpl:8) and `@shared` (caddyfile.tpl:33) be domain-gated and/or narrowed to a fixed first segment? | FM-3 is unconditional theft today |

## Standing caveats

- All line refs against working tree at `VERSION` 1.0.3-beta-era sources
  (RAZY_VERSION `1.0.3-beta`, bootstrap.inc.php:40); re-verify after the next core
  refactor (RouteDispatcher/Compiler extractions are recent, see their class docblocks).
- The probe (§1.6) exercised pure matching primitives; full-stack behavior under
  Apache also depends on `AllowOverride FileInfo` + `Options FollowSymLinks` — without
  them the whole generated file is inert
  ([htaccess docs §Prerequisites](https://httpd.apache.org/docs/current/rewrite/htaccess.html#prerequisites)).
- No other file was modified by this document; per instructions it is the sole new
  artifact.
