# Plugin System - Quick Reference

Fast reference for creating and using Razy plugins.

---

## Plugin Types Quick Chart

| Type | File Pattern | Base Class | Returns | Use For |
|------|-------------|------------|---------|---------|
| **Template Function** | `function.NAME.php` | `TFunction` or `TFunctionCustom` | `string` | Template logic |
| **Template Modifier** | `modifier.NAME.php` | `TModifier` | `string` | Value transformation |
| **Collection Filter** | `filter.NAME.php` | None (Closure) | `bool` | Filter elements |
| **Collection Processor** | `processor.NAME.php` | None (Closure) | `mixed` | Transform values |
| **Flow Manager** | `FLOWNAME.php` | `Flow` | `Flow` | Data pipelines |
| **Statement Builder** | `BUILDERNAME.php` | `Builder` | `void` | Query building |

---

## Quick Start Templates

### Template Modifier

```php
<?php
// File: modifier.mymod.php
use Razy\Template\Plugin\TModifier;

return function (...$arguments) {
    return new class(...$arguments) extends TModifier {
        protected function process(mixed $value, string ...$args): string
        {
            // Your logic here
            return (string)$value;
        }
    };
};
```

**Usage:** `{$variable|mymod}`

---

### Template Function (Simple)

```php
<?php
// File: function.myfunc.php
use Razy\Template\Entity;
use Razy\Template\Plugin\TFunction;

return function (...$arguments) {
    return new class(...$arguments) extends TFunction {
        protected function processor(
            Entity $entity, 
            array $parameters = [], 
            array $arguments = [], 
            string $wrappedText = ''
        ): ?string {
            // Your logic here
            return 'output';
        }
    };
};
```

**Usage:** `{@myfunc}`

---

### Template Function (With Content)

```php
<?php
// File: function.myfunc.php
use Razy\Template\Entity;
use Razy\Template\Plugin\TFunctionCustom;

return function (...$arguments) {
    return new class(...$arguments) extends TFunctionCustom {
        protected bool $encloseContent = true;

        public function processor(Entity $entity, string $syntax = '', string $wrappedText = ''): string
        {
            // Process $syntax (parameters)
            // Process $wrappedText (enclosed content)
            return $entity->parseText($wrappedText);
        }
    };
};
```

**Usage:** `{@myfunc}...content...{/myfunc}`

---

### Collection Filter

```php
<?php
// File: filter.myfilter.php
return function ($value, ...$args) {
    // Return boolean
    return is_string($value);
};
```

**Usage:** `$collection('*:myfilter')`

---

### Collection Processor

```php
<?php
// File: processor.myproc.php
return function ($value, ...$args) {
    // Return transformed value
    return strtoupper($value);
};
```

**Usage:** `$collection('*')->myproc()`

---

### FlowManager Plugin

```php
<?php
// File: MyFlow.php
namespace Razy\FlowManager\Flow;

use Razy\FlowManager\Flow;

return function (...$arguments) {
    return new class(...$arguments) extends Flow {
        public function __construct(private readonly string $name = '')
        {
            $this->recursive(true);
        }

        public function resolve(...$args): bool
        {
            // Your logic here
            return true;
        }

        public function request(string $typeOfFlow = ''): bool
        {
            return true; // or check specific flow type
        }
    };
};
```

**Usage:** `$flow->start('MyFlow', 'fieldname')`

---

### Statement Builder Plugin

```php
<?php
// File: MyBuilder.php
use Razy\Database\Statement\Builder;

return function (...$args) {
    return new class(...$args) extends Builder {
        public function __construct(private readonly string $param = '') {}

        public function build(string $tableName): void
        {
            // Modify $this->statement
            $this->statement
                ->select('*')
                ->from($tableName)
                ->where('active=1');
        }
    };
};
```

**Usage:** `$stmt->builder('MyBuilder', 'param')->from('users')`

---

## Built-in Template Tags

**Modifiers**: `{$var|upper}`, `{$var|trim|capitalize}`

**Conditionals**:
```
{@if $status="active"}
Active
{@else}
Inactive
{/if}
```

**Each Loop**:
```
{@each $items}
Key: {$kvp.key}
Value: {$kvp.value}
{/each}

{@each source=$items as="item"}
Key: {$item.key}
Value: {$item.value}
{/each}
```

**Repeat Loop**:
```
{@repeat 3}
Item
{/repeat}
```

**Define Variables**:
```
{@def "name" "value"}
{@def "copy" $data.path}
```

**Load Templates**:
```
{@template:SampleA paramA="test" paramB=$data.path}
```

---

## Common Patterns

### 1. With Default Arguments

```php
return function ($value, string $format = 'Y-m-d', string $timezone = 'UTC') {
    date_default_timezone_set($timezone);
    return date($format, strtotime($value));
};
```

### 2. Type Validation

```php
protected function process(mixed $value, string ...$args): string
{
    if (!is_string($value)) {
        return '';
    }
    
    return strtoupper($value);
}
```

### 3. Error Handling

```php
protected function process(mixed $value, string ...$args): string
{
    try {
        return $this->doSomething($value);
    } catch (\Exception $e) {
        error_log($e->getMessage());
        return (string)$value; // Safe fallback
    }
}
```

### 4. Access Controller

```php
protected function processor(Entity $entity, ...): ?string
{
    if ($this->controller) {
        $modulePath = $this->controller->getModulePath();
        $config = $this->controller->getConfig();
    }
    
    return $result;
}
```

### 5. Chainable Flows

```php
public function setValue(mixed $value): Flow
{
    $this->parent->setValue($this->name, $value);
    return $this; // Enable chaining
}

public function setOptions(array $opts): Flow
{
    $this->options = $opts;
    return $this; // Enable chaining
}
```

---

## Usage Examples

### Template Modifiers (Chained)

```html
{$email|trim|lower|addslashes}
{$price|number_format:2}
{$date|dateformat:"Y-m-d"}
```

### Template Functions

```html
<!-- Simple -->
{@now format="Y-m-d"}

<!-- With content -->
{@if $user.active}
    Active user
{@else}
    Inactive user
{@/if}

<!-- Iteration -->
{@each $items as $item}
    {$item.name}
{@/each}
```

### Collection Filters

```php
// Single filter
$result = $collection('*.email:email');

// Multiple filters
$result = $collection('*.price:between(10,100)|*.stock:gt(0)');

// Wildcard
$result = $collection('users.*.email:email');
```

### Collection Processors

```php
// Single processor
$result = $collection('*.name')->trim();

// Chained processors
$result = $collection('*.email')->trim()->lower();

// With arguments
$result = $collection('*.price')->round(2);
```

### FlowManager

```php
$flow = new FlowManager();

// Start flows
$flow->start('Validate', 'email')
     ->setValue($_POST['email']);

$flow->start('Sanitize', 'username')
     ->setValue($_POST['username']);

// Resolve all
$flow->resolve();

// Get results
$email = $flow->getValue('email');
$username = $flow->getValue('username');
```

### Statement Builders

```php
$stmt = $db->prepare()
    ->builder('Paginate', $page, $perPage)
    ->select('id', 'name', 'email')
    ->from('users')
    ->where('active=1')
    ->orderBy('created_at', 'DESC');

$users = $stmt->execute()->fetchAll();
```

---

## Loading Plugins

### Automatic (Module)

Place in module structure:
```
sites/mysite/vendor/mymodule/1.0.0/plugins/
    ├── Template/
    │   ├── function.custom.php
    │   └── modifier.custom.php
    ├── Collection/
    │   ├── filter.custom.php
    │   └── processor.custom.php
    ├── FlowManager/
    │   └── CustomFlow.php
    └── Statement/
        └── CustomBuilder.php
```

Automatically loaded when module loads.

### Manual

```php
// In your module setup
Template::AddPluginFolder($path . '/plugins/Template', $this);
Collection::AddPluginFolder($path . '/plugins/Collection');
FlowManager::AddPluginFolder($path . '/plugins/FlowManager');
Statement::AddPluginFolder($path . '/plugins/Statement');
```

---

## File Naming Rules

### ✅ Correct

```
Template:
    function.user_greeting.php
    function.format_price.php
    modifier.trim_spaces.php
    modifier.to_json.php

Collection:
    filter.is_valid.php
    filter.not_empty.php
    processor.uppercase.php
    processor.format_date.php

FlowManager:
    EmailValidator.php
    DataSanitizer.php
    CustomProcessor.php

Statement:
    PaginationBuilder.php
    SearchBuilder.php
    MaxValueBuilder.php
```

### ❌ Incorrect

```
userGreeting.php          // Missing type prefix
function-user-greeting.php // Wrong separator
FunctionUserGreeting.php  // Wrong casing
function_user_greeting.php // Wrong underscore position
```

---

## Return Type Reference

```php
// Template Function
public function processor(...): ?string

// Template Modifier
protected function process(...): ?string

// Collection Filter
return function (...): bool

// Collection Processor
return function (...): mixed

// FlowManager
public function resolve(...): bool
public function request(...): bool
public function setValue(...): Flow  // Chainable

// Statement Builder
public function build(string $tableName): void
```

---

## Property Reference

### Template Function

```php
protected bool $encloseContent = false; // {@func}...{@/func}
protected bool $extendedParameter = true; // name="value" syntax
protected array $allowedParameters = ['param1', 'param2'];
protected ?Controller $controller = null; // Module context
```

### Template Modifier

```php
protected ?Controller $controller = null; // Module context
```

### FlowManager

```php
protected function recursive(bool $recursive): void // Enable recursive
```

---

## Debugging Tips

### 1. Check Plugin Loading

```php
// In plugin file, add at top
error_log('Plugin loaded: ' . __FILE__);
```

### 2. Debug Plugin Execution

```php
protected function process(mixed $value, string ...$args): string
{
    error_log('Modifier input: ' . print_r($value, true));
    error_log('Modifier args: ' . print_r($args, true));
    
    $result = $this->doProcess($value, ...$args);
    
    error_log('Modifier output: ' . $result);
    return $result;
}
```

### 3. Verify Plugin Registration

```php
// Check if plugin folder exists
var_dump(is_dir($pluginFolder));

// Check if plugin file exists
var_dump(file_exists($pluginFolder . '/function.test.php'));
```

### 4. Test Plugin Directly

```php
// Test template modifier
$modifier = require 'modifier.test.php';
$instance = $modifier();
$result = $instance->modify('test value', 'arg1,arg2');
var_dump($result);
```

---

## Common Mistakes

### ❌ Wrong: Missing Return

```php
return function (...$arguments) {
    new class(...$arguments) extends TModifier {
        // Missing return statement
    };
    // Should return the instance
};
```

### ✅ Correct:

```php
return function (...$arguments) {
    return new class(...$arguments) extends TModifier {
        // Implementation
    };
};
```

---

### ❌ Wrong: Incorrect Base Class

```php
// Template function extending TModifier
return function (...$arguments) {
    return new class(...$arguments) extends TModifier {
        // Wrong base class for function
    };
};
```

### ✅ Correct:

```php
return function (...$arguments) {
    return new class(...$arguments) extends TFunction {
        // Correct base class
    };
};
```

---

### ❌ Wrong: Missing Type Hint

```php
protected function process($value, ...$args) {
    // Missing type hints
}
```

### ✅ Correct:

```php
protected function process(mixed $value, string ...$args): string {
    // Proper type hints
}
```

---

## Performance Tips

1. **Cache expensive operations**
   ```php
   private static array $cache = [];
   
   return function ($value) {
       if (!isset(self::$cache[$value])) {
           self::$cache[$value] = expensiveOp($value);
       }
       return self::$cache[$value];
   };
   ```

2. **Early return for invalid input**
   ```php
   protected function process(mixed $value, string ...$args): string
   {
       if (!is_string($value)) return '';
       if (strlen($value) === 0) return '';
       
       // Process only valid input
       return $this->doProcess($value);
   }
   ```

3. **Avoid repeated regex compilation**
   ```php
   private const PATTERN = '/[^a-z0-9]/i';
   
   protected function process(mixed $value, string ...$args): string
   {
       return preg_replace(self::PATTERN, '', $value);
   }
   ```

---

## Testing Checklist

- [ ] File named correctly (`function.NAME.php`, etc.)
- [ ] Returns closure that creates plugin instance
- [ ] Extends correct base class
- [ ] Method signatures match base class
- [ ] Return types specified
- [ ] Parameters validated
- [ ] Edge cases handled
- [ ] Error handling implemented
- [ ] Documentation added

---

## See Also

- [Full Plugin System Documentation](../guides/PLUGIN-SYSTEM.md)
- [Template System](TEMPLATE-SYSTEM.md)
- [Collection API](COLLECTION-API.md)
- [FlowManager Guide](FLOWMANAGER-GUIDE.md)
- [Database Query Builder](DATABASE-QUERY.md)

---

**Version**: 0.5.4  
**Last Updated**: February 8, 2026
