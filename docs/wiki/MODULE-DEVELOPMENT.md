# Module Development Guide

**Reference Module**: `test-razy-cli/sites/mysite/demo/event_demo/`

---

## Module Structure

```
sites/{dist}/{vendor}/{module}/
├── module.php              # Module metadata (REQUIRED)
└── default/                # Default package
    ├── package.php         # Package version info
    └── controller/         # Route controllers
        ├── {module}.php    # Main controller (__onInit entry)
        └── {module}.{route}.php  # Additional route handlers
```

**Critical**: Do NOT create a `modules/` subfolder inside the module directory.

---

## Step 1: Create module.php

```php
<?php
return [
    'module_code' => 'vendor/module_name',  // Must be unique
    'author' => 'Your Name',
    'version' => '1.0.0',
    'description' => 'Short description of module functionality',
];
```

**Notes**:
- `module_code` format: `vendor/module_name`
- This must match the folder path: `sites/{dist}/{vendor}/{module_name}/`

---

## Step 2: Create default/package.php

```php
<?php
return [
    '1.0.0' => [
        'require' => []  // Dependencies: ['vendor/other_module' => '*']
    ]
];
```

**Dependency Format**:
```php
'require' => [
    'vendor/module' => '*',      // Any version
    'vendor/module' => '>=1.0',  // Minimum version
]
```

---

## Step 3: Create Main Controller

File: `default/controller/{module}.php`

```php
<?php
namespace Razy\Sites\{Dist}\{Vendor}\{Module}\Controller;

use Razy\Controller;

/**
 * @llm-primary Main controller for {module}
 * @llm-nav Routes: /{module}/, /{module}/action
 */
class {Module} extends Controller
{
    /**
     * Called when module initializes
     * Use for: registering events, setting up routes
     */
    public function __onInit(): int
    {
        // Register routes
        $this->getAgent()->addRoute('/{module}/action/(:d)', 'action');
        
        // Register event listeners
        $this->getAgent()->listen('vendor/other:event', function($data) {
            return ['received' => true];
        });
        
        return Controller::PLUGIN_LOADED;
    }

    /**
     * Default route: /{module}/
     */
    public function main(): string
    {
        return 'Module loaded';
    }

    /**
     * Action route: /{module}/action/{id}
     */
    public function action(int $id): string
    {
        return json_encode(['id' => $id]);
    }
}
```

---

## Step 4: Register Module in dist.php

```php
<?php
return [
    'enable' => true,
    'name' => 'mysite',
    'domain' => [
        'localhost:8080',
    ],
    'module' => [
        'demo/module_name' => ['autoload' => false],  // Add your module
    ],
];
```

**Module Options**:
```php
'vendor/module' => [
    'autoload' => false,  // false = load on demand, true = always load
]
```

---

## Controller Naming Convention

| File Name | Controller Class | Route Pattern |
|-----------|-----------------|---------------|
| `{module}.php` | `{Module}` | `/{module}/` (main) |
| `{module}.user.php` | `{Module}_user` | `/{module}/user/` |
| `{module}.api.php` | `{Module}_api` | `/{module}/api/` |

**Namespace**: `Razy\Sites\{Dist}\{Vendor}\{Module}\Controller`

---

## Common Patterns

### JSON Response
```php
public function data(): string
{
    header('Content-Type: application/json');
    return json_encode(['status' => 'ok']);
}
```

### With Template
```php
public function view(): string
{
    return $this->getTemplate()
        ->assign('data', $this->loadData())
        ->output();
}
```

### Fire Event
```php
public function action(): string
{
    $responses = $this->trigger('event_name')
        ->resolve()
        ->getAllResponse();
    
    return json_encode(['responses' => $responses]);
}
```

---

## Testing Checklist

```powershell
# Start server
cd test-razy-cli
C:\MAMP\bin\php\php8.3.1\php.exe -S localhost:8080

# Test main route
$wc.DownloadString("http://localhost:8080/{module}/")

# Test action route
$wc.DownloadString("http://localhost:8080/{module}/action/123")
```

- [ ] Module loads without errors
- [ ] Main route returns expected content
- [ ] Additional routes work
- [ ] Events fire correctly (if applicable)
