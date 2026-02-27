# Advanced Features Examples

This document provides detailed examples for advanced Razy module features:
- `Agent::await()` - Wait for other modules before executing
- `addAPICommand()` with `#` prefix - Internal method binding
- Complex `addLazyRoute()` with nested structures
- `addShadowRoute()` - Route proxy to other modules

---

## 1. Agent::await() - Module Dependency Execution

The `await()` method defers execution of a callable until specified module(s) are ready (after `__onLoad`).

### Use Case
When your module needs to interact with another module that may not be loaded yet.

### Signature
```php
$agent->await(string $moduleCode, callable $caller): static
```

### Example: Waiting for a Single Module

```php
// In your Controller::__onInit()
public function __onInit(Agent $agent): int
{
    // Wait for 'vendor/auth' module to be ready before registering routes
    $agent->await('vendor/auth', function() use ($agent) {
        // This executes AFTER vendor/auth has completed __onLoad
        $authModule = $this->module->getDistributor()->getLoadedModule('vendor/auth');
        
        if ($authModule) {
            // Access auth module's API
            $api = $this->poke('vendor/auth');
            $api->registerProvider('mymodule', $this->getAuthCallback());
        }
        
        // Register routes that depend on auth being ready
        $agent->addRoute('/protected/(:a)', 'protected');
    });
    
    return ModuleStatus::LOADED;
}
```

### Example: Sequential Module Dependencies

```php
public function __onInit(Agent $agent): int
{
    // First, wait for database module
    $agent->await('vendor/database', function() use ($agent) {
        // Database is ready, now wait for cache
        $agent->await('vendor/cache', function() use ($agent) {
            // Both database and cache are ready
            $this->initializeDataLayer();
        });
    });
    
    return ModuleStatus::LOADED;
}
```

### How It Works
1. `await()` registers a callback with the Distributor
2. After all modules complete `__onLoad`, Distributor processes awaiting callbacks
3. Callbacks execute in registration order
4. If target module never loads, callback still executes (you should check if module exists)

---

## 2. addAPICommand() with `#` Prefix - Internal Binding

The `#` prefix in `addAPICommand()` creates both:
1. A public API command (accessible via module API)
2. An internal binding (accessible via `$this->methodName()` in Controller)

### Signature
```php
$agent->addAPICommand('#command', 'closure/path');
// OR via Module directly
$this->module->addAPICommand('#command', 'closure/path');
```

### Example: Dual-Purpose API Command

**Controller Setup:**
```php
// controller/main.php
public function __onInit(Agent $agent): int
{
    // '#' prefix = bind as both API command AND internal method
    $agent->addAPICommand('#validateUser', 'internal/validate-user');
    $agent->addAPICommand('#processPayment', 'internal/process-payment');
    
    // Regular API command (external only)
    $agent->addAPICommand('getStatus', 'api/status');
    
    return ModuleStatus::LOADED;
}

public function someRouteHandler(): void
{
    // Internal binding allows direct method call
    $isValid = $this->validateUser($userId);  // Calls internal/validate-user.php
    
    if ($isValid) {
        $result = $this->processPayment($amount);  // Calls internal/process-payment.php
    }
}
```

**Closure File (controller/internal/validate-user.php):**
```php
<?php
use Razy\Controller;

return function (int $userId): bool {
    /** @var Controller $this */
    $db = $this->getDatabase();
    
    $stmt = $db->query('users')->select(['id', 'status'])->where([
        'id' => $userId,
        'active' => 1
    ])->fetch();
    
    return $stmt->hasRecord();
};
```

### External API Access
Other modules can still call the API:
```php
// From another module
$api = $this->poke('vendor/mymodule');
$result = $api->validateUser($userId);  // Works via API
$result = $api->processPayment($amount); // Works via API
```

### Without `#` Prefix
```php
// Regular API command - external access only
$agent->addAPICommand('publicMethod', 'api/public');

// This will NOT work:
$this->publicMethod();  // Error: method not defined

// Must use API:
$api = $this->poke($this->module->getModuleInfo()->getCode());
$api->publicMethod();
```

---

## 3. Complex addLazyRoute() with Nested Structures

`addLazyRoute()` accepts nested arrays where:
- **Keys** = URL path segments
- **Values** = Closure file paths (relative to `controller/`)
- **`@self`** = Route maps to the current path level

### Signature
```php
$agent->addLazyRoute(mixed $route, mixed $path = null): static
```

### Example: Complete Nested Structure

```php
public function __onInit(Agent $agent): int
{
    $agent->addLazyRoute([
        // Root path '/' maps to 'main' closure
        '/' => 'main',
        
        // /type routes
        'type' => [
            '@self' => 'type/main',      // /type → controller/type/main.php
            'api' => [
                'list' => 'type/list',   // /type/api/list → controller/type/api/list.php
                'create' => 'type/api/create',
                'update' => 'type/api/update',
                'delete' => 'type/api/delete',
            ],
        ],
        
        // /api routes
        'api' => [
            '@self' => 'api/main',       // /api → controller/api/main.php
            'fetch' => [
                'type' => 'api/fetch/type',  // /api/fetch/type → controller/api/fetch/type.php
            ],
            'attachment' => [
                'upload' => 'attachment/upload',
                'download' => 'attachment/download',
            ],
            'list' => 'api/list',
        ],
        
        // /admin routes with deep nesting
        'admin' => [
            '@self' => 'admin/dashboard', // /admin → controller/admin/dashboard.php
            'users' => [
                '@self' => 'admin/users/list',
                'create' => 'admin/users/create',
                'edit' => [
                    '(:d)' => 'admin/users/edit',  // /admin/users/edit/123 with param
                ],
            ],
            'settings' => [
                'general' => 'admin/settings/general',
                'security' => 'admin/settings/security',
            ],
        ],
    ]);
    
    return ModuleStatus::LOADED;
}
```

### Route Resolution Table

| URL Path | Closure File |
|----------|-------------|
| `/` | `controller/main.php` |
| `/type` | `controller/type/main.php` |
| `/type/api/list` | `controller/type/api/list.php` |
| `/type/api/create` | `controller/type/api/create.php` |
| `/api` | `controller/api/main.php` |
| `/api/fetch/type` | `controller/api/fetch/type.php` |
| `/api/attachment/upload` | `controller/attachment/upload.php` |
| `/api/list` | `controller/api/list.php` |
| `/admin` | `controller/admin/dashboard.php` |
| `/admin/users` | `controller/admin/users/list.php` |
| `/admin/users/create` | `controller/admin/users/create.php` |
| `/admin/users/edit/123` | `controller/admin/users/edit.php` (123 passed as param) |

### Understanding `@self`

`@self` is a special key that maps **the current path level** to a closure:

```php
'products' => [
    '@self' => 'products/index',   // /products → controller/products/index.php
    'view' => 'products/view',     // /products/view → controller/products/view.php
    'add' => 'products/add',       // /products/add → controller/products/add.php
]
```

Without `@self`, `/products` would have no handler.

### Combining with Route Tokens

Lazy routes support the same tokens as regular routes:

```php
$agent->addLazyRoute([
    'article' => [
        '(:d)' => 'article/view',           // /article/123
        '(:a)-(:d)' => 'article/seo-view',  // /article/my-title-123 (captures both)
    ],
    'user' => [
        '(:w){3,20}' => 'user/profile',     // /user/john (3-20 letters only)
    ],
]);
```

### Flat vs Nested Syntax

Both are equivalent:

**Nested:**
```php
$agent->addLazyRoute([
    'api' => [
        'users' => [
            'list' => 'users/list',
        ],
    ],
]);
```

**Flat (string keys):**
```php
$agent->addLazyRoute('api/users/list', 'users/list');
```

### HTTP Method Prefix in Nested Routes

Nested lazy routes support HTTP method prefixes at **any nesting level**. A method prefix on a parent key is inherited by all its children unless a child specifies its own method.

**Supported methods:** `GET`, `POST`, `PUT`, `PATCH`, `DELETE`, `HEAD`, `OPTIONS` (and multi-method with `|`).

#### Method on Parent Key (Inherited by Children)

```php
$agent->addLazyRoute([
    'POST api' => [
        'fetch' => 'fetch',    // POST /api/fetch → controller/fetch.php
        'add' => 'add',        // POST /api/add → controller/add.php
        'delete' => 'delete',  // POST /api/delete → controller/delete.php
    ],
]);
```

All children inherit `POST` from the parent key.

#### Method on Leaf Keys (Per-Route)

```php
$agent->addLazyRoute([
    'api' => [
        'fetch' => 'fetch',         // ANY /api/fetch → controller/fetch.php
        'POST add' => 'add',        // POST /api/add → controller/add.php
        'POST delete' => 'delete',  // POST /api/delete → controller/delete.php
    ],
]);
```

Only `add` and `delete` are restricted to `POST`; `fetch` matches any method.

#### Child Overrides Parent Method

```php
$agent->addLazyRoute([
    'POST api' => [
        'fetch' => 'fetch',         // POST /api/fetch (inherited)
        'GET list' => 'list',       // GET /api/list (overrides POST)
    ],
]);
```

#### Deep Nesting with Inheritance

```php
$agent->addLazyRoute([
    'POST api' => [
        'v1' => [
            'fetch' => 'v1/fetch',   // POST /api/v1/fetch
            'update' => 'v1/update', // POST /api/v1/update
        ],
    ],
]);
```

The `POST` method propagates through all nesting levels.

#### Multi-Method Prefix

```php
$agent->addLazyRoute([
    'GET|POST api' => [
        'search' => 'search',  // GET or POST /api/search
    ],
]);
```

#### `@self` with Method Prefix

```php
$agent->addLazyRoute([
    'POST api' => [
        '@self' => 'api/index',  // POST /api → controller/api/index.php
        'submit' => 'submit',    // POST /api/submit → controller/submit.php
    ],
]);
```

> **Note:** The method prefix must appear before the path segment, separated by a space (e.g. `POST api`, not `api POST`). The method is stripped from the file path — `POST api` maps to the `api/` path, not `POST api/`.

---

## 4. addShadowRoute() - Route Proxy

`addShadowRoute()` creates a route in your module that proxies to another module's closure. This enables URL aliasing, route forwarding, and module composition.

### Signature
```php
$agent->addShadowRoute(string $route, string $moduleCode, string $path = ''): static
```

- `$route`: URL path for THIS module (needs leading slash for absolute paths)
- `$moduleCode`: The target module to proxy to
- `$path`: The closure path in the target module (defaults to `$route` if empty)

### Use Cases
1. **URL Aliasing**: Short URLs that proxy to longer module paths
2. **Route Forwarding**: Redirect v2 API routes to v1 handlers
3. **Module Composition**: Aggregate routes from multiple modules into one interface
4. **White-Label**: Same functionality with different URL namespaces

### Example: Basic Shadow Route

```php
public function __onInit(Agent $agent): int
{
    // Shadow route with explicit target path
    // /mymodule/helper → calls helper_module's 'shared/handler' closure
    $agent->addShadowRoute('/mymodule/helper', 'vendor/helper_module', 'shared/handler');
    
    // Shadow route without path (uses same route in target)
    // /mymodule/common → calls helper_module's '/mymodule/common' route
    $agent->addShadowRoute('/mymodule/common', 'vendor/helper_module');
    
    return Module::STATUS_LOADED;
}
```

### Example: API Version Aliasing

```php
public function __onInit(Agent $agent): int
{
    // v2 API module proxies to v1 for unchanged endpoints
    $agent->addShadowRoute('/api/v2/users/list', 'vendor/api_v1', 'api/users/list');
    $agent->addShadowRoute('/api/v2/users/get', 'vendor/api_v1', 'api/users/get');
    
    // New v2 endpoints handled locally
    $agent->addLazyRoute([
        'api/v2' => [
            'users' => [
                'bulk-update' => 'api/v2/users/bulk-update',  // New in v2
            ],
        ],
    ]);
    
    return Module::STATUS_LOADED;
}
```

### Example: White-Label Composition

```php
// white_label_app module - aggregates routes from other modules
public function __onInit(Agent $agent): int
{
    // Main app branding
    $agent->addLazyRoute(['/' => 'branding/home']);
    
    // Proxy auth routes from auth module
    $agent->addShadowRoute('/login', 'vendor/auth', 'auth/login');
    $agent->addShadowRoute('/logout', 'vendor/auth', 'auth/logout');
    
    // Proxy dashboard from dashboard module
    $agent->addShadowRoute('/dashboard', 'vendor/dashboard', 'main');
    $agent->addShadowRoute('/dashboard/stats', 'vendor/dashboard', 'stats');
    
    // Proxy API from api_core module
    $agent->addShadowRoute('/api/users', 'vendor/api_core', 'users/list');
    $agent->addShadowRoute('/api/items', 'vendor/api_core', 'items/list');
    
    return Module::STATUS_LOADED;
}
```

### Important Notes

1. **Cannot shadow to self**: You cannot create a shadow route to your own module
   ```php
   // This throws an Error:
   $agent->addShadowRoute('/path', 'my/own_module', 'handler');
   ```

2. **Target module must exist**: The target module must be loaded for the shadow route to work

3. **Closure binding**: The target closure runs in its original module context, not the shadow module

4. **Execution flow**:
   - Request comes to shadow route URL
   - Distributor resolves to shadow route
   - Request proxied to target module's closure
   - Response returned through original route

---

## 5. Combining All Features

Here's a real-world example combining await, internal binding, and complex routes:

```php
public function __onInit(Agent $agent): int
{
    // Internal API bindings for use within this module
    $agent->addAPICommand('#authenticate', 'internal/auth');
    $agent->addAPICommand('#authorize', 'internal/authorize');
    $agent->addAPICommand('#log', 'internal/logger');
    
    // Public API commands
    $agent->addAPICommand('getUser', 'api/get-user');
    $agent->addAPICommand('updateUser', 'api/update-user');
    
    // Complex route structure
    $agent->addLazyRoute([
        '/' => 'dashboard',
        'auth' => [
            'login' => 'auth/login',
            'logout' => 'auth/logout',
            'callback' => 'auth/callback',
        ],
        'api' => [
            'v1' => [
                '@self' => 'api/v1/index',
                'users' => [
                    '@self' => 'api/v1/users/list',
                    '(:d)' => 'api/v1/users/get',
                ],
                'posts' => [
                    'list' => 'api/v1/posts/list',
                    'create' => 'api/v1/posts/create',
                ],
            ],
        ],
    ]);
    
    // Wait for permission module to be ready
    $agent->await('vendor/permissions', function() {
        // Register protected routes after permissions are ready
        $api = $this->poke('vendor/permissions');
        $api->registerResource('mymodule', [
            'users.read',
            'users.write',
            'posts.read',
            'posts.write',
        ]);
    });
    
    return ModuleStatus::LOADED;
}
```

---

## Quick Reference

| Feature | Syntax | Purpose |
|---------|--------|---------|
| `await()` | `$agent->await('module', fn)` | Defer until module ready |
| `#` prefix | `$agent->addAPICommand('#cmd', 'path')` | Internal + external binding |
| `@self` | `['path' => ['@self' => 'file']]` | Handler for current level |
| Nested route | `['a' => ['b' => 'file']]` | `/a/b` → `controller/a/file.php` |
| Method prefix | `['POST a' => ['b' => 'file']]` | POST `/a/b` (inherited by children) |
| Shadow route | `$agent->addShadowRoute('/url', 'module', 'path')` | Proxy to other module |
