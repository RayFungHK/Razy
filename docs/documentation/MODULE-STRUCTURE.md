# Razy Module File Structure Reference

Complete reference for module file structure, requirements, and best practices (verified from Razy v0.5.0-237).

---

### Quick Reference

```
DISTRIBUTOR/modules/{vendor}/{module_code}/{version}/
│
├── package.php              ✓ REQUIRED: Entry point
├── controller/
│   └── {module_code}.php    ✓ REQUIRED: Main controller
├── view/                    ○ Optional: Templates
├── src/                     ○ Optional: Source code
├── plugin/                  ○ Optional: Plugins
└── data/                    ○ Optional: Data files
```

### Mandatory Requirements

### 1. Controller Folder & File (CRITICAL)

**Location**: `{version}/controller/{module_code}.php`

**Purpose**: Main controller handling all module actions

**Requirements**:
- File MUST exist or module will fail to load
- Anonymous class extending `\Razy\Controller`
- File name MUST match module code (lowercase)
- Returns: `new class extends Controller { }`

**Example** (module code: `hello`):
```php
<?php
// sites/mysite/modules/test/hello/default/controller/hello.php

use Razy\Controller;

return new class extends Controller {
    public function greet($name = 'World'): string {
        return "Hello, " . htmlspecialchars($name) . "!";
    }
    
    public function process(array $data): array {
        return array_filter($data);
    }
};
```

**What happens if missing**:
```
Error: The controller (path) does not exists
```

Source: Module.php line 76-103

### 2. Package Entry Point (REQUIRED)

**Location**: `{version}/package.php` (root of version folder, NOT in any subfolder)

**Purpose**: Module initialization and metadata

**File Name**: MUST be `package.php` (lowercase, singular)

**Returns**: Can be array with metadata or class instance

**Example**:
```php
<?php
// sites/mysite/modules/test/hello/default/package.php

namespace Razy\Module\hello;

return [
    'name'        => 'Hello Module',
    'version'     => '1.0.0',
    'author'      => 'Developer Name',
    'description' => 'Test module for documentation extraction',
    'dependencies' => ['core/framework' => '1.0.0'],
];
```

Source: Distributor.php line 307, ModuleInfo.php line 60

### 3. Version Folder (REQUIRED)

**Location**: `{module_root}/{version}/` - EXACTLY one level deep

**Allowed Version Names**:
- `'default'` → Default version (used as fallback)
- `'dev'` → Development version
- Semantic versions: `'1.0.0'`, `'2.1.3'`, `'0.5.0-237'`, etc.
- Pattern: `/^(\d+)(?:\.(?:\d+|\*)){0,3}$/`

**Version Resolution** (Distributor.php line 333):
```php
// When loading a module, Razy looks for version in this order:
$version = isset($this->requires[$code]) 
    ? $this->requires[$code]  // Explicit version from dist.php
    : 'default';               // Fallback to 'default'
```

**In dist.php**:
```php
'modules' => [
    '*' => [
        'test/hello' => 'default',   // Explicit version
        'auth' => '1.0.0',           // Different version
        'user/profile',              // Defaults to 'default'!
    ],
],
```

Source: ModuleInfo.php line 51, Distributor.php line 333

## Complete Folder Structure

### Minimal Module (with mandatory files only)

```
sites/mysite/
└── modules/
    └── test/
        └── hello/
            └── default/
                ├── package.php
                └── controller/
                    └── hello.php
```

### Full-Featured Module

```
sites/mysite/
└── modules/
    └── test/
        └── hello/
            ├── module.php                  (optional: module-level config)
            └── default/
                ├── package.php             (required)
                ├── controller/
                │   └── hello.php           (required)
                ├── view/
                │   ├── main.tpl
                │   ├── greeting.tpl
                │   └── form.tpl
                ├── src/
                │   ├── Service.php
                │   ├── Repository.php
                │   └── Helper.php
                ├── plugin/
                │   ├── modifier.format.php
                │   ├── processor.validate.php
                │   └── filter.clean.php
                ├── data/
                │   ├── defaults.json
                │   └── templates.php
                ├── model/
                │   ├── User.php
                │   ├── Message.php
                │   └── Repository.php
                └── config/
                    ├── settings.php
                    └── routes.php
```

### Multi-Version Module

```
sites/mysite/
└── modules/
    └── test/
        └── hello/
            ├── default/
            │   ├── package.php
            │   └── controller/hello.php
            ├── dev/
            │   ├── package.php
            │   └── controller/hello.php
            ├── 1.0.0/
            │   ├── package.php
            │   └── controller/hello.php
            ├── 1.5.0/
            │   ├── package.php
            │   └── controller/hello.php
            └── 2.0.0/
                ├── package.php
                └── controller/hello.php
```

## File Content Guidelines

### package.php

**Purpose**: Initialize module and provide metadata

**Options 1 - Return Configuration Array**:
```php
<?php
return [
    'name'        => 'Hello Module',
    'version'     => '1.0.0',
    'author'      => 'Developer',
    'description' => 'Module description',
    'dependencies' => [
        'auth' => '1.0.0',
        'core/framework' => '1.5.0',
    ],
];
```

**Option 2 - Return Class Instance**:
```php
<?php
namespace Razy\Module\hello;

return new class {
    public function __construct() {
        // Initialization code
    }
    
    public function getName(): string {
        return 'Hello Module';
    }
};
```

**Option 3 - With LLM Documentation**:
```php
<?php
/**
 * Hello Module Package
 * 
 * @llm This module provides greeting functionality.
 * It demonstrates how to create a simple HTTP response module.
 * For more info, see controller/hello.php
 */

namespace Razy\Module\hello;

return [
    'name' => 'Hello Module',
    'version' => '1.0.0',
];
```

### controller/{module_code}.php

**Purpose**: Handle HTTP requests and define module actions

**MUST Return**: `new class extends \Razy\Controller { }`

**Method Naming**: Public methods become actions
- `public function greet()` → Action URL: `/module/hello/greet`
- `public function process()` → Action URL: `/module/hello/process`

**Example with LLM Documentation**:
```php
<?php
/**
 * Hello Module Main Controller
 * 
 * @llm This controller handles greeting requests and data processing.
 * All public methods become callable HTTP actions.
 */

use Razy\Controller;

return new class extends Controller {
    /**
     * Return greeting message
     * 
     * @llm Greets the user by name.
     * Accessible via GET /module/hello/greet?name=World
     * 
     * @param string $name User's name (default: World)
     * @return string Greeting message with @llm documentation
     */
    public function greet($name = 'World'): string {
        return "Hello, " . htmlspecialchars($name) . "!";
    }
    
    /**
     * Process form data
     * 
     * @llm Validates and filters user input.
     * Removes empty values from the submitted data array.
     */
    public function process(array $data): array {
        return array_filter($data, fn($v) => !empty($v));
    }
    
    /**
     * Get module metadata
     * 
     * @llm Returns API documentation for module discovery.
     */
    public function info(): array {
        return [
            'version' => '1.0.0',
            'actions' => ['greet', 'process', 'info'],
        ];
    }
};
```

## Using @llm Prompts for Documentation

### Where to Add @llm Comments

1. **Package.php** - Module-level documentation:
```php
<?php
/**
 * Module Name Package
 * 
 * @llm General description of what this module does.
 * Multiple lines of documentation.
 * Describes the module's purpose and features.
 */
return [...];
```

2. **Controller Actions** - Function-level documentation:
```php
public function actionName($param): string {
    /**
     * @llm Describes what this action does.
     * How it handles the parameter.
     * What output it produces.
     */
}
```

3. **View Templates** - Template-level documentation:
```html
{# 
  @llm Explains how this template is used.
  Which components are displayed.
  How data flows through the template.
#}
<div class="greeting">
    {$content}
</div>
```

### LLMCASGenerator Processing

The LLMCASGenerator tool will:
1. Scan all `@llm` docblock comments in the module
2. Extract comments from:
   - `package.php` → Module overview
   - `controller/*.php` → Action documentation
   - `view/*.tpl` → Template documentation
   - `src/**/*.php` → Class/method documentation
3. Generate comprehensive documentation based on these prompts

## Troubleshooting

### Module Won't Load

**Error**: "Module not found" or "Module failed to load"

**Checklist**:
1. ✓ Controller folder exists at `{version}/controller/`
2. ✓ Controller file is `{version}/controller/{module_code}.php`
3. ✓ Controller class extends `\Razy\Controller`
4. ✓ `package.php` exists at `{version}/package.php` root
5. ✓ Version folder matches: `default`, `dev`, or semantic version
6. ✓ dist.php has module listed in modules array

### Version Not Found

**Error**: "Module version X.X.X for '{module}' is not available"

**Fix**:
1. Check dist.php module version matches actual folder
2. Use 'default' version if version folder not created
3. Verify folder name matches exactly: `default` not `Default`

### Controller Error

**Error**: "The controller ... does not exists"

**Fix**:
1. Controller file MUST be in `controller/` subfolder
2. File name MUST be `{module_code}.php` (lowercase)
3. File MUST return anonymous class extending Controller
4. File MUST be readable and valid PHP

### Import Paths

When using @llm prompts, use relative paths:
```php
@llm See controller/hello.php for action definitions
@llm Check view/templates/ for display logic
@llm Review src/Service.php for business logic
```

## Source Code References

- **Module Loading**: [Module.php](src/library/Razy/Module.php#L76)
- **Version Handling**: [ModuleInfo.php](src/library/Razy/ModuleInfo.php#L51)
- **Module Discovery**: [Distributor.php](src/library/Razy/Distributor.php#L333)
