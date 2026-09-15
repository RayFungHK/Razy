# 02 — Modules & Lifecycle

Modules are Razy's unit of everything: versioning, routing root, dependency scope, API
contract, and team boundary. This page covers anatomy, the hook chain, versions/tags,
`dist.php`, and the only three sanctioned ways for modules to talk.

Rules cross-references throughout refer to
[`skills/RAZY-AI-RULES.md`](../skills/RAZY-AI-RULES.md) (RZ-001…RZ-014).

---

## 1. Anatomy

Verified directory shapes (`demo_modules/data/database_demo/`,
`demo_modules/core/template_demo/`):

```
<vendor>/<module>/
├── module.php                 # module identity: module_code, name, author, description, version
│                              #   (database_demo/module.php:14-20)
└── <version-tag>/             # e.g. default/, v2/ — dist.php pins which tag a site runs
    ├── package.php            #   deps + api_name (ModuleInfo.php:238); see 06
    ├── controller/
    │   ├── <shortname>.php            # main controller — returns anonymous class
    │   ├── <shortname>.<method>.php   # handler closures bound to the controller ($this)
    │   └── <dir>/<handler>.php        # nested paths mirror URL folders (addLazyRoute)
    ├── view/                  # *.tpl templates (loadTemplate resolves view/<name>[.tpl],
    │                          #   Controller.php:382-390)
    ├── model/                 # ORM model files (loadModel, Controller.php:574,581)
    ├── migration/             # migrations (getMigrationManager, Controller.php:670-683)
    ├── plugins/               # optional Template/Collection/… plugins
    │                          #   (registerPluginLoader reads <module>/plugins/*,
    │                            Controller.php:693-699)
    ├── data/ and webassets/   # runtime data & assets — write only under
                               #   getDataPath()/getAssetPath() roots (RZ-006)
```

The main controller **returns an anonymous class** extending `Razy\Controller`
(`demo_modules/data/database_demo/default/controller/database_demo.php:43`). Handler files
return plain closures; inside them `$this` is the module controller
(`database_demo/default/controller/demo/insert.php:29-31`).

Undeclared method calls on the controller resolve in this order
(`Controller.php:219-222`): bindings from `Agent::bind()` first, then the external closure
file `controller/{ClassName}.{method}.php`.

## 2. The 13 lifecycle hooks

`Controller` exposes **13 overridable `__on*` hooks** (each line verified in
`src/library/Razy/Controller.php`). The readme diagram adds "(await callbacks)" as a 14th
*phase* — the callbacks scheduled by `Agent::await()` run once their peer module is ready
(`Module.php:228`) — but there are 13 hook **methods**.

| Hook | Signature | Return semantics | Use it for | Never |
|---|---|---|---|---|
| `__onInit` | `(Agent $agent): bool` (`:81`) | `false` → module **Failed** | register routes/APIs/events, bind | IO, API calls, DB, file writes (RZ-009) |
| `__onLoad` | `(Agent $agent): bool` (`:129`) | `false` → **Skipped** | react after all modules registered | side effects |
| `__onRequire` | `(): bool` (`:211`) | `false` → **Failed** | verify your module is ready | IO-heavy probes |
| `__onDispatch` | `(): bool` (`:99`) | `false` → removed from queue, silent | opt-in/opt-out per request | slow checks |
| `__onReady` | `(): void` (`:137`) | — | cross-module init once everyone's loaded | heavy IO |
| `__onScriptReady` | `(ModuleInfo $module): void` (`:118`) | — | observe another module about to run scripts | — |
| `__onRouted` | `(ModuleInfo $moduleInfo): void` (`:109`) | — | observe route match on the matched module | assuming "mine" — check the argument |
| `__onEntry` | `(array $routedInfo): void` (`:146`) | — | per-request state, request-scoped logging | mutating shared state (RZ-008) |
| `__onError` | `(string $path, Throwable $e): void` (`:158`) | default renders exception | translate/hide errors | rethrow-and-leak |
| `__onAPICall` | `(ModuleInfo $module, string $method, string $fqdn = ''): bool` (`:173`) | **default `true` — open** | allow-list who may call you | leaving unimplemented for sensitive commands |
| `__onBridgeCall` | `(string $sourceDistributor, string $command): bool` (`:187`) | **default `true` — open** | allow-list callers/commands | leaving unimplemented (RZ-002) |
| `__onTouch` | `(ModuleInfo $module, string $version, string $message = ''): bool` (`:201`) | bool | react to a peer touch (version probes) | heavy work |
| `__onDispose` | `(): void` (`:89`) | — | cleanup after dispatch | storing state for the next request |

Order (per readme Core Concepts, consistent with the code's hook docblocks):

```
Scan → __onInit (per module) → __onLoad (all registered) → __onRequire
     → [await callbacks] → __onReady → __onScriptReady
     → __onDispatch (pre-route) → __onRouted (route matched) → __onEntry → [handler]
     → script routes → __onDispose
```

**Read the `false`** (RZ-009). `__onInit` → Failed, `__onRequire` → Failed,
`__onDispatch`/`__onLoad` → Skipped; `__onRouted`/`__onReady`/`__onEntry`/`__onDispose` are
`void`. Do not silently swallow a peer's failure — handle it or log it.

## 3. Versioned modules & tags

One module folder holds several version tags (`default/`, `v2/`). Sites pin tags in
`dist.php`; domains pick a tag with `@` suffix (`example.com@beta`) or path entries
(`'/beta' => 'mysite@beta'` — verified pattern in `src/asset/setup/sites.inc.php.tpl`
comment). CLI: `php Razy.phar serve --dist mysite@beta` (`serve.inc.php` usage).

Breaking API change? Ship a new major **directory**, update consumers, let both coexist —
RZ-012. `php Razy.phar validate <dist>` fails loudly on duplicate `api_name` across vendors
(throws with both module codes, `Distributor/ModuleRegistry.php:166-173`).

## 4. `dist.php` in depth

Full field list as used in the working sample `playground/sites/appdemo/dist.php` and
`src/asset/setup/dist.php.tpl`:

| Key | Meaning |
|---|---|
| `dist` | distributor code |
| `global_module` / `autoload_shared` | pull in `shared/module` vs keep local |
| `greedy` | load every module folder present (demo/playground default) |
| `strict` | strict module resolution (see `standalone`/`pkg` serve strictness) |
| `modules` | tag → `{ module_code => version-tag }`; `'*'` is the default tag |
| `config_mapping` | site-tag → config **folder name** (`'beta' => 'v2'`); lets several site tags share/overlay config folders (readme Routing section; per-domain mapping lives here, not in sites.inc.php) |
| `reserve` | site-level reserved path segments (e.g. `api`) — required for `Agent::reserve()` (`Agent.php:435-444`) |

## 5. Shared vs vendor modules

- **Local/distributor**: `modules/...` or `sites/<dist>/` module folders.
- **Shared**: `shared/module/<vendor>/<module>` — reference, never copy.
- **Vendor (installed)**: `vendor/module/` (e.g. from `php Razy.phar install owner/repo`) —
  never hand-edit (RZ-012 discipline; trust caveats in [06](06-packages-and-deployment.md)).

Per-distributor third-party libs land in `autoload/<dist>/` only — RZ-007; mechanism in
[06](06-packages-and-deployment.md).

## 6. The three sanctioned cross-module surfaces

### 6.1 API commands (request/response)

```php
// provider (module A, __onInit):
$agent->addAPICommand('getThing', 'api/get_thing');           // Agent.php:55
$agent->addAPICommand(['a' => 'api/a', 'b' => 'api/b']);      // batch form, Agent.php:57-62

// consumer (module B):
$data = $this->api('acme/store')->getThing($id);              // Controller.php:510-515
```

> **Closure-path rules** (`Module/ClosureLoader.php:117-130`): registration paths are
> relative to `controller/` and **never include `.php`** — the loader appends it (`:130`).
> A slash-less path resolves to `<moduleClass>.<path>.php` (or a real controller method,
> `:119-123`); a slash-containing path resolves to `controller/<path>.php`. Writing
> `'api/user.php'` resolves to `api/user.php.php` → never loads (strict mode:
> `ModuleLoadException`, `:141-143`).

`api()` returns **`?Emitter` — null when the target module isn't loaded**
(`Controller.php:508-513`); call sites must tolerate null (RZ-009). Emitter methods map to
registered command names via `__call` (`Emitter.php:50`); unknown commands resolve to null.
To probe availability, use `$this->api('acme/store')->has('getThing')` (`Emitter.php:68`) —
**never `method_exists()`**: the Emitter dispatches through `__call`, so `method_exists()` is
always false and a guard built on it silently disables the integration (dossier
MODULE-LIFECYCLE.md L0 found one doing exactly that in production).
Command name grammar: `/^#?[a-z]\w*$/i` (`Agent.php:65-67`). Duplicates throw at
registration (`Module/CommandRegistry.php:61-63`).

**`bind()` vs `addAPICommand()` vs `#`** (RZ-010):

| Register | Visible to `$this->` | Visible to `api()` |
|---|---|---|
| `$agent->bind('helper', 'helpers/x')` (`Agent.php:95`) | ✅ | ❌ |
| `$agent->addAPICommand('getThing', 'api/x')` | ❌ | ✅ |
| `$agent->addAPICommand('#both', 'api/x')` (the `#` = dual-registration, `Module/CommandRegistry.php:55-59`) | ✅ | ✅ |

A released `addAPICommand` is a **contract** — semver it (RZ-012), ship a test (RZ-014).
Peers must never reach into your `api/*.php` files directly (RZ-001/RZ-010).

### 6.2 Events (fire-and-collect)

Verified firing pattern from the working demo
(`demo_modules/core/event_demo/default/controller/event_demo.php:61-71`):

```php
// producer:
$emitter = $this->trigger('order_placed');            // Controller.php:339 — returns EventEmitter
$emitter->resolve($orderData);                        // dispatch to listeners with payload
$responses = $emitter->getAllResponse();              // collect listener return values
```

```php
// consumer, in __onInit (both callable and file forms verified:
//   database_demo/default/controller/database_demo.php:71-80 (callable),
//   Agent.php listen(string, callable) / observe(...) (:166/:200)):
$agent->listen('acme/store:orderPlaced', function (array $payload): array {
    return ['received' => true, 'id' => $payload['id'] ?? null];
});

$agent->listen('core/auth:onLogin', 'listeners/login');   // file form → controller/listeners/login.php
```

`listen()` runs the callback **when triggered**; `observe()` runs it **once and
unsubscribes** (`readme.md` Core Concepts). Event names in the demos are namespaced
`vendor/module:hook` (`demo/demo_index:register_demo`, `core/auth:onLogin`) — adopt that
convention so `--dist` audits stay greppable. No cross-module shared state via event
payloads by reference — send plain values (RZ-008).

⚠️ The readme/RZ-008 snippet `$this->trigger('post:onPublished', ['id' => …])` passes an
array where the signature takes `?callable` (`Controller.php:339`) — use the
`trigger()/resolve()/getAllResponse()` pattern above instead (drift list in
[README](README.md) §Known drift).

### 6.3 Bridge commands (cross-distributor, gated, local IPC)

```php
$agent->addBridgeCommand('getSetting', 'bridge/setting');      // Agent.php:129 / Module.php:505
```

Execution path consults the gate before running
(`Module/CommandRegistry.php:168-176`: `executeBridgeCommand()` → `__onBridgeCall`), but
**the framework default gate allows everything** (`Controller.php:187-190`). Any module that
registers bridge commands must ship its own allow-list (RZ-002):

```php
public function __onBridgeCall(string $sourceDistributor, string $command): bool
{
    $allowed = [
        'clientb@1.0.0' => ['getSetting'],   // exact source string => command allow-list
    ];
    return in_array($command, $allowed[$sourceDistributor] ?? [], true);
}
```

Treat the source string as **unauthenticated**: same-host process transport, no HMAC,
spoofable (see [07 §bridge trust](07-security-guide.md)). Same for `__onAPICall` — default
is open (`Controller.php:173-176`).

## 7. Coordination without shared state

```php
$agent->await('acme/db', function (): void {            // Agent.php:292 — runs once acme/db ready
    // one-shot init against a peer that is guaranteed loaded
});

$threads = $agent->thread();                            // Agent.php:304 — per-module ThreadManager
$thread = $threads->spawn(fn() => long_task());         // process-isolated; 04/07 for rules
$result = $threads->await($thread->getId());
```

(RZ-008: no module-level statics carrying state across requests; RZ-011: never
`spawnPHPCode` with input-derived code — it is deprecated v1.0.3-beta+.)

Sizing the async tool (v1.0.3-beta+): one-shot isolation → `$agent->thread()`
(spawn-per-job, pays PHP boot each time); repeated/boot-heavy compute →
`new \Razy\WorkerPool(size: N)` — persistent workers fed file-based jobs
(`submit`/`submitCode`, in-worker deadlines, at-most-once, `shutdown()` owned by
the creator); durable/retriable work → `QueueManager` over `DatabaseStore` (CLI
`queue work`) or `Queue\RedisQueueStore` (inject a connected `\Redis`, same
family pattern as the Cache/Session Redis drivers).

## 8. DI fence

Module code gets a child DI container; resolving framework internals (`Application`,
`Container`, `Module`, … — a blocklist maintained in `Container.php` `blockedAbstracts`
/`blockAbstracts()` `:135/:185`) throws `SecurityException` (`:167`). That is a fence, not a
bug (RZ-005): use Controller helpers (`getDB`-style wiring, `getModuleConfig()`,
`getDataPath()`) and **your own** bindings.

Next: [03-routing-and-requests.md](03-routing-and-requests.md).
