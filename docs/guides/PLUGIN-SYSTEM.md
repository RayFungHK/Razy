# Razy Plugin System

Complete guide to the Razy plugin system architecture, plugin types, and development guidelines.

---

## Table of Contents

1. [Overview](#overview)
2. [Plugin Architecture](#plugin-architecture)
3. [Plugin Types](#plugin-types)
   - [Template Plugins](#template-plugins)
   - [Collection Plugins](#collection-plugins)
   - [FlowManager Plugins](#flowmanager-plugins)
   - [Statement Plugins](#statement-plugins)
4. [Creating Plugins](#creating-plugins)
5. [Loading Plugins](#loading-plugins)
6. [Plugin Examples](#plugin-examples)
7. [Best Practices](#best-practices)
8. [Built-in Plugins](#built-in-plugins)

---

## Overview

The Razy plugin system provides a flexible, modular architecture for extending framework functionality. Plugins are self-contained PHP files that return closures or factory functions, enabling dynamic feature addition without modifying core code.

### Key Features

- **Dynamic Loading**: Plugins are loaded on-demand, reducing memory footprint
- **Modular Design**: Each plugin is a separate file with clear naming conventions
- **Type Safety**: Plugins must extend specific base classes for type checking
- **Controller Binding**: Plugins can access module controllers for context-aware operations
- **Multiple Systems**: Four distinct plugin systems (Template, Collection, FlowManager, Statement)

### Architecture Pattern

```
PluginTrait
    ├── AddPluginFolder(folder, args)
    ├── GetPlugin(pluginName)
    └── pluginsCache[]

Used By:
    ├── Template (template functions and modifiers)
    ├── Collection (filters and processors)
    ├── FlowManager (data flow operations)
    └── Statement (database query builders)
```

---

### Plugin Architecture

### PluginTrait

The `PluginTrait` is the core of the plugin system, providing:

```php
trait PluginTrait
{
    private static array $pluginFolder = [];
    private static array $pluginsCache = [];

    // Add a plugin folder path
    static public function AddPluginFolder(string $folder, mixed $args = null): void
    
    // Get a plugin by name (with caching)
    static private function GetPlugin(string $pluginName): ?array
}
```

### Plugin Discovery

1. **Register Folder**: Call `AddPluginFolder()` with the plugin directory path
2. **Request Plugin**: When a plugin is requested, the system searches registered folders
3. **Load & Cache**: Plugin file is loaded, closure extracted, and cached for reuse
4. **Return Entity**: Plugin closure is invoked to create the plugin instance

### Plugin Structure

Each plugin file must:
- Be a valid PHP file
- Return a `Closure` that creates the plugin instance
- Follow naming conventions for automatic discovery
- Extend appropriate base classes for type validation

---

### Plugin Types

### Template Plugins

Template plugins extend the template engine with custom functions and modifiers.

#### Functions (function.NAME.php)

Template functions process content with parameters and can enclose content.

**Base Classes:**
- `TFunction` - Standard function without enclosed content
- `TFunctionCustom` - Custom function with enclosed content

**Example: function.if.php**
```php
<?php
use Razy\Template\Entity;
use Razy\Template\Plugin\TFunctionCustom;

return function (...$arguments) {
    return new class(...$arguments) extends TFunctionCustom {
        protected bool $encloseContent = true;

        public function processor(Entity $entity, string $syntax = '', string $wrappedText = ''): string
        {
            // Parse conditional syntax
            $condition = evaluateCondition($syntax, $entity);
            
            // Parse enclosed content
            $parts = explode('{@else}', $wrappedText);
            
            return $entity->parseText($condition ? $parts[0] : ($parts[1] ?? ''));
        }
    };
};
```

**Usage in Templates:**
```html
{@if $user}
    Welcome, {$user.name}!
{@else}
    Please log in.
{@/if}
```

#### Modifiers (modifier.NAME.php)

Modifiers transform values in template expressions.

**Base Class:** `TModifier`

**Example: modifier.upper.php**
```php
<?php
use Razy\Template\Plugin\TModifier;

return function (...$arguments) {
    return new class(...$arguments) extends TModifier {
        protected function process(mixed $value, string ...$args): string
        {
            return strtoupper($value);
        }
    };
};
```

**Usage in Templates:**
```html
{$username|upper}
{$email|lower|trim}
```

---

### Collection Plugins

Collection plugins provide data filtering and processing capabilities.

#### Filters (filter.NAME.php)

Filters return boolean values to filter collection elements.

**Example: filter.istype.php**
```php
<?php
return function ($value, string $type = '') {
    return gettype($value) === strtolower($type);
};
```

**Usage:**
```php
$collection = new Collection(['a' => 1, 'b' => 'text', 'c' => 3]);

// Get only integer values
$integers = $collection('*:istype(integer)');
```

#### Processors (processor.NAME.php)

Processors transform collection element values.

**Example: processor.trim.php**
```php
<?php
return function ($value) {
    return is_string($value) ? trim($value) : $value;
};
```

**Usage:**
```php
$collection = new Collection([
    'name' => '  John  ',
    'email' => ' john@example.com '
]);

// Trim all string values
$trimmed = $collection('*')->trim();
```

---

### FlowManager Plugins

FlowManager plugins create data flow processing pipelines.

**Base Class:** `Razy\FlowManager\Flow`

**Example: Validate.php**
```php
<?php
namespace Razy\FlowManager\Flow;

use Razy\FlowManager\Flow;

return function (...$arguments) {
    return new class(...$arguments) extends Flow {
        public function __construct(private readonly string $name = '')
        {
            $this->recursive(true);
        }

        public function setValue(mixed $value): Flow
        {
            $this->parent->setValue($this->name, $value);
            return $this;
        }

        public function getValue(): mixed
        {
            return $this->parent->getValue($this->name);
        }

        public function reject(mixed $message = ''): Flow
        {
            $this->parent->reject($this->name, $message);
            return $this;
        }

        public function request(string $typeOfFlow = ''): bool
        {
            return $typeOfFlow === 'FormWorker';
        }
    };
};
```

**Usage:**
```php
$flowManager = new FlowManager();
$flow = $flowManager->start('Validate', 'email');
$flow->setValue($_POST['email'] ?? '');

if (!filter_var($flow->getValue(), FILTER_VALIDATE_EMAIL)) {
    $flow->reject('Invalid email address');
}
```

---

### Statement Plugins

Statement plugins extend database query building capabilities.

**Base Class:** `Razy\Database\Statement\Builder`

**Example: Max.php**
```php
<?php
use Razy\Database\Statement;
use Razy\Database\Statement\Builder;

return function (...$args) {
    return new class(...$args) extends Builder {
        public function __construct(
            private readonly string $compareColumn = '',
            private readonly array $indexColumns = []
        ) {}

        public function build(string $tableName): void
        {
            // Build subquery to find max values
            $statement = $this->statement
                ->select('a.*')
                ->from($tableName . ' AS a')
                ->leftJoin($tableName . ' AS b', function($join) {
                    $join->on('a.id', '=', 'b.id')
                         ->where('a.' . $this->compareColumn, '<', 'b.' . $this->compareColumn);
                })
                ->where('b.id', '=', null);
        }
    };
};
```

**Usage:**
```php
$db = new Database($config);
$stmt = $db->prepare()
    ->builder('Max', 'created_at', ['user_id'])
    ->from('posts');

$latestPosts = $stmt->execute()->fetchAll();
```

---

### Creating Plugins

### Step 1: Choose Plugin Type

Determine which plugin system fits your needs:

| System | Use Case | Returns |
|--------|----------|---------|
| **Template** | Template rendering logic | String/HTML output |
| **Collection** | Data filtering/transformation | Boolean or transformed value |
| **FlowManager** | Data flow processing | Flow object for chaining |
| **Statement** | Query building | Query builder modifications |

### Step 2: Follow Naming Conventions

Plugin files must follow specific naming patterns:

```
Template:
    function.FUNCTIONNAME.php
    modifier.MODIFIERNAME.php

Collection:
    filter.FILTERNAME.php
    processor.PROCESSORNAME.php

FlowManager:
    FLOWNAME.php (PascalCase)

Statement:
    BUILDERNAME.php (PascalCase)
```

### Step 3: Create Plugin File

#### Template Function Example

**File:** `src/plugins/Template/function.hello.php`

```php
<?php
use Razy\Template\Entity;
use Razy\Template\Plugin\TFunction;

return function (...$arguments) {
    return new class(...$arguments) extends TFunction {
        protected bool $encloseContent = false;
        protected bool $extendedParameter = true;
        protected array $allowedParameters = ['name', 'greeting'];

        protected function processor(
            Entity $entity, 
            array $parameters = [], 
            array $arguments = [], 
            string $wrappedText = ''
        ): ?string {
            $name = $parameters['name'] ?? 'Guest';
            $greeting = $parameters['greeting'] ?? 'Hello';
            
            return $greeting . ', ' . $name . '!';
        }
    };
};
```

**Usage:**
```html
{@hello name="John" greeting="Welcome"}
```

#### Collection Filter Example

**File:** `src/plugins/Collection/filter.minlength.php`

```php
<?php
return function ($value, int $minLength = 0) {
    return is_string($value) && strlen($value) >= $minLength;
};
```

**Usage:**
```php
// Filter strings with minimum 5 characters
$filtered = $collection('*:minlength(5)');
```

#### Collection Processor Example

**File:** `src/plugins/Collection/processor.uppercase.php`

```php
<?php
return function ($value) {
    return is_string($value) ? strtoupper($value) : $value;
};
```

**Usage:**
```php
// Convert all strings to uppercase
$result = $collection('*')->uppercase();
```

### Step 4: Implement Logic

#### Template Function Properties

```php
protected bool $encloseContent = false;      // Can wrap content {@func}...{@/func}
protected bool $extendedParameter = false;   // Use key="value" parameters
protected array $allowedParameters = [];     // Whitelist of parameter names
protected ?Controller $controller = null;    // Access to module controller
```

#### Template Modifier Methods

```php
// Main processing method
protected function process(mixed $value, string ...$args): ?string

// Called by template engine
final public function modify(mixed $value, string $paramText = ''): mixed
```

#### Flow Properties & Methods

```php
// Constructor receives flow arguments
public function __construct(...$arguments)

// Called when flow is requested
public function request(string $typeOfFlow = ''): bool

// Called when flow is resolved
public function resolve(...$args): bool

// Set recursive processing
protected function recursive(bool $recursive): void
```

---

### Loading Plugins

### Automatic Loading (Module Context)

Plugins in module directories are loaded automatically:

```
sites/mysite/vendor/mymodule/1.0.0/
    ├── plugins/
    │   ├── Template/
    │   │   ├── function.custom.php
    │   │   └── modifier.format.php
    │   ├── Collection/
    │   │   ├── filter.validate.php
    │   │   └── processor.sanitize.php
    │   ├── FlowManager/
    │   │   └── CustomFlow.php
    │   └── Statement/
    │       └── CustomBuilder.php
```

The framework automatically registers these paths:
```php
Template::AddPluginFolder(append($modulePath, 'plugins', 'Template'), $controller);
Collection::AddPluginFolder(append($modulePath, 'plugins', 'Collection'), $controller);
FlowManager::AddPluginFolder(append($modulePath, 'plugins', 'FlowManager'), $controller);
Statement::AddPluginFolder(append($modulePath, 'plugins', 'Statement'), $controller);
```

### Manual Loading

```php
// Load template plugins from custom location
Template::AddPluginFolder('/path/to/template/plugins', $controller);

// Load collection plugins
Collection::AddPluginFolder('/path/to/collection/plugins');

// Load flow manager plugins
FlowManager::AddPluginFolder('/path/to/flowmanager/plugins');

// Load statement builder plugins
Statement::AddPluginFolder('/path/to/statement/plugins');
```

### Plugin Caching

Plugins are cached after first load:
```php
// First call: loads from file
$plugin1 = Template::loadPlugin('function', 'example');

// Subsequent calls: returns cached instance
$plugin2 = Template::loadPlugin('function', 'example');

// $plugin1 === $plugin2 (same instance)
```

---

### Plugin Examples

### Example 1: Date Formatter (Template Modifier)

**File:** `modifier.dateformat.php`

```php
<?php
use Razy\Template\Plugin\TModifier;

return function (...$arguments) {
    return new class(...$arguments) extends TModifier {
        protected function process(mixed $value, string ...$args): string
        {
            $format = $args[0] ?? 'Y-m-d H:i:s';
            
            if (is_numeric($value)) {
                return date($format, $value);
            }
            
            if (is_string($value)) {
                $timestamp = strtotime($value);
                return $timestamp ? date($format, $timestamp) : $value;
            }
            
            return $value;
        }
    };
};
```

**Usage:**
```html
<!-- Default format -->
{$post.created_at|dateformat}

<!-- Custom format -->
{$post.created_at|dateformat:"F j, Y"}

<!-- Unix timestamp -->
{$timestamp|dateformat:"Y-m-d"}
```

### Example 2: Email Validator (Collection Filter)

**File:** `filter.email.php`

```php
<?php
return function ($value) {
    return is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL);
};
```

**Usage:**
```php
$contacts = new Collection([
    ['name' => 'John', 'email' => 'john@example.com'],
    ['name' => 'Jane', 'email' => 'invalid-email'],
    ['name' => 'Bob', 'email' => 'bob@example.com']
]);

// Get only valid email entries
$validEmails = $contacts('*.email:email');
```

### Example 3: Price Formatter (Collection Processor)

**File:** `processor.currency.php`

```php
<?php
return function ($value, string $symbol = '$', int $decimals = 2) {
    if (is_numeric($value)) {
        return $symbol . number_format($value, $decimals);
    }
    return $value;
};
```

**Usage:**
```php
$products = new Collection([
    ['name' => 'Laptop', 'price' => 999.99],
    ['name' => 'Mouse', 'price' => 29.5]
]);

// Format prices as currency
$formatted = $products('*.price')->currency('$', 2);
// Result: ['$999.99', '$29.50']
```

### Example 4: Sanitize Flow (FlowManager)

**File:** `Sanitize.php`

```php
<?php
namespace Razy\FlowManager\Flow;

use Razy\FlowManager\Flow;

return function (...$arguments) {
    return new class(...$arguments) extends Flow {
        public function __construct(
            private readonly string $name = '',
            private readonly string $type = 'string'
        ) {
            $this->recursive(true);
        }

        public function resolve(...$args): bool
        {
            $value = $this->parent->getValue($this->name);
            
            $sanitized = match($this->type) {
                'email' => filter_var($value, FILTER_SANITIZE_EMAIL),
                'url' => filter_var($value, FILTER_SANITIZE_URL),
                'int' => filter_var($value, FILTER_SANITIZE_NUMBER_INT),
                default => htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8')
            };
            
            $this->parent->setValue($this->name, $sanitized);
            return true;
        }

        public function request(string $typeOfFlow = ''): bool
        {
            return true;
        }
    };
};
```

**Usage:**
```php
$flow = new FlowManager();
$flow->start('Sanitize', 'user_email', 'email');
$flow->start('Sanitize', 'user_name', 'string');

$flow->setValue('user_email', '<script>evil@example.com');
$flow->setValue('user_name', '<b>John</b>');

$flow->resolve();

// Values are now sanitized
$email = $flow->getValue('user_email'); // "evil@example.com"
$name = $flow->getValue('user_name');   // "&lt;b&gt;John&lt;/b&gt;"
```

### Example 5: Paginate Builder (Statement)

**File:** `Paginate.php`

```php
<?php
use Razy\Database\Statement\Builder;

return function (...$args) {
    return new class(...$args) extends Builder {
        public function __construct(
            private readonly int $page = 1,
            private readonly int $perPage = 10
        ) {}

        public function build(string $tableName): void
        {
            $offset = ($this->page - 1) * $this->perPage;
            
            $this->statement
                ->limit($this->perPage)
                ->offset($offset);
        }
    };
};
```

**Usage:**
```php
$db = new Database($config);

// Get page 2 with 20 items per page
$stmt = $db->prepare()
    ->builder('Paginate', 2, 20)
    ->select('*')
    ->from('users')
    ->where('active=1');

$users = $stmt->execute()->fetchAll();
```

---

### Best Practices

### 1. Naming Conventions

```php
// ✅ Good
function.user_avatar.php    // Template function
modifier.trim_whitespace.php // Template modifier
filter.is_active.php        // Collection filter
processor.format_date.php   // Collection processor
CustomValidator.php         // FlowManager (PascalCase)
PaginationBuilder.php       // Statement builder (PascalCase)

// ❌ Bad
userAvatar.php              // Wrong casing
function-user-avatar.php    // Wrong separator
trim.php                    // Missing type prefix
```

### 2. Return Types

```php
// Template plugins must return string
protected function process(mixed $value, string ...$args): string

// Collection filters must return boolean
return function ($value): bool

// Collection processors return transformed value
return function ($value): mixed

// Flow plugins return Flow instance for chaining
public function setValue(mixed $value): Flow
```

### 3. Error Handling

```php
// Template plugin with validation
protected function process(mixed $value, string ...$args): string
{
    if (!isset($args[0])) {
        throw new \InvalidArgumentException('Format argument required');
    }
    
    try {
        return $this->format($value, $args[0]);
    } catch (\Exception $e) {
        // Log error and return safe fallback
        error_log($e->getMessage());
        return (string)$value;
    }
}
```

### 4. Type Safety

```php
// Always validate input types
return function ($value, string $type = '') {
    if (!is_string($value)) {
        return false; // Don't process non-strings
    }
    
    return gettype($value) === $type;
};
```

### 5. Controller Binding

```php
// Access module controller in template plugins
protected function processor(Entity $entity, ...): ?string
{
    // Access controller (if bound)
    if ($this->controller) {
        $config = $this->controller->getConfig();
        $modulePath = $this->controller->getModulePath();
    }
    
    return $result;
}
```

### 6. Performance

```php
// Cache expensive operations
private static array $cache = [];

return function ($value, string $format) {
    $cacheKey = md5($value . $format);
    
    if (!isset(self::$cache[$cacheKey])) {
        self::$cache[$cacheKey] = expensiveOperation($value, $format);
    }
    
    return self::$cache[$cacheKey];
};
```

### 7. Documentation

```php
/**
 * Format a date value using specified format string.
 * 
 * Supported formats:
 * - PHP date() format strings
 * - "human" for human-readable format
 * - "relative" for relative time (e.g., "2 hours ago")
 * 
 * @param mixed $value Unix timestamp, date string, or DateTime object
 * @param string ...$args [0] Format string (default: 'Y-m-d H:i:s')
 * @return string Formatted date string
 */
protected function process(mixed $value, string ...$args): string
{
    // Implementation
}
```

### 8. Namespace Usage

```php
// FlowManager and Statement plugins should use proper namespaces
namespace Razy\FlowManager\Flow;

use Razy\FlowManager\Flow;
use Razy\Error;

return function (...$arguments) {
    return new class(...$arguments) extends Flow {
        // Implementation
    };
};
```

---

### Built-in Plugins

### Template Functions

| Function | Description | Usage |
|----------|-------------|-------|
| `if` | Conditional rendering | `{@if $condition}...{@/if}` |
| `each` | Iterate over arrays | `{@each $items as $item}...{@/each}` |
| `repeat` | Repeat content N times | `{@repeat count=5}...{@/repeat}` |
| `template` | Include template file | `{@template file="header.tpl"}` |
| `def` | Define reusable blocks | `{@def name="block"}...{@/def}` |

### Template Modifiers

| Modifier | Description | Usage |
|----------|-------------|-------|
| `trim` | Remove whitespace | `{$text|trim}` |
| `upper` | Convert to uppercase | `{$text|upper}` |
| `lower` | Convert to lowercase | `{$text|lower}` |
| `capitalize` | Capitalize first letter | `{$text|capitalize}` |
| `nl2br` | Convert newlines to `<br>` | `{$text|nl2br}` |
| `addslashes` | Add slashes for escaping | `{$text|addslashes}` |
| `join` | Join array elements | `{$array|join:","}` |
| `alphabet` | Keep only letters | `{$text|alphabet}` |
| `gettype` | Get variable type | `{$var|gettype}` |

### Collection Filters

| Filter | Description | Usage |
|--------|-------------|-------|
| `istype` | Check value type | `*.value:istype(string)` |

### Collection Processors

| Processor | Description | Usage |
|-----------|-------------|-------|
| `trim` | Trim whitespace | `*.text->trim()` |
| `int` | Convert to integer | `*.id->int()` |
| `float` | Convert to float | `*.price->float()` |

### FlowManager Flows

| Flow | Description | Usage |
|------|-------------|-------|
| `Validate` | Field validation | `$flow->start('Validate', 'email')` |
| `Fetch` | Fetch value from source | `$flow->start('Fetch', 'user_id')` |
| `FetchGreatest` | Fetch greatest value | `$flow->start('FetchGreatest', 'scores')` |
| `FormWorker` | Form processing | `$flow->start('FormWorker')` |
| `NoEmpty` | Reject empty values | `$flow->start('NoEmpty', 'field')` |
| `Password` | Password hashing | `$flow->start('Password', 'password')` |
| `Regroup` | Regroup data | `$flow->start('Regroup', 'items')` |
| `Unique` | Ensure unique values | `$flow->start('Unique', 'email')` |
| `Custom` | Custom flow logic | `$flow->start('Custom')` |

### Statement Builders

| Builder | Description | Usage |
|---------|-------------|-------|
| `Max` | Find maximum values | `->builder('Max', 'created_at')` |

---

## Advanced Topics

### Plugin Inheritance

Create base plugin classes for common functionality:

```php
// BaseValidator.php
namespace MyModule\FlowManager;

use Razy\FlowManager\Flow;

abstract class BaseValidator extends Flow
{
    protected function validate(mixed $value): bool
    {
        // Common validation logic
        return true;
    }
    
    protected function reject(string $message): void
    {
        $this->parent->reject($this->name, $message);
    }
}

// EmailValidator.php
return function (...$arguments) {
    return new class(...$arguments) extends \MyModule\FlowManager\BaseValidator {
        public function resolve(...$args): bool
        {
            $valid = $this->validate($this->parent->getValue($this->name));
            if (!$valid) {
                $this->reject('Invalid email');
            }
            return $valid;
        }
    };
};
```

### Plugin Configuration

Pass configuration via `AddPluginFolder()`:

```php
// In module setup
$config = ['dateFormat' => 'Y-m-d', 'timezone' => 'UTC'];
Template::AddPluginFolder($pluginPath, $config);

// In plugin
protected function processor(Entity $entity, ...): ?string
{
    // Access via bound controller or args
    $format = $this->controller->getConfig('dateFormat');
    return date($format, $value);
}
```

### Dynamic Plugin Loading

```php
// Load plugins based on configuration
$pluginDirs = $config['plugin_directories'] ?? [];

foreach ($pluginDirs as $type => $dir) {
    match($type) {
        'template' => Template::AddPluginFolder($dir),
        'collection' => Collection::AddPluginFolder($dir),
        'flow' => FlowManager::AddPluginFolder($dir),
        'statement' => Statement::AddPluginFolder($dir),
    };
}
```

---

## Troubleshooting

### Plugin Not Found

**Symptom**: Plugin returns null or error when loaded

**Solutions**:
1. Check file naming: `function.NAME.php`, `modifier.NAME.php`, etc.
2. Verify plugin folder is registered: `AddPluginFolder($path)`
3. Ensure file returns a `Closure`
4. Check plugin extends correct base class

### Plugin Not Executing

**Symptom**: Plugin loads but doesn't produce expected output

**Solutions**:
1. Verify plugin method signatures match base class
2. Check return types (string, bool, Flow, etc.)
3. Add debug logging to track execution
4. Validate input parameters

### Type Errors

**Symptom**: Type mismatch errors when plugin loads

**Solutions**:
1. Ensure plugin extends correct base class:
   - Template functions: `TFunction` or `TFunctionCustom`
   - Template modifiers: `TModifier`
   - FlowManager: `Flow`
   - Statement: `Builder`
2. Check method return types match expectations
3. Validate constructor parameters

### Namespace Issues

**Symptom**: Class not found or autoload errors

**Solutions**:
1. Use correct namespace for FlowManager/Statement plugins
2. Include proper `use` statements
3. Return anonymous class if namespace conflicts occur

---

## Migration Guide

### From v0.4 to v0.5

**Plugin Loading:**
```php
// v0.4
Template::loadPluginFolder($path);

// v0.5
Template::AddPluginFolder($path, $controller);
```

**Plugin Returns:**
```php
// v0.4 - Return class instance
return new MyPlugin();

// v0.5 - Return closure factory
return function (...$arguments) {
    return new class(...$arguments) extends BasePlugin {
        // Implementation
    };
};
```

---

## Summary

The Razy plugin system provides:

✅ **Four plugin types** - Template, Collection, FlowManager, Statement  
✅ **Dynamic loading** - On-demand plugin instantiation with caching  
✅ **Type safety** - Base classes enforce correct implementations  
✅ **Modular design** - Self-contained files with clear naming  
✅ **Controller binding** - Access to module context when needed  
✅ **Performance** - Cached instances, minimal overhead  

**Next Steps:**
1. Browse built-in plugins in `src/plugins/` for examples
2. Create custom plugins in your module's `plugins/` directory
3. Test plugins in isolation before deployment
4. Document plugin usage for your team

---

**Version**: 0.5.4  
**Last Updated**: February 8, 2026  
**See Also**: [Module Development](MODULE-DEVELOPMENT.md), [Template System](TEMPLATE-SYSTEM.md), [Collection API](COLLECTION-API.md)
