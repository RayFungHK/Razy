# 03 — Routing & Requests

Route registration, the routed-info contract, the `xhr()` object, superglobal policy, and
what changes under persistent worker mode. All `Agent` methods verified in
`src/library/Razy/Agent.php`; routed-info literal verified in
`src/library/Razy/Distributor/RouteDispatcher.php:496-506`.

---

## 1. Route registration (Agent)

Every route lives under your module's URL root (`/vendor/module/...`).

### `addLazyRoute` — nested paths = folders

```php
$agent->addLazyRoute([
    '/'          => 'main',              // slash-less → controller/<moduleClass>.main.php
    'demo'       => [                    // demo/ = controller/demo/ folder
        '/'       => 'demo/index',       // slash path → controller/demo/index.php
        'insert'  => 'demo/insert',      // NEVER add .php — loader appends it
    ],
]);
```

Array keys map URL levels to **folder levels** under `controller/`
(`Agent.php:268-271` docblock); files are resolved at match time ("lazy" — the file is
resolved, not preloaded). This is the shape the demos use
(`database_demo/default/controller/database_demo.php:49-63`).

### `addRoute` — regex-flavoured routes with capture placeholders

Placeholder vocabulary, verbatim from the `Agent::addRoute` docblock (`Agent.php:310-321`):

| Token | Matches |
|---|---|
| `:a` | any characters |
| `:d` | digits `0-9` |
| `:D` | non-digits |
| `:w` | alphabet `a-zA-Z` |
| `:W` | non-alphabet |
| `:[\w\d-@*]` | any inline regex character class |
| `{min,max}` suffix | length window, e.g. `:d{4}`; `:w{3,}` |

Captured values arrive in `getRoutedInfo()['arguments']` (see §2). Regex routes match with
priority over plain paths in URL-query routing (`Agent.php:310`).

### `group`, `middleware`, `reserve`, `addScript`, `addShadowRoute`

```php
$agent->group('v1', function (RouteGroup $v1): void {      // Agent.php:419-433
    $v1->middleware($authGuard);                            // group middleware
    $v1->addRoute('user/:d', 'api/user');                   // → /acme/mod/v1/user/{id}
    $v1->group('admin', function (RouteGroup $admin): void  // nested groups supported
        { $admin->addLazyRoute('panel' => 'api/panel'); });
});

$agent->middleware($globalGuard);                           // module-global, Agent.php:387
$agent->reserve('api')->addRoute(':w', 'api/edge');         // site-level '/api/*';
       // segment MUST be declared in dist.php 'reserve' (Agent.php:435-444).
       // Paths never include .php — ClosureLoader appends it (ClosureLoader.php:130)

$agent->addScript('post' => 'scripts/post_render');         // runs AFTER main route handler
       // (Agent.php:252-265; under CLI these are the CLIScripts table —
       //   RouteDispatcher.php:412-417)

$agent->addShadowRoute('legacy/thing', 'other/vendor_mod', 'thing/entry');
       // serve another module's closure under YOUR URL prefix;
       // shadowing your own module throws (Agent.php:244-246)
```

Middleware runs in **three levels**: module-global → group → route
(`readme.md` Routing; enforced ordering per `Agent.php:380-387`). Keep them stateless:
workers reuse the process.

## 2. The routed-info contract

`$this->getRoutedInfo(): array` (`Controller.php:498-501`) returns the literal built at
match time — exact keys from `RouteDispatcher.php:496-506`:

```php
[
    'url_query'    => string,   // the matched URL query
    'base_url'     => string,   // site URL + matched route (no trailing slash)
    'route'        => string,   // tidied registered route pattern
    'module'       => string,   // owning module code
    'closure_path' => string,   // resolved closure path
    'arguments'    => array,    // captured placeholder values
    'type'         => string,   // 'LazyRoute' | 'Route' | 'Script' | ...
    'method'       => string,   // HTTP method or '*' (default)
    'is_shadow'    => bool,     // matched via addShadowRoute
    // 'contains'   => mixed,   // present only for callable-routes with data (:509)
]
```

Read it defensively; in a 404 it can be empty — the dispatcher clears it per worker request
(`resetRoutedInfo()`, `RouteDispatcher.php:393-396`, added specifically to stop stale-route
leakage into error handlers).

Reading `arguments`: cast, always — the values are URL strings:

```php
$id = (int) ($this->getRoutedInfo()['arguments']['id'] ?? 0);   // rules-pack verified line
```

## 3. `xhr()` — the object factory

`$this->xhr(bool $returnAsArray = false): XHR` (`Controller.php:370`). It **builds then
sends**; there is no static JSON helper. Verified surface (`src/library/Razy/XHR.php`):

| Method | Line | Behaviour |
|---|---|---|
| `->allowOrigin(string)` | `:82` | CORS header; **defaults to `SITE_URL_ROOT`, not `*`** (`:42`) |
| `->corp(string)` | `:114` | CORP header; default `cross-origin` (`:48`); constants `CORP_SAME_SITE/_SAME_ORIGIN/_CROSS_ORIGIN` (`:33-39`) |
| `->data($dataset)` | `:130` | sets body content; recursively parses scalars/iterables/`__toString` (`:288-314`) |
| `->set($name, $v)` | `:254` | adds a named `params` entry to envelopes |
| `->responseCode(int)` | `:140` | clamps to 100-599 (`:142`) |
| `->onComplete(callable)` | `:274` | runs after output |
| `->sendData(bool $success, string $msg='')` | `:152` | **oaao SPA envelope**: `{success, hash, timestamp, message?, data?, params?}` |
| `->responseAsBody(array)` | `:185` | **sets only** a pre-built flat body — nothing is sent yet |
| `->sendEnvelope()` | `:197` | emits the `responseAsBody` set body; throws if unset (`:199-201`) |
| `->send(bool $success=true, string $msg='')` | `:219` | legacy envelope `{result, hash, timestamp, response, message?, params?}` |

Terminal behaviour: every send path ends in `output()` (`:324-343`) which sets headers,
`ob_clean()`s the buffer (`:333-335`), echoes JSON, runs `onComplete`, then **throws an
internal `HttpException` to unwind the dispatch stack** (`:342`). Code after a send is dead
by design — structure handlers accordingly (early-return, no cleanup after send; put
cleanup in `onComplete`).

```php
return function (): void {
    /** @var Razy\Controller $this */
    $id = (int) ($this->getRoutedInfo()['arguments']['id'] ?? 0);
    if ($id <= 0) {
        $this->xhr()->responseCode(422)->responseAsBody(['error' => 'bad id'])->sendEnvelope();
    }
    $this->xhr()
        ->allowOrigin($this->getSiteURL())
        ->responseAsBody(['data' => $this->api('acme/store')?->getProduct($id)])
        ->sendEnvelope();
};
```
*illustrative* — composes verified methods (`responseCode` clamp `:142`, `sendEnvelope`
contract `:197-208`, `api()` nullability `:508-513`).

`$this->xhr(true)` returns the envelope **as array without outputting** — handy for tests
and internal composition (`Controller.php:370` param, `sendData`/`sendEnvelope` return
branches `:172-177`, `:203-205`).

## 4. Superglobals policy (RZ-003)

`$_GET/$_POST/$_COOKIE/$_SERVER/$_ENV/$_FILES` are available — Razy does not wrap
requests — but you own the discipline:

1. **Prefer `addRoute` placeholders** (`:d`/`:w`, §1) over ad-hoc query parsing.
2. Cast immediately or validate. Working demo pattern
   (`demo_modules/core/event_demo/default/controller/event_demo.order.php:13-15`, each
   line carrying its `// lint-allow: RZ-003` justification):

   ```php
   $product  = htmlspecialchars($_GET['product'] ?? 'Unknown Product', ENT_QUOTES, 'UTF-8');
   $quantity = max(1, (int) ($_GET['quantity'] ?? 1));
   $price    = (float) ($_GET['price'] ?? 29.99);
   ```

3. Never interpolate superglobal values into SQL (RZ-003 → [04 §Security](04-database.md))
   or into raw template output (RZ-004 → [05](05-templates.md)).
4. `$_SERVER` in worker mode: route state is **recomputed per request**; framework
   constants like `URL_QUERY` are explicitly not trusted stale across requests (per
   `benchmark/results/COMPARISON-REPORT.md` worker-fix notes) — read request facts from
   `getRoutedInfo()`, not from long-lived constants.

File uploads: validate type/size, store under `getDataPath()` only (RZ-006).

## 5. Worker mode (persistent process)

`php Razy.phar serve --dist <dist> --worker [--max-requests N]` (`serve.inc.php` usage,
default 500). Production pattern: FrankenPHP worker + Caddy
(`benchmark/docker/Caddyfile.razy`):

```
{
	frankenphp {
		worker /app/site/index.php
	}
	order php_server before file_server
}
:8080 {
	root * /app/site
	php_server
}
```

What happens per request (verified in the benchmark post-mortem —
`benchmark/results/COMPARISON-REPORT.md` "Worker Mode Fixes" + `Worker Mode Optimization`
table): the full application graph boots **once**; per request Razy resets
`http_response_code(200)`, strips stale headers, drains output buffers, recomputes the URL
from `$_SERVER`, clears the routed-info, resets the session writer, and runs GC every 500
requests. The per-request cost of your module code is therefore fully yours.

Module guidance:

- **Be stateless.** Module classes stay loaded; static properties persist across requests.
  The framework resets its own module runtime (route/closure/event registries) between
  worker dispatches (`Module::resetForWorker`, referenced by the optimization doc
  `COMPARISON-REPORT.md:20-36`) but **not your statics**.
- No cross-request caches keyed by request data (RZ-008). If you need caching, use the
  Cache service (PSR-16 adapters: File/Redis/Null in `src/library/Razy/Cache/`).
- `Database` keeps a persistent PDO by default in the MySQL driver
  (`Database/Driver/MySQL.php` `getConnectionOptions()` returns `PDO::ATTR_PERSISTENT =>
  true`) — connections survive requests; wrap per-request work in transactions
  ([04](04-database.md)).
- `WORKER_MAX_REQUESTS` bounds process lifetime (`benchmark/results/BENCHMARK-REPORT.md`
  worker fixes: worker loop with max-requests support) — leak-tolerant deploys should still
  set it.
- Hot updates (file-change → drain/restart strategies) exist as a **library**
  (`src/library/Razy/Worker/`), not wired into the default request path
  (`RAZY-ANALYSIS-REPORT.md` §8.2). Treat zero-downtime hotplug as **[planned]**.

Next: [04-database.md](04-database.md).
