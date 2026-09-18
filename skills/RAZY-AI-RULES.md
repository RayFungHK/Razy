# RAZY-AI-RULES — The Razy Architecture Discipline Rule Pack

**Version:** 1.0 (2026-07) · **Applies to:** every AI/LLM agent writing or reviewing
code in a Razy project (framework, distributors, modules, packages).
**Enforcement:** `tools/lint-module-discipline.php` (greppable rules) + `composer quality`
+ human review. Rule IDs are stable; quote them in PRs and re-prompts.

## How to use this pack (agents)

1. Read this file **before** generating any module code.
2. When proposing code, self-check each rule's *Self-check* grep first.
3. After finishing, run: `php tools/lint-module-discipline.php <changed-dirs>` and
   `composer quality`. Report both results.
4. Where a rule conflicts with a doc example you found, **this pack and the code win**
   (legacy docs contain drift, e.g. the old README's `|`-modifier syntax is wrong).
5. Suppression: `// lint-allow: RZ-003` on the offending line — requires a one-line
   justification comment above and human sign-off. Never mass-suppress.

**Verified API surface (2026-07):** `Razy\Agent` — `addAPICommand, bind, addBridgeCommand,
listen, observe, addRoute, addLazyRoute, addShadowRoute, addScript, middleware, group,
reserve, await, thread` (verified `src/library/Razy/Agent.php`). `Razy\Controller` —
`api, view, loadTemplate, xhr, trigger, getDB*, getDataPath, getAssetPath, getModuleConfig,
getModuleInfo, getRoutedInfo, container, resolve, hasService, loadModel, fork, up, down`
(verified `src/library/Razy/Controller.php`; `*` helper used in demos
`demo_modules/data/database_demo/.../select.php`). Lifecycle hooks and gates on
`Controller`: `__onInit/__onLoad/__onRequire/__onReady/__onScriptReady/__onRouted/
__onEntry/__onDispatch/__onAPICall/__onBridgeCall/__onError/__onDispose/__onTouch`.
`$this->xhr()` **returns an XHR object** (factory) — chain it:
`$this->xhr()->responseAsBody($array)` / `->sendData($success, $msg)` / `->sendEnvelope()`
(verified `src/library/Razy/XHR.php`). `$this->getRoutedInfo()` returns
`url_query, base_url, route, module, closure_path, arguments, type, method, is_shadow`
(verified `Distributor/RouteDispatcher.php` routedInfo literal).

---

## RZ-001 — Modules never load each other's files (error)

**Statement.** No `require`/`include`/`require_once`/`include_once`, nor
`file_get_contents`, that targets another module's directory, a distributor's
`dist.php`, or anything under `sites/*/vendor/module/` outside your own module.

❌ **Wrong**
```php
// inside module acme/shop/controller/cart.php
require __DIR__ . '/../../auth/default/helpers/session.php';   // reach into another module
$cfg = json_decode(file_get_contents(__DIR__.'/../../../auth/default/config/jwt.json'));
```

✅ **Right**
```php
// auth module publishes the capability (its __onInit):
$agent->addAPICommand('currentSession', 'api/current_session');   // no .php suffix (loader appends)

// shop module consumes it:
$session = $this->api('acme/auth')->currentSession();
```

**Why.** Module boundaries are the versioning/shipping boundary. A cross-module
`require` silently breaks when the other module upgrades a version tag, moves a file,
or isn't installed in this distributor — and it bypasses `__onAPICall` permissioning
entirely. The framework cannot see the dependency, so `validate`/`compose`/`pack` can't
either.

**Self-check.** `grep -rnE "(require|include)(_once)?\s*\(?\s*['\"][^'\"]*(\.\./|sites/|autoload/|shared/module)" <dir>`

---

## RZ-002 — Cross-distributor calls require a gate (error)

**Statement.** The framework default `Controller::__onBridgeCall()` **allows everything**.
Any module that registers bridge commands MUST override the gate with an explicit
allow-list. Never bridge into another distributor's files (that's RZ-001 with extra steps).

❌ **Wrong**
```php
public function __onInit(Agent $agent): bool
{
    $agent->addBridgeCommand('exportOrders', 'bridge/export_orders');
    return true;   // gate missing → any distributor can call it, source ID is spoofable
}
```

✅ **Right**
```php
private const BRIDGE_ALLOW = [
    'billing/statement' => ['exportOrders'],   // caller module code => commands it may run
];

public function __onBridgeCall(string $sourceDistributor, string $command): bool
{
    $allowed = self::BRIDGE_ALLOW[$sourceDistributor] ?? [];
    return in_array($command, $allowed, true);
}
```

**Why.** Bridge payload historically carried an **unauthenticated** source-distributor
string. Since this hardening round the framework ships `Razy\BridgeSignature`: with
`RAZY_BRIDGE_SECRET` set, `Module::executeBridgeCommand` denies any call lacking a valid
HMAC envelope (canonical bind of source+module+command+args+ts+nonce, ±60s window). The
sender signs with `BridgeSignature::signedPayload($secret, $source, $module, $cmd, $args)`.
The gate is still mandatory: HMAC proves the payload was produced by a secret-holder; your
`__onBridgeCall` allow-list decides what that identity may run. The CLI `bridge` command
remains unauthenticated local IPC (shell access = operator trust).

**Self-check.** For every `addBridgeCommand` in your module: confirm a matching
`__onBridgeCall` override exists in the same module's main controller.

---

## RZ-003 — SQL discipline (error)

**Statement.** All values flow through named parameters + `assign()`. No `new PDO`, no
raw `->prepare('SELECT …')` string from `getDB()`, no condition strings assembled from
input, no `getSearchTextSyntax($rawUserText)` (known landmine: quotes in input break the
generated syntax). Never use `Database::prepare(string)` raw passthrough in modules.

❌ **Wrong**
```php
$db = $this->getDB();
$rows = $db->prepare("SELECT * FROM posts WHERE title LIKE '%{$_GET['q']}%'")->query();
$stmt = $db->prepare()->select('*')->from('posts')
    ->where($db->prepare()->getSearchTextSyntax('title', $userQuery));  // landmine
$pdo = new PDO('mysql:host=…');  // bypasses drivers, pooling, per-dist config
```

✅ **Right**
```php
$db = $this->getDB();
$stmt = $db->prepare()
    ->select('id, title, created_at')
    ->from('posts')
    ->where('title~=:q,active=1')              // ~ = LIKE-style op per Simple Syntax
    ->assign(['q' => '%' . str_replace(['%','_'], ['\%','\_'], $keyword) . '%'])
    ->order('>created_at')
    ->limit(20);
$rows = $stmt->query();

// INSERT with values via assign only (pattern verified against demo_modules/data/database_demo):
$db->prepare()->insert('log', ['msg', 'created'])
   ->assign(['msg' => $message, 'created' => date('Y-m-d H:i:s')])
   ->query();                                  // $query->lastID() afterwards
```

Superglobals: prefer `$this->getRoutedInfo()['arguments']`; a cast+validated `$_GET`/
`$_POST` read is tolerated (lint warning) but **never** flows into a condition string.

**Why.** Razy's SQL layer renders final SQL with quoted literals rather than native
bound parameters (audited `Database.php:306-327`); safety depends on every value
entering via `assign()` so the quoting path is the one that's tested. Raw interpolation
skips that path entirely. Search text with quotes (`O'Brien`) breaks
`getSearchTextSyntax` output.

**Self-check.**
`grep -rnE "new PDO|->prepare\(\s*['\"]|getSearchTextSyntax\(\s*[^'\"]|\$_(GET|POST|REQUEST)" <dir>`

---

## RZ-004 — Templates do NOT auto-escape (error)

**Statement.** `{$var}` renders **raw**. Before the core ships an `escape` modifier,
any value that can contain user input must be HTML-escaped before it reaches the
template — in the controller, via the DOM builder, or via the adopted escape patch.

❌ **Wrong** — `templates/comment.tpl`
```html
<li>{$comment.body</li>   <!-- stored XSS if body = <script>… -->
```

✅ **Right** — controller escapes, template renders trusted fragments
```php
$view->assign('comments', array_map(
    fn($c) => [...$c, 'body' => htmlspecialchars($c['body'], ENT_QUOTES, 'UTF-8')],
    $comments
));
```

✅ **Right** — use the built-in `->escape` modifier (shipped since v1.0.3-beta as
`src/plugins/Template/modifier.escape.php`): `templates/comment.tpl` writes
`{$comment.body->escape}` (chains work: `{$v->trim->escape}`). On older installs, drop
in the Appendix C patch file first.

✅ **Right** — build dynamic HTML with the DOM builder (`Razy\DOM`): text nodes and
attributes are `htmlspecialchars(ENT_QUOTES)`-escaped by construction
(`src/library/Razy/DOM.php:84-140`).

**Why.** Audited: no escaping anywhere in `Template/` — the engine renders `{$var}` raw.
The 2026-07 audit found zero escape-capable modifiers among the 9 built-ins, which is
why `->escape` was shipped upstream in v1.0.3-beta. The rule still applies: no
auto-escaping means every unescaped dynamic value is a latent XSS — the modifier is
opt-in per output site.

**Self-check.** `grep -rnE "\{\$[a-zA-Z_][^}]*\}" <tpl-dir>` then classify each: is the
variable provably trusted (static config)? Otherwise escape per above.

---

## RZ-005 — DI fences are load-bearing (error)

**Statement.** `SecurityException` from the container is an architecture boundary, not a
bug to route around. Never attempt to resolve `Razy\Application|Container|Standalone|
Domain|Distributor|Module|Controller|ModuleRegistry|ModuleScanner|RouteDispatcher|
PrerequisiteResolver|MiddlewarePipeline|MiddlewareGroupRegistry|PluginManager` from a
module; never use raw `$_SERVER`-adjacent global plumbing to find "another way" to them.

❌ **Wrong**
```php
$app = $this->resolve('Razy\\Application');        // blocked
$mods = Container::getInstance()->make('Distributor'); // blocked
```

✅ **Right.** Use documented Controller helpers (`api()`, `trigger()`, `getModuleConfig()`,
`loadModel()`, …). If a helper seems missing — that's a question for a human, not an
exploit to invent.

**Why.** In worker mode every distributor shares one process; the blocklist (14 classes,
audited `Module.php:1139-1154`) is the in-process guard keeping modules from rewriting
each other's runtime.

---

## RZ-006 — File discipline: stay in your sandbox (error)

**Statement.** All writes root under `$this->getDataPath()` (per-dist/module data dir) or
`$this->getAssetPath()` (public assets). No absolute paths, no `../`, no writes to
`config/`, `autoload/`, other modules' dirs, or system temp as shared state.

✅ **Right**
```php
$file = $this->getDataPath('exports/') . $safe_name . '.json';   // getDataPath scopes per dist+module
file_put_contents($file, json_encode($data));   // $safe_name = preg_replace('/[^A-Za-z0-9._-]/', '', $name);
```

**Why.** Data paths are the isolation boundary today (`{domain}-{distCode}/{module}`);
step outside and you've silently created cross-tenant coupling.

---

## RZ-007 — Dependency manifests, not hand-edits (error)

**Statement.** Dependencies are declared, never installed by hand: module deps in
`package.php` → `require`, Composer deps → `prerequisite`. `autoload/` and
`autoload/lock.json` are **generated** — treat them like `node_modules`.

❌ **Wrong:** hand-copying a vendor lib into `autoload/mysite/`; editing `lock.json`;
committing a patched class inside `autoload/`.
✅ **Right:**
```php
return [ 'module_code' => 'acme/shop', 'version' => '2.1.0', 'api_name' => 'shop',
    'require' => ['acme/auth' => '>=1.0.0'], 'prerequisite' => ['monolog/monolog' => '^3.0'] ];
```
then `php Razy.phar compose <dist>`.

---

## RZ-008 — No cross-module shared state (error)

**Statement.** No static properties/singleton registries written by one module and read
by another; no shared files under system temp; no `global $x` (also see RZ-001 for the
`src/system` bootstrap globals — those are framework-owned, modules must not add to them).
Use events for broadcast, APIs for request/response, per-distributor config for data.

✅ **Right**
```php
// producer (module acme/post): trigger() takes the BARE event name — the framework
// qualifies it with the emitter's module code (verified EventEmitter.php:72-73) — and
// returns an EventEmitter; the payload travels via resolve(), NOT a second array arg
// (verified Controller.php:339: trigger(string $event, ?callable $callback = null)).
$this->trigger('published')->resolve(['id' => $id]);

// consumer (notify module, its __onInit): listen by the QUALIFIED name
// (verified Agent.php:177-181): '<provider/code>:<bare-event>'
$agent->listen('acme/post:published', 'onPostPublished');
```

---

## RZ-009 — Lifecycle honesty (error)

**Statement.** `__onInit` = registration only (routes/APIs/bindings/listeners; must
`return true`). No DB/file/network IO, no peer API calls (peers aren't loaded). Peer
calls belong in `__onReady`+. Request-scoped work in `__onRouted`/`__onEntry`. Every
lifecycle hook returning `bool` must return a meaningful value — `false` aborts and
frameworks depends on that. In worker mode, do not accumulate per-request state on
module-level properties (it leaks across requests; audited reset list in
`Module::resetForWorker` does not cover your statics).

**Machine-check status (lint v2026-09).** `php tools/lint-module-discipline.php` now
enforces the *direct path* of `__onInit` (token-level scan): peer `api()`/`bridge()`,
`getDB()`/`getMigrationManager()`, file/network IO, and thread/child spawns → **error**.
Policy semantics the scan encodes: (1) bodies of deferred callbacks — `listen()`,
`addAPICommand()`, route closures — are NOT init's direct path; acting inside them is
the sanctioned shape and stays clean. (2) `await(...)` registration is sanctioned and
reports **warn only** (review its callback body by eye; `BootCompiler` refuses impure
await callbacks at compile time — deploy-time backstop). (3) A constant `return false`
arm proves the abort path; a missing return or never-true/never-false tail → **warn**.

---

## RZ-010 — API vs binding, and contracts are real (error)

**Statement.** `addAPICommand('name', …)` is **public to all modules** (gate with
`__onAPICall`). `$agent->bind('name', …)` is private sugar for the module itself. `#`
prefix = both. Don't expose internals because it's convenient; a released API/event is
a versioned contract (see RZ-012). Duplicates throw at boot
(`CommandRegistry.php:61-63` audited) — never "fix" by renaming to something vague.

---

## RZ-011 — No dynamic code / shells (error)

**Statement.** `eval`, `create_function`, `exec`, `shell_exec`, `passthru`,
`proc_open`, backticks — forbidden in module code without exception. In framework/tool
code: CLI process management only with `escapeshellarg`/array-form `proc_open`;
`ThreadManager::spawnPHPCode()` must never receive input-derived code (child runs
`eval(base64_decode())` — audited `ThreadManager.php:152-153`); prefer `spawnPHPFile()`
— it is **deprecated** as of v1.0.3-beta. For repeated or boot-heavy background work,
use `Razy\WorkerPool` (`submit`/`submitCode` ship *file-based* jobs to persistent
workers, `@see` the class doc; jobs are deadline-bounded in-worker via `set_time_limit`,
at-most-once delivery, and it needs no posix/pcntl).
(0600, atomic rename) or plain callables.

✅ **Right**
```php
$t = $this->thread()->thread('export', function () use ($rows) {   // callable, no shell
    return ['ok' => true, 'bytes' => strlen(json_encode($rows))];
});
$result = $this->thread()->await($t->getId());
```

---

## RZ-012 — Version discipline on released surfaces (error)

**Statement.** Changes to a published API command, event name/payload, or route pattern
in a released module require a version bump in `module.php` + `package.php` (semver);
breaking changes ship as a new major version directory and distributors adopt per-tag.
Never mutate a released version in place — every distributor consuming it will silently
change behavior.

---

## RZ-013 — Routes only through Agent (error)

**Statement.** All routing via `addLazyRoute/addRoute/group/reserve`; generated
`.htaccess`/Caddyfile output is machine-owned — regenerate with `php Razy.phar rewrite
<dist>`, never hand-edit. Shadow a route into another module's namespace only via
`addShadowRoute` (which the framework tracks; a hand-edited rewrite file does not exist
to the framework's validators/packager).

---

## RZ-014 — Tests and quality gates (error)

**Statement.** Every published API command gets a test (mock the caller module code);
route handlers get at least a happy-path + validation test. CI gates are the floor:
PSR-12 via php-cs-fixer (config `.php-cs-fixer.dist.php` only — do not create a local
`.php-cs-fixer.php` override to silence rules), PHPStan level 5 (do not add
`ignoreErrors` entries), `composer test` green. The 50% coverage gate may not be
weakened.

## RZ-015 — Framework core has zero third-party runtime dependencies (error)

**Statement.** `src/` (the framework phar) may not depend on any third-party runtime
package — no Guzzle, no league/*, no symfony/* beyond their PSR *contracts*, forever.
Interop with the wider PHP world lives in modules or standalone packages
(`PackageRunner:93-145`), which carry their own `require` manifests (RZ-007); PSR-18
HTTP and PSR-7 message consumers belong there, not in core. When a primitive looks
missing ("just add Guzzle"), the correct move is to harden the in-house equivalent
(HttpClient, OAuth2, …) or to ship the dependency inside a module/package — never to
import it into core. Decided 2026-09-17 (OAuth dossier Q4) because the absence of
this rule made "just add Guzzle" look cheap. Human rule (the lint tool scans module
code, not `src/`); enforced in review + CI diff guards on `composer.json` `require`.

## RZ-016 — Migrations run only at the deploy door (error)

**Statement.** Module code must not reach the migration manager (`Controller::getMigrationManager()`)
from web-triggerable paths. Schema moves at exactly two doors: `php Razy.phar migrate <dist>`
(deploy) and, when a module declares `'provision' => 'wizard'` (dossier MODULE-LIFECYCLE.md, L1+),
the framework's token-gated wizard runner — never an ad-hoc `install_action` handler or a
`__onReady` auto-migrate. The ERP audit found live violations of exactly this shape (core auto-migrating
during web boot; a `getMigrationManager` API command as a public migration door). Lint:
`RZ-016` flags `getMigrationManager()` in module code; the legitimate CLI-command exemption needs
`// lint-allow: RZ-016` with a written justification.

## RZ-017 — No cross-module class imports (error)

**Statement.** A module's code may not `use` or FQCN-reference another module's namespaces
(`use erp\user\UserIdRemapHelper;`, `\erp\group\PermissionResolver::…`). Module boundaries are
the same legal entities RZ-001 protects at the file level — class-level coupling is the same
violation through the autoloader's back door, and the ERP audit measured 74 live hits while the
old RZ-001 regex (require/include only) saw zero of them. Cross-module capability travels through
`addAPICommand` + `$this->api('vendor/mod')` or events; to probe whether a peer exposes a command,
use `$this->api('vendor/mod')->has('command')` — never `method_exists()` on an API object (the Emitter
is `__call` magic; a `method_exists` guard is permanently false and silently kills the integration).
Lint: `RZ-017` is structural — it pre-scans `module.php` manifests in the same command's paths and
flags imports resolving to a sibling module's `vendor/module` namespace.

---

## Appendix A — Agent pre-PR checklist

```
[ ] Read skills/RAZY-AI-RULES.md for the surfaces I touched
[ ] All cross-module touchpoints are API/Event/bridges-with-gates
[ ] All SQL values via assign()/named params; no raw strings
[ ] All template values escaped (or provably static)
[ ] New bridge commands gated in __onBridgeCall
[ ] New APIs gated in __onAPICall where sensitive; tested
[ ] package.php versions bumped if any released surface changed
[ ] php tools/lint-module-discipline.php <dirs> --format=json  → 0 errors
[ ] composer quality → green (no new baseline warnings)
[ ] php Razy.phar validate <dist> → passes
[ ] Justified `lint-allow` uses listed in PR description with rule IDs
```

## Appendix B — Machine rule registry (consumed by the lint tool)

| Rule | Detector (regex, PHP files unless noted) | Level |
|------|------------------------------------------|-------|
| RZ-001 | `(require|include)(_once)?\s*\(?\s*['"][^'"]*(\.\./|sites[/\\]|autoload[/\\]|shared[/\\]module)`; `file_get_contents` to `sites/\*` or `shared/module` | error |
| RZ-003 | `new PDO`, `->prepare\(\s*['"]`, `getSearchTextSyntax\(\s*[^'")]`, raw `->query('SELECT…')` | error |
| RZ-003 | `\$_(GET\|POST\|REQUEST\|COOKIE)` in module code | warn (cast/validate; never into SQL) |
| RZ-004 | Template files (`*.tpl`,`*.html` under `templates/`): `{$var}` with no `->` and no `lint-allow` | warn (review) |
| RZ-004 | Template: legacy `{$var|modifier}` pipe (silent no-op under fallback semantics) | warn |
| RZ-003/011 | Scope `any`: raw-SQL / dynamic-code call syntax also scanned **inside `.tpl`** teaching snippets | error |
| RZ-005 | `(make|resolve|get)\(\s*['"]\\?Razy\\+(Application|Container|Standalone|Domain|Distributor|Module|Controller|PluginManager)` | error |
| RZ-006 | write/`file_get_contents` with absolute path or `'\.\.'` | error |
| RZ-007 | writes touching `lock.json` / paths under `autoload/` | error |
| RZ-011 | `\beval\s*\(|\b(exec|shell_exec|passthru|proc_open|create_function)\s*\(|` backtick `` `\s*[^`]+\s*`\s*; `` | error |
| RZ-002 | per-module heuristic: `addBridgeCommand` present without `__onBridgeCall` | error |
| RZ-008 | static-property writes to `\Razy\*` classes / `global\s+\$` | error |
| RZ-013 | module code writing to `.htaccess`/`Caddyfile` paths | error |

Suppression syntax: `// lint-allow: RZ-003` on the same or previous line.
Scanner notes: comments/strings/docblocks ARE scanned (teaching text counts as surface
an AI will copy) and there is no negation awareness — when documenting a forbidden
pattern, phrase it without literal call syntax (e.g. write "spawn-PHP-Code with a
variable" not `spawnPHPCode($var)`), or carry a justified `lint-allow`.

## Appendix C — Escape modifier reference (SHIPPED upstream in v1.0.3-beta)

**v1.0.3-beta+ installs: nothing to do** — `src/plugins/Template/modifier.escape.php`
is built in (with `ENT_SUBSTITUTE` and non-scalar guards beyond this minimal
reference). Use `{$value->escape}` directly. On **older** installs, drop the file below
into `src/plugins/Template/modifier.escape.php` (same factory format as the built-ins,
verified pattern from `modifier.addslashes.php`) or into your project plugin path per
`Block::loadPlugin` resolution:

```php
<?php
/**
 * Template Modifier Plugin: escape
 * HTML-escapes values for safe output (ENT_QUOTES, UTF-8).
 * Usage: {$variable->escape}
 */
use Razy\Template\Plugin\TModifier;

return function (...$arguments) {
    return new class(...$arguments) extends TModifier {
        protected function process(mixed $value, string ...$args): string
        {
            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        }
    };
};
```

*(The upstream file additionally substitutes invalid UTF-8 (`ENT_SUBSTITUTE`) and
renders arrays/non-`__toString` objects as `''`; see
`tests/TemplateModifierEscapeTest.php` for the behavioural contract.)*

## Appendix D — Correct-pattern quick reference

```php
// module main controller (controller/<moduleClass>.php returns):
use Razy\Agent; use Razy\Controller;
return new class extends Controller {
    public function __onInit(Agent $agent): bool {
        $agent->addLazyRoute(['page' => 'page']);            // → controller/<moduleClass>.page.php
        $agent->addAPICommand('getThing', 'api/get_thing');  // NO .php suffix — loader appends (ClosureLoader.php:130)
        $agent->bind('privateHelper', 'helpers/thing');
        $agent->listen('core/auth:onLogin', 'onUserLogin');
        return true;
    }
    // gate receives the CALLER's ModuleInfo (verified Controller.php:173):
    public function __onAPICall(\Razy\ModuleInfo $fromModule, string $method, string $fqdn = ''): bool {
        return $method === 'getThing';
    }
};

// route handler file (controller/<moduleClass>.page.php):
use Razy\Controller;
return function (): void {
    /** @var Controller $this */
    $id = (int) ($this->getRoutedInfo()['arguments']['id'] ?? 0);
    $this->xhr()->responseAsBody(['data' => $this->api('vendor/store')->getProduct($id)]);
};

// DB write — values only via assign (columns declared in insert()):
$this->getDB()->prepare()->insert('log', ['msg', 'created'])
    ->assign(['msg' => $message, 'created' => date('Y-m-d H:i:s')])
    ->query();
```

**Verified closure-path facts (demos agent forensics, 2026-07):** closure paths are
relative to `controller/`, never include `.php` (`ClosureLoader.php:130`), and
slash-less paths get the module class-name prefix (`ClosureLoader.php:126`) — a bare
`users.php` handler is never loaded; it must be `<moduleClass>.users.php`.
`__onAPICall` receives the **requesting** module's `ModuleInfo` (`Razy\ModuleInfo`,
`Controller.php:173`); `__onBridgeCall` receives `(string $sourceDistributor, string
$command)` (`Controller.php:187`). `api()` returns `?Emitter` — null-check before
calling (`Controller.php:510`).

## Appendix E — Plugin & extension governance

Verified surfaces an agent may LEGITIMATELY extend (no rule violations needed):

1. **Template/Collection/Pipeline/Statement plugins** — factory closure returning a
   `TModifier`/`TFunction`/… subclass; file name IS the plugin id
   (`modifier.<name>.php`). Ship under `<module>/plugins/Template/`, register in
   `__onInit` via `$this->registerPluginLoader(Controller::PLUGIN_TEMPLATE)`
   (`Controller.php:693`). Module path only — RZ-001/006 clean.
   - Core plugins **cannot be shadowed**: core folders register at bootstrap
     (`bootstrap.inc.php:155-165`), lookup is first-folder-wins
     (`PluginManager::getPlugin:113-131`). Don't "override" core plugins — pick a new name.
   - Modifier args arrive via `TModifier::modify()` ':a:b' parsing (word/number/quoted,
     quotes stripped). Pre-2026-07 this parser shipped broken (all args empty) — if you
     see a modifier "ignoring" its parameters on an older install, that is why
     (`tests/TModifierParamParsingTest.php` pins the fixed contract).
   - Function parameters (`{@name key=$var}`) accept only `$vars`, numbers, `true/false`
     and QUOTED strings; a value containing `=` truncates at the first `=`
     (`TFunction::parse` named-split) — pass URLs with query strings via a `$variable`.
2. **Validation rules** — `extends ValidationRule`, implement `validate()`
   (return transformed value; `$this->fail()` to reject) + `defaultMessage()`
   (interpolates `:field`); attach with `Validator::field($f)->rule(new YourRule())`,
   `withMessage()` overrides per instance. Reference: `tests/ValidationCustomRuleTest.php`.
3. **Translation dictionaries** — `Translator::addNamespace('vendor/mod', <module>/lang)`;
   keys `vendor/mod::group.key`; never read another module's lang files (RZ-001).

NOT sanctioned shortcuts: editing `src/plugins/` to "add a project modifier"
(framework fork — module plugin instead), hand-rolling template output for tokens
(`{$token->escape}` from the controller), or weakening engine regexes to accept your
syntax. New built-ins belong upstream via PR with contract tests (RZ-014).
