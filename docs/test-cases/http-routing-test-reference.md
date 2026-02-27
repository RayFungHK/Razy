# HTTP & Routing — Test Case Reference

---

## 1. Route

### `contain()` — Check if route contains matches
**Description:** Attaches arbitrary data to a route entry for controller consumption. Returns the `Route` instance for fluent chaining.

#### Test Case 1: Attach and retrieve data
```php
use Razy\Route;

$route = new Route('users/show');
$result = $route->contain(['role' => 'admin']);

$this->assertSame($route, $result);             // fluent return
$this->assertSame(['role' => 'admin'], $route->getData());
```
**Expected Result:** `contain()` returns the same `Route` instance, and `getData()` returns exactly the data that was passed in.

#### Test Case 2: Attach null data (reset)
```php
use Razy\Route;

$route = new Route('users/show');
$route->contain('initial');
$route->contain(null);

$this->assertNull($route->getData());
```
**Expected Result:** Passing `null` to `contain()` clears the previously set data.

---

### `getData()` — Get route data
**Description:** Returns the arbitrary data previously set via `contain()`. Returns `null` if nothing was attached.

#### Test Case 1: Default is null
```php
use Razy\Route;

$route = new Route('dashboard');
$this->assertNull($route->getData());
```
**Expected Result:** A freshly created route has `null` data.

#### Test Case 2: Scalar data
```php
use Razy\Route;

$route = new Route('settings');
$route->contain(42);

$this->assertSame(42, $route->getData());
```
**Expected Result:** `getData()` returns the scalar value `42`.

---

### `method(method)` — Set HTTP method
**Description:** Sets the HTTP method constraint for the route. Accepts `GET`, `POST`, `PUT`, `PATCH`, `DELETE`, `HEAD`, `OPTIONS`, or `*` (any). Throws `\InvalidArgumentException` for invalid methods.

#### Test Case 1: Set and get method
```php
use Razy\Route;

$route = new Route('api/users');
$result = $route->method('POST');

$this->assertSame($route, $result);  // fluent
$this->assertSame('POST', $route->getMethod());
```
**Expected Result:** Method is stored as uppercase, fluent return.

#### Test Case 2: Case-insensitive input
```php
use Razy\Route;

$route = new Route('api/users');
$route->method('get');

$this->assertSame('GET', $route->getMethod());
```
**Expected Result:** Lowercase `'get'` is normalized to `'GET'`.

#### Test Case 3: Invalid method throws
```php
use Razy\Route;

$this->expectException(\InvalidArgumentException::class);

$route = new Route('api/users');
$route->method('INVALID');
```
**Expected Result:** `\InvalidArgumentException` is thrown for unsupported HTTP methods.

---

### `getMethod()` — Get method
**Description:** Returns the HTTP method constraint string. Defaults to `'*'` (match any method).

#### Test Case 1: Default method
```php
use Razy\Route;

$route = new Route('home');
$this->assertSame('*', $route->getMethod());
```
**Expected Result:** Default method is `'*'`.

---

### `middleware(middleware)` — Set middleware
**Description:** Attaches one or more middleware (implementing `MiddlewareInterface` or `Closure`) to the route. Middleware is appended in order.

#### Test Case 1: Add closure middleware
```php
use Razy\Route;

$mw = function (array $ctx, \Closure $next) { return $next($ctx); };
$route = new Route('admin/panel');
$result = $route->middleware($mw);

$this->assertSame($route, $result);  // fluent
$this->assertCount(1, $route->getMiddleware());
$this->assertTrue($route->hasMiddleware());
```
**Expected Result:** Middleware is stored and `hasMiddleware()` returns `true`.

#### Test Case 2: Add multiple middleware
```php
use Razy\Route;

$mw1 = function (array $ctx, \Closure $next) { return $next($ctx); };
$mw2 = function (array $ctx, \Closure $next) { return $next($ctx); };
$route = new Route('admin/panel');
$route->middleware($mw1, $mw2);

$this->assertCount(2, $route->getMiddleware());
```
**Expected Result:** Both middleware are added in order.

---

### `getMiddleware()` / `hasMiddleware()`
**Description:** `getMiddleware()` returns the array of attached middleware. `hasMiddleware()` returns `true` if the route has at least one middleware.

#### Test Case 1: No middleware
```php
use Razy\Route;

$route = new Route('public/page');

$this->assertSame([], $route->getMiddleware());
$this->assertFalse($route->hasMiddleware());
```
**Expected Result:** Empty array, `hasMiddleware()` returns `false`.

---

### `name(name)` — Set name
**Description:** Assigns a name to the route for named lookups and URL generation. Names must start with a letter or underscore and may contain alphanumeric characters, dots, hyphens, and underscores.

#### Test Case 1: Set and get name
```php
use Razy\Route;

$route = new Route('users/index');
$result = $route->name('users.index');

$this->assertSame($route, $result);
$this->assertSame('users.index', $route->getName());
$this->assertTrue($route->hasName());
```
**Expected Result:** Name is stored and `hasName()` returns `true`.

#### Test Case 2: Invalid name throws
```php
use Razy\Route;

$this->expectException(\InvalidArgumentException::class);

$route = new Route('users/index');
$route->name('123-invalid');
```
**Expected Result:** `\InvalidArgumentException` is thrown because a name cannot start with a digit.

#### Test Case 3: Empty name throws
```php
use Razy\Route;

$this->expectException(\InvalidArgumentException::class);

$route = new Route('users/index');
$route->name('');
```
**Expected Result:** `\InvalidArgumentException` is thrown for empty name.

---

### `getName()` / `hasName()`
**Description:** `getName()` returns the route name or `null` if none is set. `hasName()` checks whether a name has been assigned.

#### Test Case 1: Unnamed route
```php
use Razy\Route;

$route = new Route('home');

$this->assertNull($route->getName());
$this->assertFalse($route->hasName());
```
**Expected Result:** `null` name and `false` from `hasName()`.

---

## 2. Routing\RouteGroup

### `create(prefix, callback)` — Create group with prefix
**Description:** Static factory that creates a new `RouteGroup` with the given URL prefix.

#### Test Case 1: Create with prefix
```php
use Razy\Routing\RouteGroup;

$group = RouteGroup::create('/api/v1');

$this->assertInstanceOf(RouteGroup::class, $group);
$this->assertSame('api/v1', $group->getPrefix());
```
**Expected Result:** The group is created and the prefix is trimmed of leading/trailing slashes.

#### Test Case 2: Create with empty prefix
```php
use Razy\Routing\RouteGroup;

$group = RouteGroup::create('');

$this->assertSame('', $group->getPrefix());
```
**Expected Result:** An empty prefix is valid for root-level groups.

---

### `middleware(middleware)` — Set group middleware
**Description:** Attaches middleware to all routes within the group. Accepts `MiddlewareInterface` or `Closure` instances.

#### Test Case 1: Add middleware to group
```php
use Razy\Routing\RouteGroup;

$mw = function (array $ctx, \Closure $next) { return $next($ctx); };
$group = RouteGroup::create('/admin')->middleware($mw);

$this->assertCount(1, $group->getMiddleware());
```
**Expected Result:** Group stores the middleware.

---

### `namePrefix(prefix)` — Set name prefix
**Description:** Sets a name prefix automatically prepended to all named routes within the group.

#### Test Case 1: Set name prefix
```php
use Razy\Routing\RouteGroup;

$group = RouteGroup::create('/admin')->namePrefix('admin.');

$this->assertSame('admin.', $group->getNamePrefix());
```
**Expected Result:** The name prefix is stored as-is (dots are not auto-appended).

---

### `method(method)` — Set group method
**Description:** Sets a default HTTP method constraint for all routes in the group. Individual routes can override this.

#### Test Case 1: Set group method
```php
use Razy\Routing\RouteGroup;

$group = RouteGroup::create('/api')->method('GET');

$this->assertSame('GET', $group->getMethod());
```
**Expected Result:** The method defaults to the specified value for all routes.

---

### `routes(callback)` — Define routes
**Description:** Invokes a callback with the group instance for fluent inline route registration.

#### Test Case 1: Define routes via callback
```php
use Razy\Routing\RouteGroup;

$group = RouteGroup::create('/api')->routes(function (RouteGroup $g) {
    $g->addRoute('/users', 'api/users');
    $g->addRoute('/posts', 'api/posts');
});

$entries = $group->getEntries();
$this->assertCount(2, $entries);
$this->assertSame('/users', $entries[0]['path']);
$this->assertSame('/posts', $entries[1]['path']);
```
**Expected Result:** Both routes are registered in the group.

---

### `addRoute(path, callback)` — Add route
**Description:** Registers a standard route within the group. Handler can be a closure path string or a `Route` instance.

#### Test Case 1: Add route with string handler
```php
use Razy\Routing\RouteGroup;

$group = RouteGroup::create('/api');
$result = $group->addRoute('/users', 'controllers/users');

$this->assertSame($group, $result);  // fluent
$entries = $group->getEntries();
$this->assertCount(1, $entries);
$this->assertSame('Route', $entries[0]['routeType']);
```
**Expected Result:** Route is added as type `'Route'` and handler is the closure path string.

#### Test Case 2: Add route with Route object
```php
use Razy\Route;
use Razy\Routing\RouteGroup;

$route = (new Route('users/show'))->method('GET')->name('users.show');
$group = RouteGroup::create('/api');
$group->addRoute('/users/:id', $route);

$entries = $group->getEntries();
$this->assertInstanceOf(Route::class, $entries[0]['handler']);
```
**Expected Result:** The `Route` object is stored as the handler.

---

### `addLazyRoute(path)` — Add lazy route
**Description:** Registers a lazy-loaded route, resolved only when matched. Handler is always a `Route` or string.

#### Test Case 1: Add lazy route
```php
use Razy\Route;
use Razy\Routing\RouteGroup;

$group = RouteGroup::create('/api');
$group->addLazyRoute('/heavy', new Route('heavy/controller'));

$entries = $group->getEntries();
$this->assertSame('LazyRoute', $entries[0]['routeType']);
```
**Expected Result:** Entry has type `'LazyRoute'`.

---

### `group(prefix, callback)` — Nested group
**Description:** Creates a nested sub-group with an additional prefix. Attributes (middleware, method, name prefix) accumulate through nesting.

#### Test Case 1: Nested group
```php
use Razy\Routing\RouteGroup;

$group = RouteGroup::create('/api');
$group->group('/v1', function (RouteGroup $sub) {
    $sub->addRoute('/users', 'v1/users');
});

$entries = $group->getEntries();
$this->assertSame('group', $entries[0]['type']);
$this->assertSame('v1', $entries[0]['group']->getPrefix());
```
**Expected Result:** The nested group is stored with its own prefix.

---

### `resolve(path)` — Resolve route
**Description:** Flattens all routes into a list of resolved entries with accumulated prefix, middleware, name prefix, and method from parent groups.

#### Test Case 1: Resolve flat routes
```php
use Razy\Routing\RouteGroup;

$group = RouteGroup::create('/api')->routes(function (RouteGroup $g) {
    $g->addRoute('/users', 'api/users');
    $g->addRoute('/posts', 'api/posts');
});

$resolved = $group->resolve();

$this->assertCount(2, $resolved);
$this->assertSame('api/users', $resolved[0]['path']);
$this->assertSame('api/posts', $resolved[1]['path']);
```
**Expected Result:** Resolved paths include the group prefix combined with route paths.

#### Test Case 2: Resolve nested groups with middleware and name prefix
```php
use Razy\Route;
use Razy\Routing\RouteGroup;

$mw = function (array $ctx, \Closure $next) { return $next($ctx); };

$group = RouteGroup::create('/admin')
    ->middleware($mw)
    ->namePrefix('admin.')
    ->method('GET')
    ->routes(function (RouteGroup $g) {
        $g->addRoute('/dashboard', (new Route('admin/dashboard'))->name('dashboard'));
        $g->group('/settings', function (RouteGroup $sub) {
            $sub->addRoute('/profile', (new Route('settings/profile'))->name('profile'));
        });
    });

$resolved = $group->resolve();

$this->assertCount(2, $resolved);
$this->assertSame('admin/dashboard', $resolved[0]['path']);
$this->assertSame('admin/settings/profile', $resolved[1]['path']);

// Resolved route inherits group middleware, name prefix, and method
$dashRoute = $resolved[0]['handler'];
$this->assertInstanceOf(Route::class, $dashRoute);
$this->assertSame('admin.dashboard', $dashRoute->getName());
$this->assertSame('GET', $dashRoute->getMethod());
$this->assertTrue($dashRoute->hasMiddleware());
```
**Expected Result:** Nested routes have accumulated prefixes, middleware, name prefixes (`admin.dashboard`, `admin.profile`), and method constraints.

---

## 3. Distributor\MiddlewarePipeline

### `pipe(middleware)` — Add single middleware
**Description:** Appends a single middleware to the pipeline stack. Accepts objects implementing `MiddlewareInterface` or `Closure`.

#### Test Case 1: Pipe a closure middleware
```php
use Razy\Distributor\MiddlewarePipeline;

$pipeline = new MiddlewarePipeline();
$mw = function (array $ctx, \Closure $next) { return $next($ctx); };
$result = $pipeline->pipe($mw);

$this->assertSame($pipeline, $result);  // fluent
$this->assertCount(1, $pipeline->getMiddleware());
$this->assertFalse($pipeline->isEmpty());
```
**Expected Result:** Middleware is added, pipeline is no longer empty.

---

### `pipeMany(middlewares)` — Add multiple
**Description:** Appends an array of middleware to the pipeline in order.

#### Test Case 1: Pipe multiple middleware
```php
use Razy\Distributor\MiddlewarePipeline;

$pipeline = new MiddlewarePipeline();
$mw1 = function (array $ctx, \Closure $next) {
    $ctx['log'][] = 'mw1';
    return $next($ctx);
};
$mw2 = function (array $ctx, \Closure $next) {
    $ctx['log'][] = 'mw2';
    return $next($ctx);
};
$pipeline->pipeMany([$mw1, $mw2]);

$this->assertCount(2, $pipeline->count());
```
**Expected Result:** Both middleware are added; count is 2.

---

### `process(context, coreHandler)` — Execute pipeline
**Description:** Executes the middleware pipeline in FIFO order around the core handler. The first middleware added is the outermost layer.

#### Test Case 1: Middleware executes in FIFO order
```php
use Razy\Distributor\MiddlewarePipeline;

$pipeline = new MiddlewarePipeline();
$log = [];

$pipeline->pipe(function (array $ctx, \Closure $next) use (&$log) {
    $log[] = 'mw1:before';
    $result = $next($ctx);
    $log[] = 'mw1:after';
    return $result;
});

$pipeline->pipe(function (array $ctx, \Closure $next) use (&$log) {
    $log[] = 'mw2:before';
    $result = $next($ctx);
    $log[] = 'mw2:after';
    return $result;
});

$result = $pipeline->process(['key' => 'value'], function (array $ctx) use (&$log) {
    $log[] = 'handler';
    return 'done';
});

$this->assertSame('done', $result);
$this->assertSame(['mw1:before', 'mw2:before', 'handler', 'mw2:after', 'mw1:after'], $log);
```
**Expected Result:** Middleware wraps the handler in onion-style: MW1 → MW2 → Handler → MW2 → MW1.

#### Test Case 2: Middleware can short-circuit
```php
use Razy\Distributor\MiddlewarePipeline;

$pipeline = new MiddlewarePipeline();

$pipeline->pipe(function (array $ctx, \Closure $next) {
    return 'blocked';  // Never calls $next
});

$pipeline->pipe(function (array $ctx, \Closure $next) {
    return $next($ctx);
});

$result = $pipeline->process([], function (array $ctx) {
    return 'handler reached';
});

$this->assertSame('blocked', $result);
```
**Expected Result:** The first middleware short-circuits; handler never executes.

#### Test Case 3: Empty pipeline passes directly to handler
```php
use Razy\Distributor\MiddlewarePipeline;

$pipeline = new MiddlewarePipeline();

$result = $pipeline->process(['name' => 'Razy'], function (array $ctx) {
    return $ctx['name'];
});

$this->assertSame('Razy', $result);
```
**Expected Result:** With no middleware, the core handler is called directly.

---

### `isEmpty()` / `count()` / `getMiddleware()`
**Description:** `isEmpty()` returns `true` when the pipeline has no middleware. `count()` returns the number of middleware. `getMiddleware()` returns the ordered array.

#### Test Case 1: Empty pipeline
```php
use Razy\Distributor\MiddlewarePipeline;

$pipeline = new MiddlewarePipeline();

$this->assertTrue($pipeline->isEmpty());
$this->assertSame(0, $pipeline->count());
$this->assertSame([], $pipeline->getMiddleware());
```
**Expected Result:** All accessors reflect an empty state.

---

## 4. Distributor\MiddlewareGroupRegistry

### `define(name, middlewares[])` — Define group
**Description:** Registers a named middleware group, overwriting any existing group with the same name.

#### Test Case 1: Define and resolve a group
```php
use Razy\Distributor\MiddlewareGroupRegistry;

$registry = new MiddlewareGroupRegistry();
$mw1 = function (array $ctx, \Closure $next) { return $next($ctx); };
$mw2 = function (array $ctx, \Closure $next) { return $next($ctx); };

$result = $registry->define('web', [$mw1, $mw2]);

$this->assertSame($registry, $result);  // fluent
$this->assertTrue($registry->has('web'));
$this->assertCount(2, $registry->resolve('web'));
```
**Expected Result:** The `'web'` group is defined with two middleware.

#### Test Case 2: Define overwrites existing
```php
use Razy\Distributor\MiddlewareGroupRegistry;

$registry = new MiddlewareGroupRegistry();
$mw1 = function (array $ctx, \Closure $next) { return $next($ctx); };
$mw2 = function (array $ctx, \Closure $next) { return $next($ctx); };

$registry->define('api', [$mw1]);
$registry->define('api', [$mw2]);

$resolved = $registry->resolve('api');
$this->assertCount(1, $resolved);
$this->assertSame($mw2, $resolved[0]);
```
**Expected Result:** The second `define()` call replaces the entire group.

---

### `appendTo(name, middleware)` — Append to group
**Description:** Appends middleware to an existing group. Creates the group if it does not exist.

#### Test Case 1: Append to existing group
```php
use Razy\Distributor\MiddlewareGroupRegistry;

$registry = new MiddlewareGroupRegistry();
$mw1 = function (array $ctx, \Closure $next) { return $next($ctx); };
$mw2 = function (array $ctx, \Closure $next) { return $next($ctx); };

$registry->define('web', [$mw1]);
$registry->appendTo('web', [$mw2]);

$resolved = $registry->resolve('web');
$this->assertCount(2, $resolved);
$this->assertSame($mw1, $resolved[0]);
$this->assertSame($mw2, $resolved[1]);
```
**Expected Result:** `$mw2` is appended after `$mw1`.

#### Test Case 2: Append creates group if missing
```php
use Razy\Distributor\MiddlewareGroupRegistry;

$registry = new MiddlewareGroupRegistry();
$mw = function (array $ctx, \Closure $next) { return $next($ctx); };

$registry->appendTo('new-group', [$mw]);

$this->assertTrue($registry->has('new-group'));
$this->assertCount(1, $registry->resolve('new-group'));
```
**Expected Result:** The group is auto-created with the appended middleware.

---

### `prependTo(name, middleware)` — Prepend to group
**Description:** Prepends middleware to the front of an existing group. Creates the group if it does not exist.

#### Test Case 1: Prepend to existing group
```php
use Razy\Distributor\MiddlewareGroupRegistry;

$registry = new MiddlewareGroupRegistry();
$mw1 = function (array $ctx, \Closure $next) { return $next($ctx); };
$mw2 = function (array $ctx, \Closure $next) { return $next($ctx); };

$registry->define('web', [$mw1]);
$registry->prependTo('web', [$mw2]);

$resolved = $registry->resolve('web');
$this->assertCount(2, $resolved);
$this->assertSame($mw2, $resolved[0]);  // prepended
$this->assertSame($mw1, $resolved[1]);
```
**Expected Result:** `$mw2` appears before `$mw1` in the resolved list.

---

### `resolve(name)` — Resolve group to middlewares
**Description:** Returns the middleware array for a named group. Throws `\InvalidArgumentException` if the group is not defined.

#### Test Case 1: Resolve undefined group throws
```php
use Razy\Distributor\MiddlewareGroupRegistry;

$registry = new MiddlewareGroupRegistry();

$this->expectException(\InvalidArgumentException::class);
$this->expectExceptionMessage("Middleware group 'nonexistent' is not defined.");

$registry->resolve('nonexistent');
```
**Expected Result:** `\InvalidArgumentException` is thrown with a descriptive message.

---

### `resolveMany(names[])` — Resolve multiple groups
**Description:** Resolves a mix of group names, `MiddlewareInterface` instances, and closures into a flat middleware list.

#### Test Case 1: Resolve mixed inputs
```php
use Razy\Distributor\MiddlewareGroupRegistry;

$registry = new MiddlewareGroupRegistry();
$mw1 = function (array $ctx, \Closure $next) { return $next($ctx); };
$mw2 = function (array $ctx, \Closure $next) { return $next($ctx); };
$mw3 = function (array $ctx, \Closure $next) { return $next($ctx); };

$registry->define('web', [$mw1, $mw2]);

$resolved = $registry->resolveMany(['web', $mw3]);

$this->assertCount(3, $resolved);
$this->assertSame($mw1, $resolved[0]);
$this->assertSame($mw2, $resolved[1]);
$this->assertSame($mw3, $resolved[2]);
```
**Expected Result:** Group names are expanded into their middleware, and standalone middleware is passed through as-is.

---

### `has(name)` / `getGroupNames()` / `count()` / `remove(name)`
**Description:** Utility methods for inspecting and managing the registry.

#### Test Case 1: Registry inspection
```php
use Razy\Distributor\MiddlewareGroupRegistry;

$registry = new MiddlewareGroupRegistry();
$mw = function (array $ctx, \Closure $next) { return $next($ctx); };

$registry->define('web', [$mw]);
$registry->define('api', [$mw]);

$this->assertTrue($registry->has('web'));
$this->assertFalse($registry->has('cli'));
$this->assertSame(['web', 'api'], $registry->getGroupNames());
$this->assertSame(2, $registry->count());
```
**Expected Result:** `has()` accurately checks existence, `getGroupNames()` lists all group names, `count()` reflects total groups.

#### Test Case 2: Remove a group
```php
use Razy\Distributor\MiddlewareGroupRegistry;

$registry = new MiddlewareGroupRegistry();
$mw = function (array $ctx, \Closure $next) { return $next($ctx); };

$registry->define('web', [$mw]);
$registry->remove('web');

$this->assertFalse($registry->has('web'));
$this->assertSame(0, $registry->count());
```
**Expected Result:** After removal, the group no longer exists.

---

## 5. Http\HttpClient (Fluent HTTP client)

### `create()` — Create new client
**Description:** Static factory that returns a new `HttpClient` instance with default configuration.

#### Test Case 1: Create returns HttpClient
```php
use Razy\Http\HttpClient;

$client = HttpClient::create();

$this->assertInstanceOf(HttpClient::class, $client);
```
**Expected Result:** A new `HttpClient` instance is returned.

---

### `baseUrl(url)` — Set base URL
**Description:** Sets the base URL prepended to relative request paths. Trailing slashes are stripped.

#### Test Case 1: Base URL is set and trimmed
```php
use Razy\Http\HttpClient;

// Illustrative — the baseUrl is stored internally.
// Verification is done via the resulting request URL.
$client = HttpClient::create()->baseUrl('https://api.example.com/v1/');

// When sending, '/users' becomes 'https://api.example.com/v1/users'
$this->assertInstanceOf(HttpClient::class, $client);
```
**Expected Result:** The client stores the base URL (trailing slash removed) and uses it to resolve relative paths.

---

### `withHeaders(headers)` / `withHeader(name, value)`
**Description:** `withHeaders()` merges an array of headers into the client. `withHeader()` sets a single header. Header names are normalized to lowercase.

#### Test Case 1: Set headers fluently
```php
use Razy\Http\HttpClient;

$client = HttpClient::create()
    ->withHeaders(['Accept' => 'application/json', 'X-Custom' => 'value'])
    ->withHeader('X-Request-ID', 'abc123');

$this->assertInstanceOf(HttpClient::class, $client);
```
**Expected Result:** Headers are stored (lowercase keys) and sent with every request.

---

### `withToken(token)` / `withBasicAuth(user, pass)`
**Description:** `withToken()` sets a Bearer token for the `Authorization` header. `withBasicAuth()` sets HTTP Basic authentication credentials.

#### Test Case 1: Bearer token
```php
use Razy\Http\HttpClient;

$client = HttpClient::create()->withToken('my-api-token-123');

// Internally sets Authorization: Bearer my-api-token-123
$this->assertInstanceOf(HttpClient::class, $client);
```
**Expected Result:** The client sends `Authorization: Bearer my-api-token-123` with requests.

#### Test Case 2: Basic auth
```php
use Razy\Http\HttpClient;

$client = HttpClient::create()->withBasicAuth('admin', 's3cret');

// Internally uses CURLOPT_USERPWD
$this->assertInstanceOf(HttpClient::class, $client);
```
**Expected Result:** The client uses HTTP Basic authentication with the given credentials.

---

### `timeout(seconds)` / `connectTimeout(seconds)`
**Description:** `timeout()` sets the total request timeout. `connectTimeout()` sets the connection establishment timeout. Both accept seconds.

#### Test Case 1: Set timeouts
```php
use Razy\Http\HttpClient;

$client = HttpClient::create()
    ->timeout(60)
    ->connectTimeout(5);

$this->assertInstanceOf(HttpClient::class, $client);
```
**Expected Result:** The client uses 60s total timeout and 5s connection timeout.

---

### `withoutVerifying()` / `withVerifying()`
**Description:** `withoutVerifying()` disables SSL certificate verification (for development). `withVerifying()` re-enables it (default).

#### Test Case 1: Toggle SSL verification
```php
use Razy\Http\HttpClient;

$client = HttpClient::create()
    ->withoutVerifying();  // Disables SSL verification

// Re-enable:
$client->withVerifying();

$this->assertInstanceOf(HttpClient::class, $client);
```
**Expected Result:** SSL verification is disabled then re-enabled.

---

### `asJson()` / `asForm()` / `asMultipart()`
**Description:** Set the body encoding format. `asJson()` sends JSON (default). `asForm()` sends `application/x-www-form-urlencoded`. `asMultipart()` sends `multipart/form-data`.

#### Test Case 1: Body format switching
```php
use Razy\Http\HttpClient;

$client = HttpClient::create()->asForm();
// POST data will be sent as form-encoded

$client->asJson();
// Back to JSON encoding

$client->asMultipart();
// Multipart form data

$this->assertInstanceOf(HttpClient::class, $client);
```
**Expected Result:** Each call sets the internal body format accordingly.

---

### `withQuery(params)`
**Description:** Merges additional query parameters appended to every request URL.

#### Test Case 1: Set query params
```php
use Razy\Http\HttpClient;

$client = HttpClient::create()
    ->withQuery(['api_key' => 'abc', 'format' => 'json']);

// All requests will include ?api_key=abc&format=json
$this->assertInstanceOf(HttpClient::class, $client);
```
**Expected Result:** Query parameters are appended to all subsequent requests.

---

### `userAgent(ua)` / `retry(times, sleep)`
**Description:** `userAgent()` sets a custom User-Agent string. `retry()` configures automatic retry on failure with a delay between attempts.

#### Test Case 1: Set user agent
```php
use Razy\Http\HttpClient;

$client = HttpClient::create()->userAgent('RazyBot/1.0');

$this->assertInstanceOf(HttpClient::class, $client);
```
**Expected Result:** The User-Agent header is set to `'RazyBot/1.0'`.

#### Test Case 2: Configure retries
```php
use Razy\Http\HttpClient;

$client = HttpClient::create()->retry(3, 200);

// Will retry up to 3 times with 200ms delay on 429/5xx status codes
$this->assertInstanceOf(HttpClient::class, $client);
```
**Expected Result:** The client retries failed requests up to 3 times with a 200ms delay.

---

### `beforeSending(callback)` / `afterResponse(callback)`
**Description:** `beforeSending()` registers a pre-request interceptor. `afterResponse()` registers a post-response interceptor.

#### Test Case 1: Register interceptors
```php
use Razy\Http\HttpClient;
use Razy\Http\HttpResponse;

$beforeCalled = false;
$afterCalled = false;

$client = HttpClient::create()
    ->beforeSending(function ($ch, $method, $url, $options) use (&$beforeCalled) {
        $beforeCalled = true;
    })
    ->afterResponse(function (HttpResponse $response, $method, $url) use (&$afterCalled) {
        $afterCalled = true;
    });

$this->assertInstanceOf(HttpClient::class, $client);
```
**Expected Result:** Interceptors are registered and will be invoked on request/response.

---

### `get/post/put/patch/delete/head/options/send(url, data)`
**Description:** HTTP verb methods that send requests. All return an `HttpResponse`. `get()` and `head()` accept query parameters; others accept body data. `send()` is the low-level method accepting method, URL, and options.

#### Test Case 1: GET request (illustrative)
```php
use Razy\Http\HttpClient;
use Razy\Http\HttpResponse;

// Note: This requires a running HTTP server or mock.
// Shown for API reference; real tests should use a local test server.
$client = HttpClient::create()
    ->baseUrl('https://jsonplaceholder.typicode.com')
    ->timeout(10);

// $response = $client->get('/posts/1');
// $this->assertInstanceOf(HttpResponse::class, $response);
// $this->assertTrue($response->successful());
// $this->assertSame(200, $response->status());
// $this->assertNotEmpty($response->json());
```
**Expected Result:** An `HttpResponse` wrapping the API response is returned.

#### Test Case 2: POST request (illustrative)
```php
use Razy\Http\HttpClient;
use Razy\Http\HttpResponse;

$client = HttpClient::create()
    ->baseUrl('https://jsonplaceholder.typicode.com')
    ->asJson()
    ->timeout(10);

// $response = $client->post('/posts', [
//     'title'  => 'Test Post',
//     'body'   => 'This is a test.',
//     'userId' => 1,
// ]);
// $this->assertInstanceOf(HttpResponse::class, $response);
// $this->assertSame(201, $response->status());
```
**Expected Result:** A `201 Created` response with the created resource.

#### Test Case 3: send() with custom method
```php
use Razy\Http\HttpClient;

$client = HttpClient::create()
    ->baseUrl('https://api.example.com');

// $response = $client->send('PATCH', '/users/1', [
//     'body' => ['name' => 'Updated Name'],
// ]);
// $this->assertInstanceOf(HttpResponse::class, $response);
```
**Expected Result:** The custom method is used to send the request.

---

## 6. Http\HttpResponse

### `status()` — HTTP status code
**Description:** Returns the integer HTTP status code of the response.

#### Test Case 1: Get status code
```php
use Razy\Http\HttpResponse;

$response = new HttpResponse(200, '{"ok":true}', ['content-type' => 'application/json']);

$this->assertSame(200, $response->status());
```
**Expected Result:** Returns `200`.

---

### `successful()` / `ok()` / `failed()` / `redirect()` / `clientError()` / `serverError()`
**Description:** Status classification helpers. `successful()`/`ok()` → 2xx, `failed()` → 4xx or 5xx, `redirect()` → 3xx, `clientError()` → 4xx, `serverError()` → 5xx.

#### Test Case 1: 200 OK response
```php
use Razy\Http\HttpResponse;

$response = new HttpResponse(200, 'OK');

$this->assertTrue($response->successful());
$this->assertTrue($response->ok());
$this->assertFalse($response->failed());
$this->assertFalse($response->redirect());
$this->assertFalse($response->clientError());
$this->assertFalse($response->serverError());
```
**Expected Result:** Only `successful()` and `ok()` return `true`.

#### Test Case 2: 404 Not Found
```php
use Razy\Http\HttpResponse;

$response = new HttpResponse(404, 'Not Found');

$this->assertFalse($response->successful());
$this->assertTrue($response->failed());
$this->assertTrue($response->clientError());
$this->assertFalse($response->serverError());
```
**Expected Result:** `failed()` and `clientError()` return `true`.

#### Test Case 3: 500 Internal Server Error
```php
use Razy\Http\HttpResponse;

$response = new HttpResponse(500, 'Server Error');

$this->assertTrue($response->failed());
$this->assertFalse($response->clientError());
$this->assertTrue($response->serverError());
```
**Expected Result:** `failed()` and `serverError()` return `true`.

#### Test Case 4: 302 Redirect
```php
use Razy\Http\HttpResponse;

$response = new HttpResponse(302, '', ['location' => '/new-url']);

$this->assertTrue($response->redirect());
$this->assertFalse($response->successful());
$this->assertFalse($response->failed());
```
**Expected Result:** Only `redirect()` returns `true`.

---

### `body()` — Raw body
**Description:** Returns the raw response body as a string.

#### Test Case 1: Get raw body
```php
use Razy\Http\HttpResponse;

$response = new HttpResponse(200, '<html>Hello</html>');

$this->assertSame('<html>Hello</html>', $response->body());
```
**Expected Result:** The exact raw body string is returned.

---

### `json()` / `jsonGet(key, default)` — Parse JSON
**Description:** `json()` decodes the body as JSON (associative array by default). `jsonGet()` retrieves a specific value using dot notation.

#### Test Case 1: Parse JSON body
```php
use Razy\Http\HttpResponse;

$response = new HttpResponse(200, '{"name":"Razy","version":"0.5"}');

$data = $response->json();
$this->assertIsArray($data);
$this->assertSame('Razy', $data['name']);
$this->assertSame('0.5', $data['version']);
```
**Expected Result:** JSON is decoded into an associative array.

#### Test Case 2: Get nested value with dot notation
```php
use Razy\Http\HttpResponse;

$json = json_encode([
    'data' => [
        'users' => [
            ['name' => 'Alice'],
            ['name' => 'Bob'],
        ],
    ],
]);
$response = new HttpResponse(200, $json);

$this->assertSame('Alice', $response->jsonGet('data.users.0.name'));
$this->assertSame('Bob', $response->jsonGet('data.users.1.name'));
$this->assertSame('default', $response->jsonGet('data.missing', 'default'));
```
**Expected Result:** Dot notation traverses nested arrays. Missing keys return the default.

#### Test Case 3: Invalid JSON returns null
```php
use Razy\Http\HttpResponse;

$response = new HttpResponse(200, 'not json');

$this->assertNull($response->json());
```
**Expected Result:** `json()` returns `null` for non-JSON bodies.

---

### `headers()` / `header(name)` / `hasHeader(name)` / `contentType()`
**Description:** Access response headers. `header()` and `hasHeader()` are case-insensitive. `contentType()` is a shortcut for the `Content-Type` header.

#### Test Case 1: Access headers
```php
use Razy\Http\HttpResponse;

$response = new HttpResponse(200, '{}', [
    'content-type' => 'application/json',
    'x-request-id' => 'abc-123',
]);

$this->assertSame('application/json', $response->header('Content-Type'));
$this->assertSame('abc-123', $response->header('X-Request-ID'));
$this->assertTrue($response->hasHeader('content-type'));
$this->assertFalse($response->hasHeader('x-missing'));
$this->assertSame('application/json', $response->contentType());
```
**Expected Result:** Headers are accessible case-insensitively with `contentType()` as a convenience.

#### Test Case 2: Missing header returns default
```php
use Razy\Http\HttpResponse;

$response = new HttpResponse(200, '', []);

$this->assertNull($response->header('X-Missing'));
$this->assertSame('fallback', $response->header('X-Missing', 'fallback'));
```
**Expected Result:** Returns `null` by default or the specified fallback.

---

### `throw()` / `throwIf(condition)` — Throw on error
**Description:** `throw()` throws `HttpException` if the response status is >= 400. `throwIf()` only throws when the condition is `true` and the response failed.

#### Test Case 1: Throw on failure
```php
use Razy\Http\HttpResponse;
use Razy\Http\HttpException;

$response = new HttpResponse(500, 'Internal Server Error');

try {
    $response->throw();
    $this->fail('Expected HttpException');
} catch (HttpException $e) {
    $this->assertSame(500, $e->getCode());
    $this->assertSame($response, $e->getResponse());
}
```
**Expected Result:** `HttpException` is thrown with status code 500 and access to the original response.

#### Test Case 2: Throw does nothing on success
```php
use Razy\Http\HttpResponse;

$response = new HttpResponse(200, 'OK');
$result = $response->throw();

$this->assertSame($response, $result);  // fluent, no exception
```
**Expected Result:** No exception thrown; returns `$this`.

#### Test Case 3: throwIf with condition
```php
use Razy\Http\HttpResponse;
use Razy\Http\HttpException;

$response = new HttpResponse(403, 'Forbidden');

// Condition true + failed → throws
$this->expectException(HttpException::class);
$response->throwIf(true);
```
**Expected Result:** Exception is thrown because the condition is `true` and the response failed.

#### Test Case 4: throwIf with false condition
```php
use Razy\Http\HttpResponse;

$response = new HttpResponse(403, 'Forbidden');

$result = $response->throwIf(false);
$this->assertSame($response, $result);  // no exception
```
**Expected Result:** No exception thrown when condition is `false`.

---

### `toArray()` — Convert to array
**Description:** Converts the response to an array with `status`, `headers`, and `body` keys.

#### Test Case 1: Convert to array
```php
use Razy\Http\HttpResponse;

$response = new HttpResponse(201, '{"id":1}', ['content-type' => 'application/json']);

$arr = $response->toArray();
$this->assertSame(201, $arr['status']);
$this->assertSame('{"id":1}', $arr['body']);
$this->assertSame('application/json', $arr['headers']['content-type']);
```
**Expected Result:** Array with `status`, `headers`, and `body` keys.

---

## 7. Session\Session

### `start()` / `save()` / `destroy()`
**Description:** `start()` loads session data from the driver and begins the session. `save()` persists data and ages flash data. `destroy()` wipes the session and closes the driver.

#### Test Case 1: Start and save session
```php
use Razy\Session\Session;
use Razy\Session\SessionConfig;
use Razy\Session\Driver\ArrayDriver;

$session = new Session(new ArrayDriver(), new SessionConfig(gcProbability: 0));

$this->assertFalse($session->isStarted());
$session->start();
$this->assertTrue($session->isStarted());

$session->set('user', 'Alice');
$session->save();

$this->assertFalse($session->isStarted());
```
**Expected Result:** Session starts, data can be set, and save closes the session.

#### Test Case 2: Destroy session
```php
use Razy\Session\Session;
use Razy\Session\SessionConfig;
use Razy\Session\Driver\ArrayDriver;

$session = new Session(new ArrayDriver(), new SessionConfig(gcProbability: 0));
$session->start();
$session->set('key', 'value');
$session->destroy();

$this->assertFalse($session->isStarted());

// Start a new session to verify data was wiped
$session->start();
$this->assertNull($session->get('key'));
```
**Expected Result:** After `destroy()`, all session data is removed.

#### Test Case 3: Start is idempotent
```php
use Razy\Session\Session;
use Razy\Session\SessionConfig;
use Razy\Session\Driver\ArrayDriver;

$session = new Session(new ArrayDriver(), new SessionConfig(gcProbability: 0));

$this->assertTrue($session->start());
$this->assertTrue($session->start());  // second call returns true, no error
```
**Expected Result:** Calling `start()` twice is safe and returns `true` both times.

---

### `isStarted()` / `getId()` / `setId()` / `regenerate()`
**Description:** `isStarted()` checks session state. `getId()`/`setId()` manage the session ID. `regenerate()` creates a new session ID, optionally destroying the old one.

#### Test Case 1: Session ID management
```php
use Razy\Session\Session;
use Razy\Session\SessionConfig;
use Razy\Session\Driver\ArrayDriver;

$session = new Session(new ArrayDriver(), new SessionConfig(gcProbability: 0));

$this->assertSame('', $session->getId());

$session->setId('custom-session-id');
$this->assertSame('custom-session-id', $session->getId());

$session->start();
$this->assertSame('custom-session-id', $session->getId());
```
**Expected Result:** ID can be set before `start()`, and is used during the session.

#### Test Case 2: Regenerate session ID
```php
use Razy\Session\Session;
use Razy\Session\SessionConfig;
use Razy\Session\Driver\ArrayDriver;

$session = new Session(new ArrayDriver(), new SessionConfig(gcProbability: 0));
$session->start();
$oldId = $session->getId();

$result = $session->regenerate();

$this->assertTrue($result);
$this->assertNotSame($oldId, $session->getId());
$this->assertNotEmpty($session->getId());
```
**Expected Result:** `regenerate()` returns `true` and produces a new, different ID.

#### Test Case 3: Regenerate with destroy old
```php
use Razy\Session\Session;
use Razy\Session\SessionConfig;
use Razy\Session\Driver\ArrayDriver;

$driver = new ArrayDriver();
$session = new Session($driver, new SessionConfig(gcProbability: 0));
$session->start();
$session->set('name', 'Alice');
$session->save();

$oldId = $session->getId();
$session->start();
$session->regenerate(destroyOld: true);
$newId = $session->getId();
$session->save();

// Old session data was destroyed in the driver
$this->assertEmpty($driver->read($oldId));
$this->assertNotEmpty($driver->read($newId));
```
**Expected Result:** The old session ID's data is wiped from the driver.

---

### `get(key, default)` / `set(key, value)` / `has(key)` / `remove(key)` / `all()` / `clear()`
**Description:** Data access methods for session attributes. `get()` supports a default value. `all()` returns all attributes. `clear()` removes everything.

#### Test Case 1: CRUD operations
```php
use Razy\Session\Session;
use Razy\Session\SessionConfig;
use Razy\Session\Driver\ArrayDriver;

$session = new Session(new ArrayDriver(), new SessionConfig(gcProbability: 0));
$session->start();

$session->set('lang', 'en');
$this->assertTrue($session->has('lang'));
$this->assertSame('en', $session->get('lang'));

$session->remove('lang');
$this->assertFalse($session->has('lang'));
$this->assertSame('default', $session->get('lang', 'default'));
```
**Expected Result:** Set, get, check, and remove work as expected with default values.

#### Test Case 2: Get all and clear
```php
use Razy\Session\Session;
use Razy\Session\SessionConfig;
use Razy\Session\Driver\ArrayDriver;

$session = new Session(new ArrayDriver(), new SessionConfig(gcProbability: 0));
$session->start();

$session->set('a', 1);
$session->set('b', 2);

$all = $session->all();
$this->assertArrayHasKey('a', $all);
$this->assertArrayHasKey('b', $all);

$session->clear();
$this->assertSame([], $session->all());
```
**Expected Result:** `all()` returns all attributes; `clear()` empties them.

---

### `flash(key, value)` / `getFlash(key, default)` / `hasFlash(key)` / `reflash()` / `keep(keys)`
**Description:** Flash data lives for one request cycle. On `save()`, "new" flash keys become "old", and old keys are removed. `reflash()` promotes all old keys back to new. `keep()` selectively promotes specific keys.

#### Test Case 1: Basic flash lifecycle
```php
use Razy\Session\Session;
use Razy\Session\SessionConfig;
use Razy\Session\Driver\ArrayDriver;

$session = new Session(new ArrayDriver(), new SessionConfig(gcProbability: 0));
$session->start();

$session->flash('message', 'Item saved!');
$this->assertTrue($session->hasFlash('message'));
$this->assertSame('Item saved!', $session->getFlash('message'));

// First save: flash moves from "new" to "old"
$session->save();

// Simulate next request
$session->start();
$this->assertTrue($session->hasFlash('message'));  // still available

// Second save: old flash data is removed
$session->save();

$session->start();
$this->assertFalse($session->hasFlash('message'));  // gone
```
**Expected Result:** Flash data survives one `save()` cycle and is removed on the second.

#### Test Case 2: Reflash keeps all flash data
```php
use Razy\Session\Session;
use Razy\Session\SessionConfig;
use Razy\Session\Driver\ArrayDriver;

$session = new Session(new ArrayDriver(), new SessionConfig(gcProbability: 0));
$session->start();

$session->flash('error', 'Validation failed');
$session->save();

// Next request — flash is now "old"
$session->start();
$session->reflash();  // promote old → new
$session->save();

// Third request — still available because we reflashed
$session->start();
$this->assertTrue($session->hasFlash('error'));
$this->assertSame('Validation failed', $session->getFlash('error'));
```
**Expected Result:** `reflash()` extends the flash data for one more request cycle.

#### Test Case 3: Keep specific flash keys
```php
use Razy\Session\Session;
use Razy\Session\SessionConfig;
use Razy\Session\Driver\ArrayDriver;

$session = new Session(new ArrayDriver(), new SessionConfig(gcProbability: 0));
$session->start();

$session->flash('msg', 'Hello');
$session->flash('error', 'Oops');
$session->save();

// Next request
$session->start();
$session->keep(['msg']);  // keep only 'msg', let 'error' expire
$session->save();

// Third request
$session->start();
$this->assertTrue($session->hasFlash('msg'));
$this->assertFalse($session->hasFlash('error'));
```
**Expected Result:** Only the kept key (`'msg'`) survives; `'error'` is removed.

#### Test Case 4: getFlash with default
```php
use Razy\Session\Session;
use Razy\Session\SessionConfig;
use Razy\Session\Driver\ArrayDriver;

$session = new Session(new ArrayDriver(), new SessionConfig(gcProbability: 0));
$session->start();

$this->assertSame('fallback', $session->getFlash('missing', 'fallback'));
```
**Expected Result:** Returns the default value when the flash key does not exist.

---

## 8. Session\SessionConfig (immutable VO)

### Constructor props
**Description:** `SessionConfig` is an immutable value object holding all session cookie and GC configuration. All properties are `readonly` with sensible defaults.

#### Test Case 1: Default values
```php
use Razy\Session\SessionConfig;

$config = new SessionConfig();

$this->assertSame('RAZY_SESSION', $config->name);
$this->assertSame(0, $config->lifetime);
$this->assertSame('/', $config->path);
$this->assertSame('', $config->domain);
$this->assertFalse($config->secure);
$this->assertTrue($config->httpOnly);
$this->assertSame('Lax', $config->sameSite);
$this->assertSame(1440, $config->gcMaxLifetime);
$this->assertSame(1, $config->gcProbability);
$this->assertSame(100, $config->gcDivisor);
```
**Expected Result:** All properties have documented defaults.

#### Test Case 2: Custom values
```php
use Razy\Session\SessionConfig;

$config = new SessionConfig(
    name: 'MY_APP_SID',
    lifetime: 7200,
    path: '/app',
    domain: '.example.com',
    secure: true,
    httpOnly: false,
    sameSite: 'Strict',
    gcMaxLifetime: 3600,
    gcProbability: 5,
    gcDivisor: 1000,
);

$this->assertSame('MY_APP_SID', $config->name);
$this->assertSame(7200, $config->lifetime);
$this->assertSame('/app', $config->path);
$this->assertSame('.example.com', $config->domain);
$this->assertTrue($config->secure);
$this->assertFalse($config->httpOnly);
$this->assertSame('Strict', $config->sameSite);
$this->assertSame(3600, $config->gcMaxLifetime);
$this->assertSame(5, $config->gcProbability);
$this->assertSame(1000, $config->gcDivisor);
```
**Expected Result:** All custom values are stored and readable via readonly properties.

---

### `with(overrides)` — Create modified copy
**Description:** Creates a new `SessionConfig` with selected properties overridden. The original instance is unchanged (immutability).

#### Test Case 1: Override a subset of properties
```php
use Razy\Session\SessionConfig;

$original = new SessionConfig();
$modified = $original->with([
    'name'    => 'CUSTOM_SID',
    'secure'  => true,
    'lifetime' => 3600,
]);

// Modified copy
$this->assertSame('CUSTOM_SID', $modified->name);
$this->assertTrue($modified->secure);
$this->assertSame(3600, $modified->lifetime);
// Non-overridden values preserved
$this->assertSame('/', $modified->path);
$this->assertTrue($modified->httpOnly);

// Original unchanged
$this->assertSame('RAZY_SESSION', $original->name);
$this->assertFalse($original->secure);
$this->assertSame(0, $original->lifetime);
```
**Expected Result:** A new config is returned with the overridden values. The original is not mutated.

#### Test Case 2: Override with empty array (clone)
```php
use Razy\Session\SessionConfig;

$original = new SessionConfig(name: 'ORIGINAL');
$clone = $original->with([]);

$this->assertSame('ORIGINAL', $clone->name);
$this->assertNotSame($original, $clone);  // different instance
```
**Expected Result:** An empty overrides array produces a new instance with identical values.
