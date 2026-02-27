# PSR Standards Quick Reference

Quick lookup for PSR-4 autoloading and PSR-12 coding standards used in Razy.

---

### Quick Commands

```bash
# Check code style violations
composer cs-check

# Auto-fix code style issues
composer cs-fix

# Run all quality checks (style + tests)
composer quality
```

---

### Currently Implemented

### PSR-4: Autoloading ✅
```php
namespace Razy;  // Maps to src/library/Razy/

use Razy\Application;  // Auto-loads from src/library/Razy/Application.php
```

### PSR-12: Coding Style ✅
**Enforced via `.php-cs-fixer.php` with 150+ rules**

---

### PSR-12 Rules

#### Class Structure
```php
<?php

namespace Razy;

use OtherClass;

class MyClass
{
    // Properties first
    private string $property;
    
    // Constructor
    public function __construct() {}
    
    // Public methods
    public function publicMethod(): void {}
    
    // Protected methods
    protected function protectedMethod(): string {}
    
    // Private methods
    private function privateMethod(): int {}
}
```

### 2. Type Declarations (PHP 8.2+)
```php
// ✅ Always use return types
public function getName(): string
{
    return $this->name;
}

// ✅ Nullable types
public function getUser(): ?User
{
    return $this->user ?? null;
}

// ✅ Union types
public function getValue(): int|float|string
{
    return $this->value;
}

// ✅ Mixed type
public function getData(): mixed
{
    return $this->data;
}
```

### 3. Arrays
```php
// ✅ Short syntax only
$array = [1, 2, 3];

// ✅ Multi-line trailing comma
$config = [
    'host' => 'localhost',
    'port' => 3306,
    'name' => 'db',
];

// ❌ Avoid old syntax
$array = array(1, 2, 3);  // NO
```

### 4. Control Structures
```php
// ✅ Opening brace on same line
if ($condition) {
    // Code
} elseif ($other) {
    // Code
} else {
    // Code
}

// ✅ Single statement still needs braces
if ($x) {
    return true;
}
```

### 5. Naming Conventions
```php
// Classes: PascalCase
class UserController {}

// Methods: camelCase
public function getUserById() {}

// Constants: UPPER_SNAKE_CASE
public const MAX_SIZE = 100;

// Properties: camelCase
private string $userName;
```

### 6. Imports
```php
// ✅ Alphabetically ordered
use Razy\Application;
use Razy\Configuration;
use Razy\Module;

// ✅ Remove unused imports
// ✅ One import per line
```

### 7. PHPDoc
```php
/**
 * Get user by ID.
 *
 * @param int $id User ID
 * @return User|null User object or null
 * @throws NotFoundException When user not found
 */
public function getUserById(int $id): ?User
{
    // Implementation
}
```

---

## 🔧 Common Fixes

### Before PSR-12
```php
<?php
namespace Razy;
use Razy\Application;    use Razy\Module;
class myClass {
    var $property;  // Old style
    function GetValue() {  // Wrong casing
        return Array(1,2,3);  // Old array syntax
    }
}
```

### After PSR-12
```php
<?php

namespace Razy;

use Razy\Application;
use Razy\Module;

class MyClass
{
    private mixed $property;
    
    public function getValue(): array
    {
        return [1, 2, 3];
    }
}
```

---

## 🎯 IDE Integration

### VSCode
1. Install: `junstyle.php-cs-fixer`
2. Enable format on save in settings

### PhpStorm
1. Settings → PHP → Quality Tools → PHP CS Fixer
2. Enable: "Enable auto-fix on save"

---

## 📊 Benefits

✅ **Consistent code** across entire project  
✅ **Easier code reviews** (focus on logic)  
✅ **Fewer merge conflicts** from formatting  
✅ **Professional quality** code  
✅ **Team productivity** improvements  

---

## 🚨 Before Committing

```bash
# Always run quality checks
composer quality

# Or just fix code style
composer cs-fix
```

---

## 📚 Learn More

- Full guide: [PSR-STANDARDS.md](../documentation/PSR-STANDARDS.md)
- Official PSR-12: https://www.php-fig.org/psr/psr-12/
- PHP CS Fixer: https://github.com/FriendsOfPHP/PHP-CS-Fixer

---

*Quick reference for Razy v0.5 - February 2026*
