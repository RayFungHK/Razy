# Module Controller Naming Guide

**Purpose**: Reference for organizing and naming controller handler files in Razy modules  
**Applies To**: Routes, API commands, and event handlers  
**Key Principle**: Prevent naming conflicts through consistent prefixing at root level

---

## Quick Summary

### Naming Rules

| Location | Pattern | Example | Purpose |
|----------|---------|---------|---------|
| **Root level** | `{module_code}.{func}.php` | `hello.main.php`, `hello.api_list.php` | Prevent conflicts between modules |
| **Subfolders** | `{subfolder}/{func}.php` | `api/set.php`, `admin/users.php` | Organize shared logic, no prefix needed |

### Key Points

- **Root level files MUST use module code prefix** - All files directly in `controller/` directory must include `{module_code}.` prefix
- **Subfolder files do NOT use prefix** - Files in `controller/{subfolder}/` structure use only the function name
- **Applies to all handler types** - Same rules for routes, API commands, and event listeners
- **Why**: Prevents name collisions when multiple modules are loaded simultaneously

---

## Root Level Handlers

### Pattern: `controller/{module_code}.{func}.php`

For any handler that declares a direct path (without slashes), create the file at root with the module code prefix.

### Examples

**Module Code**: `hello`

```php
// In Module Controller (e.g., Hello.php)
$agent->addLazyRoute([
    '/' => 'main',      // Creates: controller/hello.main.php
    'api' => [
        'list' => 'list',       // Creates: controller/hello.list.php  
        'create' => 'process',  // Creates: controller/hello.process.php
    ],
]);

$agent->addAPICommand([
    'status' => 'status',       // Creates: controller/hello.status.php
    'config' => 'config',       // Creates: controller/hello.config.php
]);

$agent->listen('user.login', 'onUserLogin');  // Creates: controller/hello.onUserLogin.php
```

### File Structure

```
modules/acme/hello/
├── default/
│   ├── package.php
│   └── src/
├── controller/
│   ├── hello.main.php          # ← Root level with prefix
│   ├── hello.list.php          # ← Root level with prefix
│   ├── hello.process.php       # ← Root level with prefix
│   ├── hello.status.php        # ← Root level with prefix
│   ├── hello.config.php        # ← Root level with prefix
│   └── hello.onUserLogin.php   # ← Root level with prefix
├── view/
└── module.php
```

---

## Subfolder Handlers

### Pattern: `controller/{subfolder}/{func}.php`

For handlers with paths containing slashes, create subfolders and place files without the module code prefix.

### Examples

**Module Code**: `hello`

```php
// In Module Controller
$agent->addLazyRoute([
    'api' => [
        'user' => [
            'list' => 'user/list',      // Creates: controller/api/user/list.php
            'create' => 'user/create',  // Creates: controller/api/user/create.php
        ],
    ],
]);

$agent->addAPICommand([
    '#set' => 'api/set',           // Creates: controller/api/set.php
    '#get' => 'api/get',           // Creates: controller/api/get.php
    'auth' => 'admin/auth',        // Creates: controller/admin/auth.php
    'permissions' => 'admin/perms', // Creates: controller/admin/perms.php
]);

$agent->listen('data.sync', 'hooks/onSync');  // Creates: controller/hooks/onSync.php
```

### File Structure

```
modules/acme/hello/
├── default/
│   ├── package.php
│   └── src/
├── controller/
│   ├── hello.main.php              # Root level with prefix
│   ├── api/
│   │   ├── set.php                 # ← No prefix in subfolder
│   │   ├── get.php                 # ← No prefix in subfolder
│   │   └── user/
│   │       ├── list.php            # ← No prefix in subfolder
│   │       └── create.php          # ← No prefix in subfolder
│   ├── admin/
│   │   ├── auth.php                # ← No prefix in subfolder
│   │   └── perms.php               # ← No prefix in subfolder
│   └── hooks/
│       └── onSync.php              # ← No prefix in subfolder
├── view/
└── module.php
```

---

## Handler Types

### Lazy Routes

**Declaration**: `$agent->addLazyRoute()`  
**Where**: Module controller's `__onInit()` method  
**File Pattern**: Follow naming rules above

```php
public function __onInit(Agent $agent): bool {
    $agent->addLazyRoute([
        '/' => 'main',                    // → controller/hello.main.php
        'admin' => [
            'users' => 'admin/users',     // → controller/admin/users.php
            'settings' => 'admin/settings' // → controller/admin/settings.php
        ]
    ]);
    return true;
}
```

### API Commands

**Declaration**: `$agent->addAPICommand()`  
**Where**: Module controller's `__onInit()` method  
**File Pattern**: Follow naming rules above

```php
public function __onInit(Agent $agent): bool {
    $agent->addAPICommand([
        'getUser' => 'getUser',           // → controller/hello.getUser.php
        '#set' => 'api/set',              // → controller/api/set.php
        'auth' => 'api/auth',             // → controller/api/auth.php
        'profile' => 'user/profile',      // → controller/user/profile.php
    ]);
    return true;
}
```

### Event Handlers

**Declaration**: `$agent->listen()`  
**Where**: Module controller's `__onInit()` method  
**File Pattern**: Follow naming rules above

```php
public function __onInit(Agent $agent): bool {
    $agent->listen('user.login', 'onLogin');     // → controller/hello.onLogin.php
    $agent->listen('data.sync', 'sync/process'); // → controller/sync/process.php
    $agent->listen('cache.clear', 'hooks/clear'); // → controller/hooks/clear.php
    return true;
}
```

---

## Handler File Implementation

Each handler file must return a controller class instance that extends `Controller`:

```php
<?php
// controller/hello.main.php
namespace Acme\Hello\Controller;

use Razy\Controller;

return new class extends Controller {
    public function __invoke() {
        return [
            'status' => 'success',
            'module' => 'hello',
            'handler' => 'main',
        ];
    }
};
```

```php
<?php
// controller/api/set.php
namespace Acme\Hello\Controller\Api;

use Razy\Controller;

return new class extends Controller {
    public function __invoke($key, $value = null) {
        return [
            'key' => $key,
            'value' => $value,
            'saved' => true,
        ];
    }
};
```

---

## Why This Pattern?

### Problem: Naming Conflicts

Without prefixes, multiple modules might try to create conflicting files:

```
modules/acme/blog/
├── controller/
│   ├── list.php    # Blog module's list handler
│   └── create.php  # Blog module's create handler

modules/acme/shop/
├── controller/
│   ├── list.php    # Shop module's list handler ← CONFLICT!
│   └── create.php  # Shop module's create handler ← CONFLICT!
```

### Solution: Module Code Prefix

Prefixes ensure unique names across all modules:

```
modules/acme/blog/
├── controller/
│   ├── blog.list.php    # Unique
│   └── blog.create.php  # Unique

modules/acme/shop/
├── controller/
│   ├── shop.list.php    # Unique
│   └── shop.create.php  # Unique
```

Subfolders provide additional namespacing without requiring prefixes since folder paths are already unique.

---

## Best Practices

1. **Use subfolders for organization** - Group related handlers in directories (`api/`, `admin/`, `hooks/`) instead of creating long prefixed names

   ```php
   // ✓ Good: Organized in subfolder
   'api' => 'api/list'              // → controller/api/list.php
   
   // ✗ Avoid: Long root-level name
   'api_list' => 'api_list'         // → controller/hello.api_list.php
   ```

2. **Keep function names simple** - Let the folder structure provide context

   ```php
   // ✓ Good: Simple names with folder context
   $agent->addAPICommand([
       '#get' => 'api/get',
       '#set' => 'api/set',
   ]);
   // Creates: controller/api/get.php, controller/api/set.php
   
   // ✗ Avoid: Redundant naming
   $agent->addAPICommand([
       '#get' => 'apiGet',             // → controller/hello.apiGet.php
       '#set' => 'apiSet',             // → controller/hello.apiSet.php
   ]);
   ```

3. **Consistent folder naming** - Use lowercase, meaningful directory names

   ```
   controller/
   ├── hello.main.php       # Root handlers (with prefix)
   ├── api/                 # API endpoints
   ├── admin/               # Admin functions
   ├── hooks/               # Event handlers
   └── utils/               # Shared utilities
   ```

4. **Document handler organization** - In module's LLM-CAS.md, explain the controller structure

   ```markdown
   ## API Commands
   - `#get` / `#set` - Basic operations (controller/api/)
   - `auth` - Authentication (controller/api/auth.php)
   
   ## Events
   - `user.login` - Handle user login (controller/hooks/login.php)
   ```

---

## Reference

- **Module location**: `sites/{dist_code}/modules/{vendor}/{module}/`
- **Controller base class**: `Razy\Controller`
- **Handler declaration**: Module controller's `__onInit()` method
- **Framework reference**: See `Razy.phar/LLM-CAS.md` for framework classes and API

---

**Created**: February 9, 2026  
**For**: Razy Framework v0.5+  
**Related**: [TEMPLATE-ENGINE-GUIDE.md](TEMPLATE-ENGINE-GUIDE.md)
