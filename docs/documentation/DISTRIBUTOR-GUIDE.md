# Distributor Creation and Configuration Guide

Complete guide to creating, configuring, and managing distributions in Razy.

---

### Creating a Distributor

#### Using CLI (Recommended)

```bash
# Step 1: Initialize distributor with cli
php Razy.phar set localhost/myapp mysite -i

# What happens:
# - Creates: sites/mysite/ directory
# - Generates: sites/mysite/dist.php
# - Updates: sites.inc.php with 'localhost/' → 'mysite' binding
# - Creates: sites/mysite/modules/ (empty, ready for modules)
```

#### Manual Creation

```bash
# Step 1: Create directory structure
mkdir -p sites/myapp/modules
mkdir -p sites/myapp/plugins
mkdir -p sites/myapp/data

# Step 2: Create dist.php manually
# (copy template from src/asset/setup/dist.php.tpl)

# Step 3: Update sites.inc.php to reference the distributor
# In sites.inc.php:
# 'domains' => [
#     'localhost' => ['/' => 'myapp@*'],
# ],
```

### Distributor Structure

```
sites/mysite/                                    # Distributor root (code: "mysite")
│
├── dist.php                                     # Main configuration
│
├── test/                                        # Vendor code "test"
│   ├── hello/                                   # Module "hello"
│   │   ├── module.php                           # Metadata (vendor/code level)
│   │   ├── default/                             # Version "default"
│   │   │   ├── package.php                      # Config (version level)
│   │   │   ├── controller/
│   │   │   │   ├── hello.php                    # Route registration
│   │   │   │   ├── hello.main.php               # Handler: GET /hello/
│   │   │   │   ├── hello.greet.php              # Handler: GET /hello/greet
│   │   │   │   └── api/
│   │   │   │       └── list.php                 # Handler: GET /hello/api/list
│   │   │   ├── view/                            # Templates
│   │   │   ├── src/                             # Source files
│   │   │   ├── plugin/                          # Module plugins
│   │   │   ├── data/                            # Module data
│   │   │   └── public/                          # Static assets
│   │   ├── 1.0.0/                               # Version 1.0.0 (parallel)
│   │   └── 2.0.0/                               # Version 2.0.0 (parallel)
│   │
│   ├── dummy/                                   # Module "dummy"
│   │   ├── module.php
│   │   ├── default/
│   │   └── admin/                               # Admin version
│   │
│   └── profile/                                 # Another module in test vendor
│       ├── module.php
│       └── default/
│
├── admin/                                       # Vendor code "admin"
│   ├── dashboard/
│   │   ├── module.php
│   │   └── default/
│   │
│   └── users/
│       ├── module.php
│       └── default/
│
└── user/                                        # Vendor code "user"
    ├── profile/
    │   ├── module.php
    │   └── default/
    │
    └── settings/
        ├── module.php
        └── default/
```

## dist.php Configuration

### Basic Structure

```php
<?php
// sites/mysite/dist.php

return [
    // Required: Distributor identifier (must match folder name)
    'dist' => 'mysite',
    
    // Whether to auto-load modules from ../shared/ folder
    'autoload_shared' => false,
    
    // Module declarations by version tag
    'modules' => [
        '*' => [                              // Default tag (always loaded)
            'test/hello' => 'default',        // vendor/module => version
            'test/dummy' => 'default',
            'admin/dashboard' => '1.0.0',
        ],
        'admin' => [                          // Custom tag (conditional)
            'test/dummy' => 'admin',          // Override with admin version
        ],
    ],
    
    // Explicitly exclude modules
    'exclude_module' => [],
    
    // Auto-load ALL modules in sites/{dist}/ folder (excluding _* prefixed)
    // If true, modules need not be listed in 'modules' array
    'greedy' => true,
];
```

### Advanced Features

#### 1. Basic Module References

```php
'modules' => [
    '*' => [
        'test/hello' => 'default',    // Exact version
        'test/dummy' => 'default',    // Simple module format
        'admin/dashboard' => '1.0.0', // Vendor/module format
    ],
],
```

#### 2. Multi-Level Vendor Organization

```php
'modules' => [
    '*' => [
        'admin/user/management' => 'default',  // Nested vendor/code
        'api/v1/rest' => 'default',            // Deep nesting
        'system/cache' => 'default',
    ],
],
```

#### 3. Conditional Module Loading by Tag

```php
'modules' => [
    '*' => [                              // Default tag - always loaded
        'test/hello' => 'default',
        'test/dummy' => 'default',
    ],
    'admin' => [                          // Admin tag - conditional
        'test/dummy' => 'admin',          // Override with admin version
        'admin/dashboard' => 'default',   // Admin-only modules
    ],
    'development' => [                    // Dev tag - optional
        'debug/profiler' => 'default',
        'debug/logger' => 'default',
    ],
],
```

## Understanding Module URLs

### URL Structure

```
Filesystem: sites/mysite/{vendor}/{module_code}/{version}/
URL Path:   /{module_code}/{route}

Examples:
- Filesystem: sites/mysite/test/hello/default/
  → URL: http://localhost/hello/
  → With route: http://localhost/hello/greet?name=Developer

- Filesystem: sites/mysite/admin/dashboard/default/
  → URL: http://localhost/dashboard/
  → With route: http://localhost/dashboard/view?id=123

- Filesystem: sites/mysite/test/dummy/admin/
  → URL: http://localhost/dummy/ (when admin tag loaded)
```

**Key Point**: Vendor code is for filesystem organization, NOT URL routing

## Controller and Handler File Pattern

### Route Registration in Controller

```php
<?php
// sites/mysite/test/hello/default/controller/hello.php
namespace Razy\Module\hello;

use Razy\Agent;
use Razy\Controller;

return new class extends Controller {
    public function __onInit(Agent $agent): bool
    {
        // Register lazy routes
        $agent->addLazyRoute([
            '/' => 'main',                 // Route /hello/ → hello.main.php
            'greet' => 'greet',            // Route /hello/greet → hello.greet.php
            'api' => [
                'list' => 'list',          // Route /hello/api/list → api/list.php
            ],
        ]);
        
        return true;
    }
};
```

### Handler File Pattern

```php
<?php
// Route handlers MUST echo output, not return strings
// File: hello.main.php
return function(): void {
    echo "✓ Hello Module - Main Page\n";
    echo "Available routes:\n";
    echo "  - GET /hello/\n";
    echo "  - GET /hello/greet\n";
    echo "  - GET /hello/api/list\n";
};

// File: hello.greet.php
return function($name = 'World'): void {
    echo "Hello, " . htmlspecialchars($name) . "! 👋\n";
};

// File: api/list.php (nested route)
return function(): void {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => true,
        'module' => 'hello',
        'routes' => ['/', '/greet', '/api/list'],
    ], JSON_PRETTY_PRINT);
};
```

**Handler File Execution Flow**:
1. Browser requests: `GET /hello/greet?name=Developer`
2. Razy loads controller and calls `__onInit()`
3. Controller specifies route mapping: `'greet' => 'greet'`
4. Razy loads and executes: `hello.greet.php`
5. Handler file returns callable function with parameters
6. Function is invoked with parameters: `function($name = 'Developer')`
7. Handler **echoes** output (not returns)
8. Output is sent to browser

## Site Binding in sites.inc.php

### Basic Setup

```php
<?php
// sites.inc.php

return [
    'domains' => [
        'localhost' => [
            '/'      => 'mysite@*',     // Root → mysite distributor, default tag
            '/admin' => 'mysite@admin', // /admin uses admin tag, different modules
        ],
        'example.com' => [
            '/' => 'mysite@*',
        ],
    ],
    'alias' => [
        '127.0.0.1' => 'localhost',        // Alias for development
    ],
];
```

### Format: `{dist_code}@{tag}`

- `mysite@*`: Use "mysite" distributor with "*" tag (default modules)
- `mysite@admin`: Use "mysite" distributor with "admin" tag modules
- `mysite@api`: Use "mysite" distributor with "api" tag modules

## Module Organization Patterns

### Pattern 1: Feature-Based Namespaces

```
modules/
├── user/
│   ├── auth/
│   ├── profile/
│   └── settings/
├── product/
│   ├── catalog/
│   ├── search/
│   └── reviews/
└── admin/
    ├── dashboard/
    ├── users/
    └── settings/
```

### Pattern 2: API Versioning

```
modules/
├── api/
│   ├── v1/
│   │   ├── auth/
│   │   ├── users/
│   │   └── products/
│   └── v2/
│       ├── auth/
│       ├── users/
│       └── products/
```

### Pattern 3: Layer-Based Organization

```
modules/
├── core/
│   ├── framework/
│   └── helpers/
├── service/
│   ├── auth/
│   ├── email/
│   └── storage/
└── presentation/
    ├── web/
    ├── mobile/
    └── api/
```

## Module File Structure (Per Version)

**IMPORTANT**: This structure is verified from Razy Framework v0.5.0-237 source code

### Mandatory Requirements

✓ **MUST EXIST**: `controller/` folder with main controller file  
✓ **MUST EXIST**: `controller/{module_code}.php` → Anonymous class extending `\Razy\Controller`  
✓ **MUST EXIST**: `package.php` at version root → Module initialization (can return config array or class)  
✓ **VERSION FOLDER REQUIRED**: Exactly one level deep (default, dev, 1.0.0, 2.1.3, etc.)  

### Complete Module Structure

```
modules/{vendor}/{module_code}/{version}/
│
├── package.php                          # REQUIRED: Entry point
│                                        # Returns: array|object with module metadata
│
├── controller/                          # REQUIRED: Controller folder
│   └── {module_code}.php                # REQUIRED: Main controller file
│                                        # Returns: new class extends Controller { }
│
├── view/                                # Optional: Template files
│   ├── main.tpl
│   ├── greeting.tpl
│   └── ...
│
├── src/                                 # Optional: Source code
│   ├── Hello.php
│   ├── Greeting.php
│   └── ...
│
├── plugin/                              # Optional: Module-specific plugins
│   └── modifier.custom.php
│
├── data/                                # Optional: Static data files
│   └── defaults.json
│
└── model/ or config/                    # Optional: Additional organization
    └── ...
```

### Real Example

```
sites/mysite/modules/test/hello/default/
│
├── package.php                          # Metadata (required)
│   ```php
│   return [
│       'name' => 'Hello Module',
│       'version' => '1.0.0',
│   ];
│   ```
│
├── controller/hello.php                 # Main controller (REQUIRED)
│   ```php
│   return new class extends Controller {
│       public function greet($name = 'World') {
│           return "Hello, $name!";
│       }
│   };
│   ```
│
├── view/greeting.tpl
├── src/HelloService.php
└── plugin/modifier.format.php
```

### Version Folder Requirements

**Version folder name must be one of**:
- `default` → Default version (used when no version specified in dist.php)
- `dev` → Development version
- Semantic version: `1.0.0`, `2.1.3`, `0.5.0-237`, etc.

**Version Resolution Order** (from dist.php):
1. If version specified in `dist.php modules['modcode'] => 'version'` → Use that version
2. If no version specified OR version not found → Default to `'default'` version folder
3. If version still not found → Module load fails with error

Example in dist.php:
```php
'modules' => [
    '*' => [
        'test/hello' => 'default',      // Explicit: load default version
        'auth' => '1.0.0',              // Load version 1.0.0
        'user/profile' => '2.1.3',      // Load version 2.1.3
    ],
],
```

If you omit the version specification:
```php
'modules' => [
    '*' => [
        'test/hello',   // Implicitly loads 'default' version!
    ],
],
```

## Module Files: Properties and Requirements

### module.php (Vendor/Code Level - REQUIRED)

Located at: `sites/{dist}/{vendor}/{module_code}/module.php`

This file contains module metadata and applies to all versions of the module.

```php
<?php
// sites/mysite/test/hello/module.php

return [
    'module_code' => 'test/hello',           // Full module identifier (vendor/code)
    'name' => 'Hello Module',                // Display name
    'description' => 'A simple hello world module',
    'author' => 'Ray Fung',
    'license' => 'MIT',
    'tags' => ['demo', 'example'],
];
```

### package.php (Version Level - REQUIRED)

Located at: `sites/{dist}/{vendor}/{module_code}/{version}/package.php`

This file contains version-specific configuration.

```php
<?php
// sites/mysite/test/hello/default/package.php

return [
    'version' => 'default',                  // Version identifier
    'name' => 'Hello Module (Default)',      // Version name (optional)
    'activated' => true,                     // Is this version active?
    'dependencies' => [],                    // Required modules
];
```

### Version Folder Requirements

**Version folder name must be one of**:
- `default` → Default version (used when no version specified in dist.php)
- `dev` → Development version
- Semantic version: `1.0.0`, `2.1.3`, `0.5.0-237`, etc.

**Version Resolution Order** (from dist.php):
1. If version specified in `dist.php modules['vendor/code'] => 'version'` → Use that version
2. If no version specified OR version not found → Default to `'default'` version folder
3. If version still not found → Module load fails with error

Example in dist.php:
```php
'modules' => [
    '*' => [
        'test/hello' => 'default',      // Explicit: load default version
        'auth' => '1.0.0',              // Load version 1.0.0
        'admin/dashboard' => '2.1.3',   // Load version 2.1.3
    ],
],
```

### Controller File Structure (Per Version)

The controller in each version handles route registration only:

```php
<?php
// sites/mysite/test/hello/default/controller/hello.php

namespace Razy\Module\hello;

use Razy\Agent;
use Razy\Controller;

return new class extends Controller {
    public function __onInit(Agent $agent): bool
    {
        // Register all routes for this module
        $agent->addLazyRoute([
            '/' => 'main',
            'greet' => 'greet',
            'api' => ['list' => 'list'],
        ]);
        
        return true;
    }
};
```

## Creating Alongside Sites (CLI)

```bash
# Create multiple distributors for different purposes

# Main application
php Razy.phar set localhost myapp -i

# Admin only
php Razy.phar set localhost/admin admin -i

# API only
php Razy.phar set localhost/api api -i

# Mobile app
php Razy.phar set api.mobileapp.com mobile -i

# All created with proper:
# - Directory structure
# - Blank dist.php
# - Module folders
# - Site routing updated
```

## Common Distributor Configurations

### Development Config

```php
'dist' => 'dev',
'autoload_shared' => true,    // Load shared modules
'greedy' => true,              // Load all local modules
'modules' => [
    '*' => [],                 // Let greedy handle it
    'debug' => [
        'debug/profiler' => '1.0.0',
        'debug/logger' => '1.0.0',
    ],
],
```

### Production Config

```php
'dist' => 'prod',
'autoload_shared' => false,    // Only specified modules
'greedy' => false,             // Explicit control
'modules' => [
    '*' => [
        'core/framework' => '2.0.0',
        'auth' => '1.5.0',
        'user/profile' => '1.0.0',
        'product/catalog' => '2.1.0',
        'api/rest' => '1.2.0',
    ],
],
'exclude_module' => [],        // None excluded
```

## Best Practices

1. **Naming**: Use lowercase, hyphens for readability (my-module, user-auth)
2. **Versioning**: Follow semantic versioning (MAJOR.MINOR.PATCH)
3. **Organization**: Group related modules under namespaces
4. **Development**: Use greedy=true for flexibility
5. **Production**: Use greedy=false with explicit module lists
6. **Testing**: Create separate distributors for testing scenarios
7. **Documentation**: Document module dependencies in dist.php comments

## Troubleshooting

### Missing distributor

```
Error: The distributor (myapp) is not registered in the current route.
```
**Fix**: Check sites.inc.php has correct binding for domain/path

### Module not loading

```
Error: Module 'auth' not found.
```
**Fix**: 
- Check modules/ folder contains auth/
- Check dist.php lists 'auth' in modules array
- Check module version folder exists

### Version mismatch

```
Error: Module version 1.0.0 for 'auth' is not available.
```
**Fix**: Module folder should match: modules/auth/1.0.0/

