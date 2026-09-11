# Anti-Pattern Gallery — Razy Golden Rules, ❌ → ✅

Every ❌ snippet below is a pattern the linter (`tools/lint-module-discipline.php`)
flags (or a runtime trap it documents); every ✅ snippet is the shape the
golden demos in [`golden/`](golden/) actually use, verified against
`src/library/Razy/` with file:line. Bad code exists **only inside these fences** —
copy nothing outside them.

Rule IDs are defined in [`../skills/RAZY-AI-RULES.md`](../skills/RAZY-AI-RULES.md).

---

## RZ-001 — Modules never load each other's files

**❌**
```php
// module golden/consumer reaches into the provider's private files
require __DIR__ . '/../../provider/default/controller/api/find_user.php';
$cfg = json_decode(file_get_contents(__DIR__ . '/../../provider/default/config/db.json'));
```

**✅**
```php
// provider publishes the capability (provider's __onInit):
$agent->addAPICommand('findUser', 'api/find_user');

// consumer calls through the contract (consumer.panel.php):
$provider = $this->api('golden/provider');      // ?Emitter — nullable, Controller.php:510
if ($provider === null) { /* degrade explicitly */ }
$result = $provider->findUser($id);
```

**Why:** the file path is not a contract — it silently breaks when the provider
bumps a version tag or moves a file, bypasses `__onAPICall` permissioning, and
is invisible to `validate`/`compose`/`pack`.

---

## RZ-002 — Cross-distributor calls require a gate

The framework default `Controller::__onBridgeCall()` **allows everything**
(verified `src/library/Razy/Controller.php:187-190`). Registering a bridge
command without overriding the gate is an open door between distributors.

**❌**
```php
public function __onInit(Agent $agent): bool
{
    $agent->addBridgeCommand('exportOrders', 'bridge/export_orders'); // gate? never met it
    return true;
}
```

**✅**
```php
private const BRIDGE_ALLOW = [
    'client_a@1.0.0' => ['exportOrders' => true],   // explicit peer => commands map
];

public function __onBridgeCall(string $sourceDistributor, string $command): bool
{
    // Gate shape verified against the real signature: Controller.php:187
    return isset(self::BRIDGE_ALLOW[$sourceDistributor][$command]);
}
```

**Why:** the source identifier arrives unauthenticated — allow-list by
*distributor + command*, deny by default, review the list like a firewall rule
set.

---

## RZ-003 — SQL discipline & no input superglobals

There is **no true bound-parameter API**: values travel as quoted literals, so
every value must enter through `assign()` — and the `getSearchTextSyntax()`
helper breaks on text containing quotes (`O'Brien`). Never hand a raw superglobal
into either.

**❌**
```php
$id = $_GET['id'];                                    // warn: raw superglobal
$db->query('SELECT * FROM users WHERE id = ' . $id);  // error: interpolated SQL
$db->prepare('SELECT * FROM users WHERE id = :id');   // error: quote-argument prepare()
$db->prepare()->select()->where('id=' . $id);         // error: string-built condition
$st->getSearchTextSyntax($keyword);                   // landmine: quote in input = break-out
```

**✅**
```php
// route args, not superglobals (Controller.php:498 + RouteDispatcher routedInfo)
$id = (int) ($this->getRoutedInfo()['arguments']['id'] ?? 0);

// columns declared in insert(), values ONLY via assign() (rules pack Appendix D):
$this->getDB()->prepare()->insert('log', ['msg', 'created'])
    ->assign(['msg' => $message, 'created' => date('Y-m-d H:i:s')])
    ->query();

// reads use parameterized conditions, never concatenation:
$this->getDB()->prepare()->select('users', ['id', 'name'])
    ->where('id=:id')
    ->assign(['id' => $id])
    ->query();
```

**Why:** the quote-the-value pipeline is correct only if nothing attacker-shaped
ever bypasses it — `assign()` is the only lane. (The golden provider keeps even
simpler: typed closure parameters + static data; see
`golden/provider/default/controller/api/find_user.php`.)

---

## RZ-004 — Raw `{$var}` of user data is unescaped output

The template engine does **not auto-escape** — `Entity::parseText()` returns the
value raw (verified `src/library/Razy/Template/Entity.php:388-399`). Since v1.0.3-beta
the framework ships an `->escape` modifier — use it; before that, the gap was the root
cause of the legacy demos' 97 raw-output warnings.

**❌**
```html
<!-- view/comment.tpl -->
<div class="comment">{%comment.body}</div>   <!-- raw user data straight to HTML -->
```
```php
echo '<h1>' . $user['name'] . '</h1>';       // same thing from a controller
```

**✅**
```php
// escape at the HTML boundary, in the controller, before handing data to the view:
$safeName = htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8');
// or build markup with Razy\DOM — text nodes/attributes escaped by construction
// (verified src/library/Razy/DOM.php:84-140).
```
```html
<!-- view/comment.tpl — only receives already-escaped values -->
<div class="comment">{%comment.bodySafe}</div>
```

**Why:** escaping is a boundary duty the engine will not do for you — a JSON
response keeps values raw (`responseAsBody`), an HTML response escapes first.

---

## RZ-005 — No DI fence-climbing

The DI fence is real: a 14-class blocklist + parent-traversal block (verified
`src/library/Razy/Module.php:1139-1154`, `src/library/Razy/Container.php:164-173`)
throws `SecurityException` — a *designed* fence, not a bug to route around.

**❌**
```php
$app  = $this->container->make(\Razy\Application::class);   // SecurityException
$db   = $this->container->get('Razy\Database');             // reaches internals
$c    = $this->container->getParent()->make('Razy\Container');
```

**✅**
```php
// controller helpers are the sanctioned surface (rules pack "Verified API surface"):
$db     = $this->getDB();
$config = $this->getModuleConfig();
// plus YOUR OWN module's bindings — and nothing else.
```

**Why:** reaching framework internals lets module code mutate state the
lifecycle/reset system doesn't know about — breaking worker-mode isolation and
upgrade safety.

---

## RZ-008 — No cross-module shared state

Static properties, singletons, and shared temp files are invisible write-channels
between modules (and across requests in worker mode).

**❌**
```php
// module A
class Cache { public static array $shared = []; }
Cache::$shared['user'] = $user;                 // module B sniffs it later

// or the "temp file bus":
file_put_contents(sys_get_temp_dir() . '/razy_bus.json', json_encode($payload));
```

**✅**
```php
// request/response → API (RZ-001 pattern):
$result = $this->api('golden/provider')->findUser($id);

// broadcast → events, exactly as golden/provider triggers + golden/consumer listens:
$this->trigger('userSeen')->resolve(['user_id' => $id]);            // provider side
$agent->listen('golden/provider:userSeen', function (array $payload = []): array {
    return ['heard_by' => 'golden/consumer'];                        // consumer side
});
```

**Why:** statics survive across requests in persistent workers (the class table
is process-global) — shared state becomes cross-request state leakage. APIs and
events carry data with a *name and a version*; side channels carry neither.

---

## RZ-011 — No eval / shell primitives; never feed derived code to threads

`ThreadManager::spawnPHPCode()` runs `eval(base64_decode(…))` in the child
process (verified `src/library/Razy/ThreadManager.php:141-156` — a documented,
dangerous convenience). Code must never be built from input anywhere near it.

**❌**
```php
$thread = $tm->spawnPHPCode('var_export(' . $userInput . ', true);');  // RCE: input → code
eval(base64_decode($payloadFromQueue));
shell_exec('convert ' . $uploadedFile . ' out.png');                   // shell injection bait
```

**✅**
```php
// 1. Prefer plain PHP callables in a thread — no child eval at all:
$thread = $tm->thread($task);                 // thread(callable) per the verified Agent surface
$result = $tm->await($thread->getId());

// 2. When a child process is genuinely required, ship FIXED code as a FILE
//    (0600 perms, random name, atomic rename — see ThreadManager::spawnPHPFile,
//    verified src/library/Razy/ThreadManager.php:174-199) and pass data as ARGUMENTS:
$thread = $tm->spawnPHPFile($fixedTaskCode);   // code is constant; input is data
```

**Why:** code built from input *is* input running with your privileges;
callables and file-spawn + arguments keep the code/data channel separation the
security review assumes.

---

## Lint status of this file

By design this gallery contains no scannable `.php` files — snippets live in
markdown fences only. The enforcement bar for `demos/`:

```bash
php tools/lint-module-discipline.php demos --format=json   # errors: 0, warnings: 0
```
