# Infrastructure Test Case Reference

---

## 1. Cache (Static Facade, PSR-16)

### Cache Initialization & Adapter Management
**Description:** The `Cache` facade provides a static interface to a PSR-16 cache adapter. It auto-initializes with `FileAdapter` by default when given a directory, falls back to `NullAdapter` when no directory or adapter is provided, and supports adapter swapping at runtime.

#### Test Case 1: Initialize with FileAdapter
```php
use Razy\Cache;
use Razy\Cache\FileAdapter;

$cacheDir = sys_get_temp_dir() . '/razy_cache_test_' . uniqid();

Cache::reset();
Cache::initialize($cacheDir);

assert(Cache::isInitialized() === true);
assert(Cache::isEnabled() === true);
assert(Cache::getAdapter() instanceof FileAdapter);
```
**Expected Result:** Cache is initialized, enabled, and the active adapter is a `FileAdapter` pointed at the given directory.

#### Test Case 2: Initialize with NullAdapter (no directory)
```php
use Razy\Cache;
use Razy\Cache\NullAdapter;

Cache::reset();
Cache::initialize();

assert(Cache::isInitialized() === true);
assert(Cache::getAdapter() instanceof NullAdapter);
```
**Expected Result:** When initialized without a directory, the cache uses `NullAdapter` — all reads return defaults and writes are no-ops.

#### Test Case 3: Swap adapter at runtime
```php
use Razy\Cache;
use Razy\Cache\NullAdapter;
use Razy\Cache\FileAdapter;

$cacheDir = sys_get_temp_dir() . '/razy_cache_test_' . uniqid();

Cache::reset();
Cache::initialize($cacheDir);
assert(Cache::getAdapter() instanceof FileAdapter);

Cache::setAdapter(new NullAdapter());
assert(Cache::getAdapter() instanceof NullAdapter);
```
**Expected Result:** `setAdapter()` replaces the active adapter. Subsequent operations use the new adapter.

---

### Basic CRUD Operations
**Description:** `get()`, `set()`, `delete()`, `has()`, and `clear()` perform standard cache operations. When the cache is disabled or uninitialized, operations degrade gracefully (return defaults, return `false`).

#### Test Case 4: set / get / has / delete
```php
use Razy\Cache;

$cacheDir = sys_get_temp_dir() . '/razy_cache_test_' . uniqid();
Cache::reset();
Cache::initialize($cacheDir);

// Set and get
Cache::set('user.name', 'Alice');
assert(Cache::get('user.name') === 'Alice');
assert(Cache::has('user.name') === true);

// Delete
Cache::delete('user.name');
assert(Cache::has('user.name') === false);
assert(Cache::get('user.name') === null);
assert(Cache::get('user.name', 'default') === 'default');
```
**Expected Result:** Values are stored and retrieved correctly. After deletion, `has()` returns `false` and `get()` returns the default.

#### Test Case 5: clear all entries
```php
use Razy\Cache;

$cacheDir = sys_get_temp_dir() . '/razy_cache_test_' . uniqid();
Cache::reset();
Cache::initialize($cacheDir);

Cache::set('key1', 'value1');
Cache::set('key2', 'value2');
assert(Cache::has('key1') === true);

Cache::clear();
assert(Cache::has('key1') === false);
assert(Cache::has('key2') === false);
```
**Expected Result:** `clear()` removes all cached entries.

#### Test Case 6: Disabled cache returns defaults
```php
use Razy\Cache;

$cacheDir = sys_get_temp_dir() . '/razy_cache_test_' . uniqid();
Cache::reset();
Cache::initialize($cacheDir);
Cache::set('key', 'value');

Cache::setEnabled(false);
assert(Cache::isEnabled() === false);
assert(Cache::get('key') === null);
assert(Cache::set('newkey', 'data') === false);
assert(Cache::has('key') === false);
```
**Expected Result:** When disabled, get returns `null` (or default), set returns `false`, has returns `false`.

---

### Multiple Key Operations
**Description:** `getMultiple()`, `setMultiple()`, and `deleteMultiple()` operate on batches of keys following PSR-16 semantics.

#### Test Case 7: setMultiple / getMultiple / deleteMultiple
```php
use Razy\Cache;

$cacheDir = sys_get_temp_dir() . '/razy_cache_test_' . uniqid();
Cache::reset();
Cache::initialize($cacheDir);

Cache::setMultiple(['a' => 1, 'b' => 2, 'c' => 3]);
$result = Cache::getMultiple(['a', 'b', 'c', 'd'], 'miss');

assert($result['a'] === 1);
assert($result['b'] === 2);
assert($result['c'] === 3);
assert($result['d'] === 'miss');

Cache::deleteMultiple(['a', 'c']);
assert(Cache::has('a') === false);
assert(Cache::has('b') === true);
assert(Cache::has('c') === false);
```
**Expected Result:** Batch operations work on all specified keys. Missing keys return the default value.

---

### Validated Cache (File mtime)
**Description:** `getValidated()` and `setValidated()` pair together to cache data with file modification time tracking. If the source file changes, the cache entry is automatically invalidated.

#### Test Case 8: setValidated / getValidated
```php
use Razy\Cache;

$cacheDir = sys_get_temp_dir() . '/razy_cache_test_' . uniqid();
$testFile = sys_get_temp_dir() . '/razy_validated_test_' . uniqid() . '.json';

file_put_contents($testFile, '{"key":"value"}');
Cache::reset();
Cache::initialize($cacheDir);

// Store with validation
Cache::setValidated('config.app', $testFile, ['key' => 'value']);
assert(Cache::getValidated('config.app', $testFile) === ['key' => 'value']);

// Modify the file — cache should be invalidated
sleep(1);
file_put_contents($testFile, '{"key":"changed"}');
clearstatcache();
assert(Cache::getValidated('config.app', $testFile) === null);

unlink($testFile);
```
**Expected Result:** Cached value is returned when the file hasn't changed. After the file is modified, `getValidated()` returns `null` (stale).

---

### Enable/Disable & Reset
**Description:** `setEnabled()` toggles cache operations at runtime. `reset()` restores the cache to its uninitialized state.

#### Test Case 9: setEnabled / isEnabled / reset
```php
use Razy\Cache;

$cacheDir = sys_get_temp_dir() . '/razy_cache_test_' . uniqid();
Cache::reset();
Cache::initialize($cacheDir);

assert(Cache::isEnabled() === true);

Cache::setEnabled(false);
assert(Cache::isEnabled() === false);

Cache::setEnabled(true);
assert(Cache::isEnabled() === true);

Cache::reset();
assert(Cache::isInitialized() === false);
assert(Cache::isEnabled() === false); // Not enabled since not initialized
```
**Expected Result:** `setEnabled()` toggles the flag; `reset()` clears adapter and initialization state.

---

### Adapters

#### Test Case 10: NullAdapter — all reads miss, all writes succeed
```php
use Razy\Cache\NullAdapter;

$adapter = new NullAdapter();

assert($adapter->set('key', 'value') === true);
assert($adapter->get('key') === null);
assert($adapter->get('key', 'fallback') === 'fallback');
assert($adapter->has('key') === false);
assert($adapter->delete('key') === true);
assert($adapter->clear() === true);
```
**Expected Result:** `NullAdapter` never stores anything. All gets return defaults, all writes return `true`.

#### Test Case 11: FileAdapter — getStats / gc
```php
use Razy\Cache\FileAdapter;

$cacheDir = sys_get_temp_dir() . '/razy_cache_test_' . uniqid();
$adapter = new FileAdapter($cacheDir);

$adapter->set('live', 'data', 3600);
$adapter->set('expired', 'old', -1); // Already expired on set (deleted)

$stats = $adapter->getStats();
assert(isset($stats['directory']));
assert(isset($stats['fileCount']));
assert(is_int($stats['fileCount']));

// gc() removes expired entries and returns number cleaned
$cleaned = $adapter->gc();
assert(is_int($cleaned));
assert($cleaned >= 0);
```
**Expected Result:** `getStats()` returns an array with directory info and file count. `gc()` returns the number of expired files cleaned.

---

## 2. Pipeline (Action-based)

### Pipeline Creation & Execution
**Description:** `Pipeline` manages a sequential list of `Action` instances. Actions are added via `pipe()` (from registered plugins) or `add()` (existing instances), then executed in order via `execute()`.

#### Test Case 1: Add actions and execute
```php
use Razy\Pipeline;
use Razy\Pipeline\Action;

// Create a simple concrete action for testing
$actionA = new class extends Action {
    public bool $ran = false;
    public function execute(...$args): bool {
        $this->ran = true;
        return parent::execute(...$args);
    }
};
$actionA->init('TestA');

$actionB = new class extends Action {
    public bool $ran = false;
    public function execute(...$args): bool {
        $this->ran = true;
        return parent::execute(...$args);
    }
};
$actionB->init('TestB');

$pipeline = new Pipeline();
$pipeline->add($actionA)->add($actionB);

assert(count($pipeline->getActions()) === 2);
assert($pipeline->execute() === true);
assert($actionA->ran === true);
assert($actionB->ran === true);
```
**Expected Result:** Both actions are registered and executed sequentially. `execute()` returns `true` when all succeed.

#### Test Case 2: Execution stops on failure
```php
use Razy\Pipeline;
use Razy\Pipeline\Action;

$passing = new class extends Action {
    public bool $ran = false;
    public function execute(...$args): bool {
        $this->ran = true;
        return true;
    }
};
$passing->init('Pass');

$failing = new class extends Action {
    public bool $ran = false;
    public function execute(...$args): bool {
        $this->ran = true;
        return false; // Fail
    }
};
$failing->init('Fail');

$afterFail = new class extends Action {
    public bool $ran = false;
    public function execute(...$args): bool {
        $this->ran = true;
        return true;
    }
};
$afterFail->init('After');

$pipeline = new Pipeline();
$pipeline->add($passing)->add($failing)->add($afterFail);

assert($pipeline->execute() === false);
assert($passing->ran === true);
assert($failing->ran === true);
assert($afterFail->ran === false); // Never reached
```
**Expected Result:** Pipeline stops at the first failing action and returns `false`. Subsequent actions are not executed.

---

### Shared Storage
**Description:** `setStorage()` / `getStorage()` provide a key-value store shared across all actions in the pipeline, with optional scoped identifiers.

#### Test Case 3: Shared and scoped storage
```php
use Razy\Pipeline;

$pipeline = new Pipeline();

// Flat storage
$pipeline->setStorage('count', 42);
assert($pipeline->getStorage('count') === 42);

// Scoped storage
$pipeline->setStorage('field', 'valid', 'email');
$pipeline->setStorage('field', 'invalid', 'phone');
assert($pipeline->getStorage('field', 'email') === 'valid');
assert($pipeline->getStorage('field', 'phone') === 'invalid');
assert($pipeline->getStorage('field') === null); // No flat 'field' set

// Non-existent key
assert($pipeline->getStorage('missing') === null);
```
**Expected Result:** Flat and scoped storage are independent. Missing keys return `null`.

---

### Relay (Broadcast Proxy)
**Description:** `getRelay()` returns a `Relay` that proxies any method call to all actions in the pipeline via `__call`.

#### Test Case 4: Relay broadcasts to all actions
```php
use Razy\Pipeline;
use Razy\Pipeline\Action;

$log = [];

$actionA = new class($log) extends Action {
    private array &$log;
    public function __construct(array &$log) { $this->log = &$log; }
    public function onSignal(string $msg): void { $this->log[] = "A:$msg"; }
};
$actionA->init('A');

$actionB = new class($log) extends Action {
    private array &$log;
    public function __construct(array &$log) { $this->log = &$log; }
    public function onSignal(string $msg): void { $this->log[] = "B:$msg"; }
};
$actionB->init('B');

$pipeline = new Pipeline();
$pipeline->add($actionA)->add($actionB);

$relay = $pipeline->getRelay();
$relay->onSignal('hello');

assert($log === ['A:hello', 'B:hello']);
```
**Expected Result:** The relay forwards `onSignal('hello')` to both actions. Exceptions from individual actions are silently caught.

---

### Action Tree: then / when / tap / attachTo / detach / terminate
**Description:** Actions form a tree structure. `then()` chains children, `when()` adds conditionally, `tap()` inspects without breaking the chain. Tree operations include `attachTo()`, `adopt()`, `detach()`, `remove()`, and `terminate()`.

#### Test Case 5: Action chaining — when and tap
```php
use Razy\Pipeline\Action;

$action = new class extends Action {};
$action->init('Root');

$tapCalled = false;

$action->when(true, function (Action $a) {
    // This would call then() if plugins were registered
})->tap(function (Action $a) use (&$tapCalled) {
    $tapCalled = true;
    assert($a->getActionType() === 'Root');
});

assert($tapCalled === true);
```
**Expected Result:** `when(true, ...)` executes the callback. `tap()` calls the callback with the action and returns `$this` for chaining.

#### Test Case 6: Detach and terminate
```php
use Razy\Pipeline;
use Razy\Pipeline\Action;

$parent = new class extends Action {};
$parent->init('Parent');
$child = new class extends Action {};
$child->init('Child');

$pipeline = new Pipeline();
$pipeline->add($parent);
$parent->adopt($child);

assert($child->isAttached() === true);
assert($child->getOwner() === $parent);

$child->detach();
assert($child->isAttached() === false);
assert($child->getOwner() === null);
```
**Expected Result:** `detach()` removes the action from its parent. After detach, `isAttached()` returns `false`.

---

### Pipeline Map
**Description:** `getMap()` returns the structural tree of action types and their children for introspection.

#### Test Case 7: getMap returns structure
```php
use Razy\Pipeline;
use Razy\Pipeline\Action;

$action = new class extends Action {};
$action->init('Worker');

$pipeline = new Pipeline();
$pipeline->add($action);

$map = $pipeline->getMap();
assert(count($map) === 1);
assert($map[0]['name'] === 'Worker');
assert(is_array($map[0]['map']));
```
**Expected Result:** `getMap()` returns an array of `[name, map]` entries reflecting the pipeline structure.

---

## 3. Event (PSR-14)

### EventDispatcher
**Description:** `EventDispatcher` implements PSR-14 `PsrEventDispatcherInterface`. It dispatches events to listeners obtained from an injected `ListenerProvider`, respecting `StoppableEventInterface` for propagation control.

#### Test Case 1: Dispatch event to listeners
```php
use Razy\Event\EventDispatcher;
use Razy\Event\ListenerProvider;

class UserRegistered {
    public string $name;
    public function __construct(string $name) { $this->name = $name; }
}

$provider = new ListenerProvider();
$log = [];

$provider->addListener(UserRegistered::class, function (UserRegistered $e) use (&$log) {
    $log[] = "Listener1: {$e->name}";
});
$provider->addListener(UserRegistered::class, function (UserRegistered $e) use (&$log) {
    $log[] = "Listener2: {$e->name}";
});

$dispatcher = new EventDispatcher($provider);
$event = $dispatcher->dispatch(new UserRegistered('Alice'));

assert($event instanceof UserRegistered);
assert($event->name === 'Alice');
assert($log === ['Listener1: Alice', 'Listener2: Alice']);
```
**Expected Result:** Both listeners are invoked in registration order. The dispatch returns the same event object.

---

### ListenerProvider with Priority
**Description:** `ListenerProvider` supports priority ordering. Higher priority listeners are invoked first (descending order).

#### Test Case 2: Listeners execute in priority order
```php
use Razy\Event\ListenerProvider;

class OrderEvent {}

$provider = new ListenerProvider();
$log = [];

$provider->addListener(OrderEvent::class, function () use (&$log) {
    $log[] = 'low';
}, 10);
$provider->addListener(OrderEvent::class, function () use (&$log) {
    $log[] = 'high';
}, 100);
$provider->addListener(OrderEvent::class, function () use (&$log) {
    $log[] = 'medium';
}, 50);

$listeners = iterator_to_array($provider->getListenersForEvent(new OrderEvent()));
foreach ($listeners as $listener) {
    $listener(new OrderEvent());
}

assert($log === ['high', 'medium', 'low']);
```
**Expected Result:** Listeners are yielded in descending priority order: high (100) → medium (50) → low (10).

---

### StoppableEvent
**Description:** `StoppableEvent` provides `stopPropagation()` / `isPropagationStopped()`. When propagation is stopped, the dispatcher skips remaining listeners.

#### Test Case 3: Stop propagation halts dispatch
```php
use Razy\Event\EventDispatcher;
use Razy\Event\ListenerProvider;
use Razy\Event\StoppableEvent;

class CancellableEvent extends StoppableEvent {
    public array $log = [];
}

$provider = new ListenerProvider();
$provider->addListener(CancellableEvent::class, function (CancellableEvent $e) {
    $e->log[] = 'first';
    $e->stopPropagation();
}, 100);
$provider->addListener(CancellableEvent::class, function (CancellableEvent $e) {
    $e->log[] = 'second'; // Should NOT be called
}, 50);

$dispatcher = new EventDispatcher($provider);
$event = $dispatcher->dispatch(new CancellableEvent());

assert($event->isPropagationStopped() === true);
assert($event->log === ['first']);
```
**Expected Result:** Only the first listener runs. The second is skipped because propagation was stopped.

#### Test Case 4: Already-stopped event is not dispatched
```php
use Razy\Event\EventDispatcher;
use Razy\Event\ListenerProvider;
use Razy\Event\StoppableEvent;

class PreStoppedEvent extends StoppableEvent {}

$provider = new ListenerProvider();
$called = false;
$provider->addListener(PreStoppedEvent::class, function () use (&$called) {
    $called = true;
});

$event = new PreStoppedEvent();
$event->stopPropagation();

$dispatcher = new EventDispatcher($provider);
$result = $dispatcher->dispatch($event);

assert($called === false);
assert($result === $event);
```
**Expected Result:** If the event is already stopped before dispatch, no listeners are invoked. The event object is returned unchanged.

---

## 4. RateLimit

### RateLimiter Core Operations
**Description:** `RateLimiter` implements a fixed-window rate limiting algorithm. It tracks hit counts per key using a pluggable `RateLimitStoreInterface`. Supports `attempt()`, `hit()`, `tooManyAttempts()`, `remaining()`, `availableIn()`, `resetAt()`, `attempts()`, and `clear()`.

#### Test Case 1: hit / attempts / remaining
```php
use Razy\RateLimit\RateLimiter;
use Razy\Contract\RateLimitStoreInterface;

// In-memory store mock
$store = new class implements RateLimitStoreInterface {
    private array $data = [];
    public function get(string $key): ?array {
        return $this->data[$key] ?? null;
    }
    public function set(string $key, int $hits, int $resetAt): void {
        $this->data[$key] = ['hits' => $hits, 'resetAt' => $resetAt];
    }
    public function delete(string $key): void {
        unset($this->data[$key]);
    }
};

$limiter = new RateLimiter($store);

// First hit starts a new window
$hits = $limiter->hit('api:user1', 60);
assert($hits === 1);
assert($limiter->attempts('api:user1') === 1);
assert($limiter->remaining('api:user1', 5) === 4);

// Second hit
$hits = $limiter->hit('api:user1', 60);
assert($hits === 2);
assert($limiter->remaining('api:user1', 5) === 3);
```
**Expected Result:** Each `hit()` increments the counter and returns the new total. `remaining()` returns `maxAttempts - hits`.

#### Test Case 2: tooManyAttempts / attempt
```php
use Razy\RateLimit\RateLimiter;
use Razy\Contract\RateLimitStoreInterface;

$store = new class implements RateLimitStoreInterface {
    private array $data = [];
    public function get(string $key): ?array { return $this->data[$key] ?? null; }
    public function set(string $key, int $hits, int $resetAt): void {
        $this->data[$key] = ['hits' => $hits, 'resetAt' => $resetAt];
    }
    public function delete(string $key): void { unset($this->data[$key]); }
};

$limiter = new RateLimiter($store);
$maxAttempts = 3;

assert($limiter->attempt('login:bob', $maxAttempts, 60) === true);  // hit 1
assert($limiter->attempt('login:bob', $maxAttempts, 60) === true);  // hit 2
assert($limiter->attempt('login:bob', $maxAttempts, 60) === true);  // hit 3
assert($limiter->attempt('login:bob', $maxAttempts, 60) === false); // blocked

assert($limiter->tooManyAttempts('login:bob', $maxAttempts) === true);
assert($limiter->remaining('login:bob', $maxAttempts) === 0);
```
**Expected Result:** `attempt()` returns `true` while under the limit, `false` when exceeded. `tooManyAttempts()` confirms the limit is reached.

#### Test Case 3: availableIn / resetAt / clear
```php
use Razy\RateLimit\RateLimiter;
use Razy\Contract\RateLimitStoreInterface;

$store = new class implements RateLimitStoreInterface {
    private array $data = [];
    public function get(string $key): ?array { return $this->data[$key] ?? null; }
    public function set(string $key, int $hits, int $resetAt): void {
        $this->data[$key] = ['hits' => $hits, 'resetAt' => $resetAt];
    }
    public function delete(string $key): void { unset($this->data[$key]); }
};

$limiter = new RateLimiter($store);
$limiter->hit('key1', 120);

// resetAt is in the future
assert($limiter->resetAt('key1') > time());
assert($limiter->availableIn('key1') > 0);
assert($limiter->availableIn('key1') <= 120);

// Clear resets the key
$limiter->clear('key1');
assert($limiter->attempts('key1') === 0);
assert($limiter->availableIn('key1') === 0);
assert($limiter->resetAt('key1') === 0);
```
**Expected Result:** `resetAt()` returns future timestamp, `availableIn()` returns seconds until reset, `clear()` removes the record entirely.

---

### Named Limiters & Limit Value Object
**Description:** Named limiters are registered via `for()` and resolved via `resolve()`. The `Limit` class provides fluent factory methods for common rate limit configurations.

#### Test Case 4: Named limiter registration and resolution
```php
use Razy\RateLimit\RateLimiter;
use Razy\RateLimit\Limit;
use Razy\Contract\RateLimitStoreInterface;

$store = new class implements RateLimitStoreInterface {
    private array $data = [];
    public function get(string $key): ?array { return $this->data[$key] ?? null; }
    public function set(string $key, int $hits, int $resetAt): void {
        $this->data[$key] = ['hits' => $hits, 'resetAt' => $resetAt];
    }
    public function delete(string $key): void { unset($this->data[$key]); }
};

$limiter = new RateLimiter($store);

$limiter->for('api', fn(array $context) =>
    Limit::perMinute(60)->by($context['ip'] ?? 'unknown')
);

assert($limiter->hasLimiter('api') === true);
assert($limiter->hasLimiter('nonexistent') === false);
assert($limiter->limiter('api') !== null);

$limit = $limiter->resolve('api', ['ip' => '192.168.1.1']);
assert($limit instanceof Limit);
assert($limit->getMaxAttempts() === 60);
assert($limit->getDecaySeconds() === 60);
assert($limit->getKey() === '192.168.1.1');
assert($limit->isUnlimited() === false);
```
**Expected Result:** Named limiter is registered and resolved with context, producing a `Limit` with correct max attempts, decay, and key.

#### Test Case 5: Limit factory methods
```php
use Razy\RateLimit\Limit;

$perMin = Limit::perMinute(100);
assert($perMin->getMaxAttempts() === 100);
assert($perMin->getDecaySeconds() === 60);

$perHour = Limit::perHour(1000);
assert($perHour->getMaxAttempts() === 1000);
assert($perHour->getDecaySeconds() === 3600);

$perDay = Limit::perDay(10000);
assert($perDay->getMaxAttempts() === 10000);
assert($perDay->getDecaySeconds() === 86400);

$custom = Limit::every(30, 10)->by('custom-key');
assert($custom->getMaxAttempts() === 10);
assert($custom->getDecaySeconds() === 30);
assert($custom->getKey() === 'custom-key');

$unlimited = Limit::none();
assert($unlimited->isUnlimited() === true);
assert($unlimited->getMaxAttempts() === PHP_INT_MAX);
```
**Expected Result:** Each factory method creates a `Limit` with the correct decay window. `none()` creates an unlimited limit.

---

## 5. Log\LogManager (PSR-3 Multi-Channel)

### Basic Logging with Handlers
**Description:** `LogManager` manages named channels, each with its own handler stack. It implements PSR-3 `LoggerInterface` with support for context interpolation and in-memory buffering.

#### Test Case 1: Create LogManager, add handler, log message
```php
use Razy\Log\LogManager;
use Razy\Log\NullHandler;

$log = new LogManager('app', bufferEnabled: true);
$log->addHandler('app', new NullHandler());

$log->info('Application started');

$buffer = $log->getBuffer();
assert(count($buffer) === 1);
assert($buffer[0]['level'] === 'info');
assert($buffer[0]['message'] === 'Application started');
assert($buffer[0]['channel'] === 'app');
```
**Expected Result:** The message is logged to the default channel 'app' and recorded in the buffer with correct level, message, and channel.

#### Test Case 2: PSR-3 context interpolation
```php
use Razy\Log\LogManager;
use Razy\Log\NullHandler;

$log = new LogManager('app', bufferEnabled: true);
$log->addHandler('app', new NullHandler());

$log->info('User {username} logged in from {ip}', [
    'username' => 'alice',
    'ip'       => '10.0.0.1',
]);

$buffer = $log->getBuffer();
assert($buffer[0]['message'] === 'User alice logged in from 10.0.0.1');
```
**Expected Result:** Placeholders `{username}` and `{ip}` are replaced with context values in the logged message.

---

### Channel Switching & Stacking
**Description:** `channel()` switches the target for the next log call. `stack()` broadcasts to multiple channels simultaneously.

#### Test Case 3: Log to specific channel
```php
use Razy\Log\LogManager;
use Razy\Log\NullHandler;

$log = new LogManager('app', bufferEnabled: true);
$log->addHandler('app', new NullHandler());
$log->addHandler('errors', new NullHandler());

$log->channel('errors')->error('Something broke');

$buffer = $log->getBuffer();
assert(count($buffer) === 1);
assert($buffer[0]['channel'] === 'errors');
assert($buffer[0]['level'] === 'error');
```
**Expected Result:** The log entry is recorded under the 'errors' channel, not the default 'app' channel.

#### Test Case 4: Stack channels
```php
use Razy\Log\LogManager;
use Razy\Log\NullHandler;

$log = new LogManager('app', bufferEnabled: true);
$log->addHandler('app', new NullHandler());
$log->addHandler('audit', new NullHandler());

$log->stack(['app', 'audit'])->critical('Critical failure in payment');

$buffer = $log->getBuffer();
assert(count($buffer) === 2);
assert($buffer[0]['channel'] === 'app');
assert($buffer[1]['channel'] === 'audit');
assert($buffer[0]['message'] === 'Critical failure in payment');
assert($buffer[1]['message'] === 'Critical failure in payment');
```
**Expected Result:** The same message is logged to both 'app' and 'audit' channels as separate buffer entries.

---

### Channel Inspection & Buffer Management
**Description:** `getChannelNames()`, `hasChannel()`, `getBuffer()`, and `clearBuffer()` provide inspection and management tools.

#### Test Case 5: Channel inspection and buffer clear
```php
use Razy\Log\LogManager;
use Razy\Log\NullHandler;

$log = new LogManager('default', bufferEnabled: true);
$log->addHandler('default', new NullHandler());
$log->addHandler('security', new NullHandler());

assert($log->hasChannel('default') === true);
assert($log->hasChannel('security') === true);
assert($log->hasChannel('missing') === false);

$names = $log->getChannelNames();
assert(in_array('default', $names));
assert(in_array('security', $names));

$log->info('test');
assert(count($log->getBuffer()) === 1);

$log->clearBuffer();
assert(count($log->getBuffer()) === 0);
```
**Expected Result:** Channel names are reported correctly. Buffer can be cleared independently of handler state.

---

### Handlers

#### Test Case 6: NullHandler accepts all levels
```php
use Razy\Log\NullHandler;
use Razy\Contract\Log\LogLevel;

$handler = new NullHandler();

assert($handler->isHandling(LogLevel::DEBUG) === true);
assert($handler->isHandling(LogLevel::EMERGENCY) === true);

// handle() is a no-op — no exceptions
$handler->handle(LogLevel::INFO, 'test', [], '2025-01-01 00:00:00', 'app');
```
**Expected Result:** `NullHandler` accepts all log levels and silently discards messages.

#### Test Case 7: StderrHandler minimum level filtering
```php
use Razy\Log\StderrHandler;
use Razy\Contract\Log\LogLevel;

$handler = new StderrHandler(LogLevel::WARNING);

assert($handler->isHandling(LogLevel::DEBUG) === false);
assert($handler->isHandling(LogLevel::INFO) === false);
assert($handler->isHandling(LogLevel::WARNING) === true);
assert($handler->isHandling(LogLevel::ERROR) === true);
assert($handler->isHandling(LogLevel::EMERGENCY) === true);
assert($handler->getMinLevel() === LogLevel::WARNING);
```
**Expected Result:** `StderrHandler` only handles messages at or above the configured minimum level.

---

## 6. Notification

### NotificationManager Core
**Description:** `NotificationManager` dispatches notifications to entities via registered channels. It calls `via()` on each notification to determine delivery channels, then delegates to the channel's `send()` method.

#### Test Case 1: Register channels and send notification
```php
use Razy\Notification\NotificationManager;
use Razy\Notification\Notification;
use Razy\Notification\NotificationChannelInterface;

// Stub channel
$stubChannel = new class implements NotificationChannelInterface {
    public array $sent = [];
    public function send(object $notifiable, Notification $notification): void {
        $this->sent[] = ['to' => $notifiable, 'notification' => $notification];
    }
    public function getName(): string { return 'stub'; }
};

// Stub notification
$notification = new class extends Notification {
    public function via(object $notifiable): array {
        return ['stub'];
    }
};

// Stub notifiable
$user = new class {
    public string $name = 'Alice';
};

$manager = new NotificationManager();
$manager->registerChannel($stubChannel);
$manager->send($user, $notification);

assert(count($stubChannel->sent) === 1);
assert($stubChannel->sent[0]['to']->name === 'Alice');
```
**Expected Result:** The notification is dispatched to the 'stub' channel and the channel receives the notifiable and notification objects.

#### Test Case 2: sendToMany dispatches to multiple notifiables
```php
use Razy\Notification\NotificationManager;
use Razy\Notification\Notification;
use Razy\Notification\NotificationChannelInterface;

$channel = new class implements NotificationChannelInterface {
    public int $count = 0;
    public function send(object $notifiable, Notification $notification): void {
        $this->count++;
    }
    public function getName(): string { return 'counter'; }
};

$notification = new class extends Notification {
    public function via(object $notifiable): array { return ['counter']; }
};

$users = [
    (object)['name' => 'Alice'],
    (object)['name' => 'Bob'],
    (object)['name' => 'Charlie'],
];

$manager = new NotificationManager();
$manager->registerChannel($channel);
$manager->sendToMany($users, $notification);

assert($channel->count === 3);
```
**Expected Result:** The notification is sent once for each notifiable entity (3 total).

---

### Lifecycle Hooks & Sent Log
**Description:** `beforeSend()`, `afterSend()`, and `onError()` register lifecycle callbacks. `getSentLog()` / `clearSentLog()` track sent notifications when logging is enabled.

#### Test Case 3: Before/after hooks and sent log
```php
use Razy\Notification\NotificationManager;
use Razy\Notification\Notification;
use Razy\Notification\NotificationChannelInterface;

$channel = new class implements NotificationChannelInterface {
    public function send(object $notifiable, Notification $notification): void {}
    public function getName(): string { return 'test'; }
};

$notification = new class extends Notification {
    public function via(object $notifiable): array { return ['test']; }
    public function getType(): string { return 'WelcomeNotification'; }
};

$hooks = [];

$manager = new NotificationManager(logging: true);
$manager->registerChannel($channel);

$manager->beforeSend(function ($notifiable, $notification, $channelName) use (&$hooks) {
    $hooks[] = "before:$channelName";
});
$manager->afterSend(function ($notifiable, $notification, $channelName) use (&$hooks) {
    $hooks[] = "after:$channelName";
});

$user = (object)['id' => 1];
$manager->send($user, $notification);

assert($hooks === ['before:test', 'after:test']);
assert(count($manager->getSentLog()) === 1);
assert($manager->getSentLog()[0]['channel'] === 'test');
assert($manager->getSentLog()[0]['notification'] === 'WelcomeNotification');

$manager->clearSentLog();
assert(count($manager->getSentLog()) === 0);
```
**Expected Result:** Before and after hooks fire in order. The sent log records the delivery. `clearSentLog()` empties the log.

#### Test Case 4: Error hook catches channel failures
```php
use Razy\Notification\NotificationManager;
use Razy\Notification\Notification;
use Razy\Notification\NotificationChannelInterface;

$failingChannel = new class implements NotificationChannelInterface {
    public function send(object $notifiable, Notification $notification): void {
        throw new \RuntimeException('Send failed');
    }
    public function getName(): string { return 'broken'; }
};

$notification = new class extends Notification {
    public function via(object $notifiable): array { return ['broken']; }
};

$caughtError = null;
$manager = new NotificationManager();
$manager->registerChannel($failingChannel);
$manager->onError(function ($notifiable, $notification, $channel, $error) use (&$caughtError) {
    $caughtError = $error->getMessage();
});

$manager->send((object)[], $notification);

assert($caughtError === 'Send failed');
```
**Expected Result:** The error hook captures the exception and prevents it from propagating. `$caughtError` contains the error message.

---

### MailChannel
**Description:** `MailChannel` sends notifications via a callable mailer function. It resolves the recipient from `getEmail()` or `$email` property. When `recording` is enabled, sent messages can be inspected.

#### Test Case 5: MailChannel sends and records
```php
use Razy\Notification\Channel\MailChannel;
use Razy\Notification\Notification;

$sentEmails = [];
$mailerFn = function (string $to, array $data) use (&$sentEmails) {
    $sentEmails[] = ['to' => $to, 'data' => $data];
};

$mailChannel = new MailChannel($mailerFn, recording: true);

$notification = new class extends Notification {
    public function via(object $notifiable): array { return ['mail']; }
    public function toMail(object $notifiable): array {
        return ['subject' => 'Welcome!', 'body' => 'Hello there!'];
    }
};

$user = new class {
    public function getEmail(): string { return 'alice@example.com'; }
};

$mailChannel->send($user, $notification);

assert($mailChannel->getName() === 'mail');
assert(count($sentEmails) === 1);
assert($sentEmails[0]['to'] === 'alice@example.com');
assert($sentEmails[0]['data']['subject'] === 'Welcome!');

$recorded = $mailChannel->getSent();
assert(count($recorded) === 1);
assert($recorded[0]['to'] === 'alice@example.com');

$mailChannel->clearSent();
assert(count($mailChannel->getSent()) === 0);
```
**Expected Result:** The mail channel calls the mailer function with the resolved email and notification data. Recorded messages match the sent data and can be cleared.

---

### DatabaseChannel
**Description:** `DatabaseChannel` stores notification records in-memory (and optionally delegates to a custom store function). Records can be retrieved, filtered by notifiable, counted, and cleared.

#### Test Case 6: DatabaseChannel stores and queries records
```php
use Razy\Notification\Channel\DatabaseChannel;
use Razy\Notification\Notification;

$dbChannel = new DatabaseChannel();

$notification = new class extends Notification {
    public function via(object $notifiable): array { return ['database']; }
    public function toDatabase(object $notifiable): array {
        return ['type' => 'welcome', 'message' => 'Hello!'];
    }
};

$user1 = new class { public int $id = 1; };
$user2 = new class { public int $id = 2; };

$dbChannel->send($user1, $notification);
$dbChannel->send($user2, $notification);

assert($dbChannel->getName() === 'database');
assert($dbChannel->count() === 2);

$records = $dbChannel->getRecords();
assert(count($records) === 2);
assert($records[0]['data']['type'] === 'welcome');

$user1Records = $dbChannel->getRecordsFor($user1);
assert(count($user1Records) === 1);

$dbChannel->clearRecords();
assert($dbChannel->count() === 0);
```
**Expected Result:** Records are stored in-memory, can be queried by notifiable, counted, and cleared.

#### Test Case 7: DatabaseChannel with custom store function
```php
use Razy\Notification\Channel\DatabaseChannel;
use Razy\Notification\Notification;

$externalStore = [];
$dbChannel = new DatabaseChannel(function (array $record) use (&$externalStore) {
    $externalStore[] = $record;
});

$notification = new class extends Notification {
    public function via(object $notifiable): array { return ['database']; }
    public function toDatabase(object $notifiable): array {
        return ['action' => 'signup'];
    }
};

$dbChannel->send((object)['id' => 42], $notification);

assert(count($externalStore) === 1);
assert($externalStore[0]['data']['action'] === 'signup');
assert(isset($externalStore[0]['id']));
assert(isset($externalStore[0]['type']));
assert(isset($externalStore[0]['created_at']));
```
**Expected Result:** The custom store function receives a complete record array with id, type, notifiable info, data, and timestamp.

---

## 7. Queue

### QueueManager Dispatching
**Description:** `QueueManager` dispatches jobs to named queues via a `QueueStoreInterface`. `dispatch()` supports delay, retry, and priority options. `dispatchNow()` is a shortcut for immediate, low-priority dispatch.

#### Test Case 1: Dispatch jobs to a queue
```php
use Razy\Queue\QueueManager;
use Razy\Queue\QueueStoreInterface;
use Razy\Queue\Job;
use Razy\Queue\JobStatus;

// In-memory mock store
$store = new class implements QueueStoreInterface {
    public array $jobs = [];
    private int $nextId = 1;

    public function push(string $queue, string $handler, array $payload = [],
        int $delay = 0, int $maxAttempts = 3, int $retryDelay = 0, int $priority = 100
    ): int|string {
        $id = $this->nextId++;
        $this->jobs[$id] = new Job($id, $queue, $handler, $payload,
            0, $maxAttempts, $retryDelay, $priority, null, date('c'), null, JobStatus::Pending);
        return $id;
    }
    public function reserve(string $queue): ?Job { return null; }
    public function complete(int|string $jobId): void {}
    public function release(int|string $jobId, int $retryDelay = 0, string $error = ''): void {}
    public function bury(int|string $jobId, string $error = ''): void {}
    public function delete(int|string $jobId): void { unset($this->jobs[$jobId]); }
    public function find(int|string $jobId): ?Job { return $this->jobs[$jobId] ?? null; }
    public function count(string $queue, JobStatus $status): int {
        return count(array_filter($this->jobs, fn(Job $j) => $j->queue === $queue && $j->status === $status));
    }
    public function clear(string $queue): int { return 0; }
    public function ensureStorage(): void {}
};

$manager = new QueueManager($store);

$id = $manager->dispatch('emails', 'App\\Handlers\\SendEmail', ['to' => 'a@b.com']);
assert($id === 1);

$job = $manager->find($id);
assert($job instanceof Job);
assert($job->queue === 'emails');
assert($job->handler === 'App\\Handlers\\SendEmail');
assert($job->payload === ['to' => 'a@b.com']);
assert($job->status === JobStatus::Pending);
```
**Expected Result:** Jobs are pushed to the store with the correct queue name, handler, payload, and pending status.

#### Test Case 2: dispatchNow uses delay=0 and priority=0
```php
use Razy\Queue\QueueManager;
use Razy\Queue\QueueStoreInterface;
use Razy\Queue\Job;
use Razy\Queue\JobStatus;

$store = new class implements QueueStoreInterface {
    public ?int $lastPriority = null;
    public ?int $lastDelay = null;
    public function push(string $queue, string $handler, array $payload = [],
        int $delay = 0, int $maxAttempts = 3, int $retryDelay = 0, int $priority = 100
    ): int|string {
        $this->lastDelay = $delay;
        $this->lastPriority = $priority;
        return 1;
    }
    public function reserve(string $queue): ?Job { return null; }
    public function complete(int|string $jobId): void {}
    public function release(int|string $jobId, int $retryDelay = 0, string $error = ''): void {}
    public function bury(int|string $jobId, string $error = ''): void {}
    public function delete(int|string $jobId): void {}
    public function find(int|string $jobId): ?Job { return null; }
    public function count(string $queue, JobStatus $status): int { return 0; }
    public function clear(string $queue): int { return 0; }
    public function ensureStorage(): void {}
};

$manager = new QueueManager($store);
$manager->dispatchNow('emails', 'App\\Handlers\\SendEmail', ['to' => 'x@y.com']);

assert($store->lastDelay === 0);
assert($store->lastPriority === 0);
```
**Expected Result:** `dispatchNow()` sets delay to 0 and priority to 0 for immediate processing.

---

### Processing Jobs
**Description:** `process()` reserves the next job, resolves the handler, calls `handle()`, and marks it completed. On failure, jobs are retried or buried based on attempt limits. `processBatch()` processes up to N jobs.

#### Test Case 3: Process a job successfully
```php
use Razy\Queue\QueueManager;
use Razy\Queue\QueueStoreInterface;
use Razy\Queue\Job;
use Razy\Queue\JobStatus;
use Razy\Queue\JobHandlerInterface;

$completed = [];

$store = new class($completed) implements QueueStoreInterface {
    private array &$completed;
    private ?Job $pending;
    public function __construct(array &$completed) {
        $this->completed = &$completed;
        $this->pending = new Job(1, 'emails', 'TestHandler', ['msg' => 'hi'],
            0, 3, 0, 100, null, null, null, JobStatus::Pending);
    }
    public function push(string $queue, string $handler, array $payload = [],
        int $delay = 0, int $maxAttempts = 3, int $retryDelay = 0, int $priority = 100
    ): int|string { return 1; }
    public function reserve(string $queue): ?Job {
        $job = $this->pending;
        $this->pending = null;
        if ($job) { $job->incrementAttempts(); $job->markReserved(); }
        return $job;
    }
    public function complete(int|string $jobId): void { $this->completed[] = $jobId; }
    public function release(int|string $jobId, int $retryDelay = 0, string $error = ''): void {}
    public function bury(int|string $jobId, string $error = ''): void {}
    public function delete(int|string $jobId): void {}
    public function find(int|string $jobId): ?Job { return null; }
    public function count(string $queue, JobStatus $status): int { return 0; }
    public function clear(string $queue): int { return 0; }
    public function ensureStorage(): void {}
};

$manager = new QueueManager($store);
$manager->setHandlerResolver(function (string $class) {
    return new class implements JobHandlerInterface {
        public function handle(array $payload): void { /* success */ }
        public function failed(array $payload, \Throwable $error): void {}
    };
});

$result = $manager->process('emails');
assert($result === true);
assert($completed === [1]);

// Queue is now empty
$result = $manager->process('emails');
assert($result === false);
```
**Expected Result:** First `process()` returns `true` and completes the job. Second call returns `false` (empty queue).

---

### Job Lifecycle
**Description:** `Job` is a value object with lifecycle methods: `incrementAttempts()`, `hasExhaustedAttempts()`, `markReserved()`, `markCompleted()`, `markFailed()`, `markBuried()`. Serialization is via `toArray()` / `fromArray()`.

#### Test Case 4: Job lifecycle transitions
```php
use Razy\Queue\Job;
use Razy\Queue\JobStatus;

$job = new Job(
    id: 42,
    queue: 'emails',
    handler: 'App\\SendEmail',
    payload: ['to' => 'test@example.com'],
    attempts: 0,
    maxAttempts: 3,
);

assert($job->status === JobStatus::Pending);
assert($job->hasExhaustedAttempts() === false);

$job->incrementAttempts();
assert($job->attempts === 1);

$job->markReserved();
assert($job->status === JobStatus::Reserved);
assert($job->reservedAt !== null);

$job->markCompleted();
assert($job->status === JobStatus::Completed);
```
**Expected Result:** Job transitions through Pending → Reserved → Completed. Attempt counter increments correctly.

#### Test Case 5: Job exhausted attempts and burial
```php
use Razy\Queue\Job;
use Razy\Queue\JobStatus;

$job = new Job(
    id: 1,
    queue: 'default',
    handler: 'App\\Handler',
    payload: [],
    attempts: 2,
    maxAttempts: 3,
);

$job->incrementAttempts(); // attempts = 3
assert($job->hasExhaustedAttempts() === true);

$job->markBuried('Max retries exceeded');
assert($job->status === JobStatus::Buried);
assert($job->error === 'Max retries exceeded');
```
**Expected Result:** When attempts reach maxAttempts, `hasExhaustedAttempts()` returns `true`. `markBuried()` sets the status and error message.

#### Test Case 6: Job serialization — toArray / fromArray
```php
use Razy\Queue\Job;
use Razy\Queue\JobStatus;

$job = new Job(
    id: 10,
    queue: 'reports',
    handler: 'App\\GenerateReport',
    payload: ['format' => 'pdf'],
    attempts: 1,
    maxAttempts: 5,
    retryDelay: 30,
    priority: 50,
    status: JobStatus::Failed,
    error: 'Timeout',
);

$array = $job->toArray();
assert($array['id'] === 10);
assert($array['queue'] === 'reports');
assert($array['handler'] === 'App\\GenerateReport');
assert($array['status'] === 'failed');
assert($array['error'] === 'Timeout');
assert($array['priority'] === 50);

$restored = Job::fromArray($array);
assert($restored->id === 10);
assert($restored->queue === 'reports');
assert($restored->payload === ['format' => 'pdf']);
assert($restored->status === JobStatus::Failed);
assert($restored->maxAttempts === 5);
assert($restored->retryDelay === 30);
```
**Expected Result:** `toArray()` produces a storable array with JSON-encoded payload. `fromArray()` reconstructs the Job object with all properties intact.

---

### JobStatus Enum
**Description:** `JobStatus` is a backed enum representing the lifecycle states of a queued job.

#### Test Case 7: JobStatus values
```php
use Razy\Queue\JobStatus;

assert(JobStatus::Pending->value === 'pending');
assert(JobStatus::Reserved->value === 'reserved');
assert(JobStatus::Completed->value === 'completed');
assert(JobStatus::Failed->value === 'failed');
assert(JobStatus::Buried->value === 'buried');
assert(JobStatus::from('pending') === JobStatus::Pending);
assert(JobStatus::from('buried') === JobStatus::Buried);
```
**Expected Result:** Enum cases map to their expected string values and can be reconstructed from strings.

---

### Lifecycle Events & Count/Delete/Clear
**Description:** `on()` registers lifecycle event listeners. `count()`, `delete()`, and `clear()` inspect and manage the queue.

#### Test Case 8: Lifecycle event listeners
```php
use Razy\Queue\QueueManager;
use Razy\Queue\QueueStoreInterface;
use Razy\Queue\Job;
use Razy\Queue\JobStatus;

$store = new class implements QueueStoreInterface {
    public function push(string $queue, string $handler, array $payload = [],
        int $delay = 0, int $maxAttempts = 3, int $retryDelay = 0, int $priority = 100
    ): int|string { return 1; }
    public function reserve(string $queue): ?Job { return null; }
    public function complete(int|string $jobId): void {}
    public function release(int|string $jobId, int $retryDelay = 0, string $error = ''): void {}
    public function bury(int|string $jobId, string $error = ''): void {}
    public function delete(int|string $jobId): void {}
    public function find(int|string $jobId): ?Job { return null; }
    public function count(string $queue, JobStatus $status): int { return 0; }
    public function clear(string $queue): int { return 0; }
    public function ensureStorage(): void {}
};

$events = [];
$manager = new QueueManager($store);
$manager->on('dispatched', function (array $ctx) use (&$events) {
    $events[] = 'dispatched:' . $ctx['id'];
});

$manager->dispatch('emails', 'Handler', ['data' => true]);

assert($events === ['dispatched:1']);
```
**Expected Result:** The 'dispatched' event listener fires with the correct job ID in the context array.

---

## 8. PluginManager

### Singleton Management
**Description:** `PluginManager` is a centralized plugin registry providing a singleton pattern via `getInstance()` / `setInstance()`. It replaces scattered static state across consumer classes.

#### Test Case 1: Singleton getInstance / setInstance
```php
use Razy\PluginManager;

// Clear any existing instance
PluginManager::setInstance(null);

$a = PluginManager::getInstance();
$b = PluginManager::getInstance();
assert($a === $b); // Same instance

// Replace the singleton
$custom = new PluginManager();
PluginManager::setInstance($custom);
assert(PluginManager::getInstance() === $custom);

// Restore to null
PluginManager::setInstance(null);
$c = PluginManager::getInstance();
assert($c !== $custom); // New instance created
```
**Expected Result:** `getInstance()` returns the same singleton. `setInstance()` replaces it, and passing `null` forces a new instance on next call.

---

### Plugin Folder Registration
**Description:** `addFolder()` registers a plugin directory for a specific owner class. Only existing directories are registered.

#### Test Case 2: addFolder registers directories
```php
use Razy\PluginManager;

$manager = new PluginManager();
$tempDir = sys_get_temp_dir() . '/razy_plugin_test_' . uniqid();
mkdir($tempDir, 0775, true);

$manager->addFolder('App\\Template', $tempDir, ['version' => '1.0']);

$folders = $manager->getFolders('App\\Template');
assert(count($folders) === 1);
assert(isset($folders[array_key_first($folders)]));

// Non-existent directories are silently ignored
$manager->addFolder('App\\Template', '/nonexistent/path');
assert(count($manager->getFolders('App\\Template')) === 1);

rmdir($tempDir);
```
**Expected Result:** Valid directories are registered; non-existent paths are silently ignored.

---

### Plugin Loading & Caching
**Description:** `getPlugin()` loads a plugin file from registered folders. The result (a `Closure` and its args) is cached for subsequent lookups.

#### Test Case 3: getPlugin loads and caches
```php
use Razy\PluginManager;

$manager = new PluginManager();
$tempDir = sys_get_temp_dir() . '/razy_plugin_test_' . uniqid();
mkdir($tempDir, 0775, true);

// Create a plugin file that returns a Closure
file_put_contents($tempDir . '/modifier.upper.php', '<?php return function(string $v) { return strtoupper($v); };');

$manager->addFolder('App\\Template', $tempDir);

$plugin = $manager->getPlugin('App\\Template', 'modifier.upper');
assert($plugin !== null);
assert($plugin['entity'] instanceof \Closure);

// Second call returns from cache
$cached = $manager->getPlugin('App\\Template', 'modifier.upper');
assert($cached === $plugin);

// Missing plugin returns null
$missing = $manager->getPlugin('App\\Template', 'modifier.missing');
assert($missing === null);

// Cached plugins list
$cachedPlugins = $manager->getCachedPlugins('App\\Template');
assert(isset($cachedPlugins['modifier.upper']));

unlink($tempDir . '/modifier.upper.php');
rmdir($tempDir);
```
**Expected Result:** Plugin files returning Closures are loaded and cached. Missing plugins return `null`.

---

### Reset & Owner Management
**Description:** `reset()` clears a single owner's registry. `resetAll()` clears all registries. `getRegisteredOwners()` lists all registered owner classes.

#### Test Case 4: reset / resetAll / getRegisteredOwners
```php
use Razy\PluginManager;

$manager = new PluginManager();
$tempDir1 = sys_get_temp_dir() . '/razy_plugin_a_' . uniqid();
$tempDir2 = sys_get_temp_dir() . '/razy_plugin_b_' . uniqid();
mkdir($tempDir1, 0775, true);
mkdir($tempDir2, 0775, true);

$manager->addFolder('App\\Template', $tempDir1);
$manager->addFolder('App\\Pipeline', $tempDir2);

$owners = $manager->getRegisteredOwners();
assert(in_array('App\\Template', $owners));
assert(in_array('App\\Pipeline', $owners));

// Reset one owner
$manager->reset('App\\Template');
assert(count($manager->getFolders('App\\Template')) === 0);
assert(count($manager->getFolders('App\\Pipeline')) === 1);

// Reset all owners
$manager->resetAll();
assert(count($manager->getRegisteredOwners()) === 0);

rmdir($tempDir1);
rmdir($tempDir2);
```
**Expected Result:** `reset()` clears only the specified owner. `resetAll()` clears everything. After reset, `getFolders()` returns empty and owner is removed from the list.
