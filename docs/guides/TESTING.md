# Testing Guide

Comprehensive guide for testing Razy framework components using PHPUnit.

---

## Table of Contents

1. [Quick Start](#quick-start)
2. [Test Structure](#test-structure)
3. [Writing Tests](#writing-tests)
4. [Running Tests](#running-tests)
5. [Best Practices](#best-practices)
6. [Coverage](#coverage)

---

### Quick Start

#### Installation

```bash
# Install dev dependencies
composer install --dev
```

#### Run Tests

```bash
# Run all tests
composer test

# Or directly with PHPUnit
vendor/bin/phpunit

# Run specific test file
vendor/bin/phpunit tests/YAMLTest.php

# Run specific test method
vendor/bin/phpunit --filter testParseSimpleKeyValue

# Run with coverage
composer test-coverage
```

### Test Structure

```
tests/
├── bootstrap.php       # PHPUnit bootstrap file
├── YAMLTest.php       # YAML parser tests
└── README.md          # Test documentation
```

### Writing Tests

#### Basic Test Template

```php
<?php

namespace Razy\Tests;

use PHPUnit\Framework\TestCase;
use Razy\YourClass;

class YourClassTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Setup before each test
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // Cleanup after each test
    }

    public function testYourMethod(): void
    {
        // Arrange
        $obj = new YourClass();
        
        // Act
        $result = $obj->yourMethod();
        
        // Assert
        $this->assertEquals('expected', $result);
    }
}
```

### Test Naming Conventions

- Test files: `ClassNameTest.php`
- Test methods: `testMethodName()` or `testMethodNameDoesSpecificThing()`
- Use descriptive names that explain what is being tested

### Assertions

Common PHPUnit assertions:

```php
// Equality
$this->assertEquals($expected, $actual);
$this->assertSame($expected, $actual);  // Strict comparison

// Boolean
$this->assertTrue($value);
$this->assertFalse($value);

// Null
$this->assertNull($value);
$this->assertNotNull($value);

// Arrays
$this->assertIsArray($value);
$this->assertCount(3, $array);
$this->assertContains('item', $array);

// Strings
$this->assertStringContainsString('substring', $string);
$this->assertStringStartsWith('prefix', $string);

// Exceptions
$this->expectException(\Exception::class);
$this->expectExceptionMessage('Error message');
```

## Test Coverage Examples

### YAML Parser Tests

**tests/YAMLTest.php** - Comprehensive YAML parser testing:

#### Parsing Tests
- ✅ Simple key-value pairs
- ✅ Nested structures (3+ levels)
- ✅ Lists/sequences
- ✅ Inline arrays `[a, b, c]`
- ✅ Inline objects `{key: value}`
- ✅ Boolean values (true, false, yes, no, on, off)
- ✅ Null values (null, ~, empty)
- ✅ Numbers (int, float, negative)
- ✅ Quoted strings (double, single, plain)
- ✅ Comments (line and inline)
- ✅ Multi-line strings (literal `|` and folded `>`)

#### Dumping Tests
- ✅ Simple arrays to YAML
- ✅ Nested arrays
- ✅ Lists
- ✅ Data types (boolean, null, numbers)
- ✅ Custom indentation
- ✅ Inline mode control
- ✅ Special character quoting

#### File Operations
- ✅ Parse YAML files
- ✅ Dump to YAML files
- ✅ Error handling (file not found, not readable)
- ✅ Directory creation

#### Round-Trip Tests
- ✅ Parse → Dump → Parse validation
- ✅ Data integrity across conversions

## Testing Best Practices

### 1. Test Independence

Each test should be independent and not rely on other tests:

```php
// ❌ Bad - depends on order
public function testCreateUser(): void {
    $this->userId = createUser('test');
}

public function testDeleteUser(): void {
    deleteUser($this->userId);  // Depends on previous test
}

// ✅ Good - independent
public function testCreateUser(): void {
    $userId = createUser('test');
    $this->assertNotNull($userId);
}

public function testDeleteUser(): void {
    $userId = createUser('test');
    $result = deleteUser($userId);
    $this->assertTrue($result);
}
```

### 2. Test One Thing

Each test should verify one specific behavior:

```php
// ❌ Bad - tests multiple things
public function testUserCrud(): void {
    $user = createUser('test');
    $this->assertNotNull($user);
    
    $updated = updateUser($user->id, 'new name');
    $this->assertEquals('new name', $updated->name);
    
    deleteUser($user->id);
    $this->assertNull(findUser($user->id));
}

// ✅ Good - separate tests
public function testCreateUser(): void {
    $user = createUser('test');
    $this->assertNotNull($user);
}

public function testUpdateUser(): void {
    $user = createUser('test');
    $updated = updateUser($user->id, 'new name');
    $this->assertEquals('new name', $updated->name);
}

public function testDeleteUser(): void {
    $user = createUser('test');
    deleteUser($user->id);
    $this->assertNull(findUser($user->id));
}
```

### 3. Use Data Providers

For testing multiple inputs:

```php
/**
 * @dataProvider validEmailProvider
 */
public function testValidateEmail(string $email, bool $expected): void {
    $result = validateEmail($email);
    $this->assertEquals($expected, $result);
}

public function validEmailProvider(): array {
    return [
        ['user@example.com', true],
        ['invalid.email', false],
        ['test@domain.co.uk', true],
        ['@missing.com', false],
    ];
}
```

### 4. Test Edge Cases

```php
public function testDivision(): void {
    // Normal case
    $this->assertEquals(5, divide(10, 2));
    
    // Edge case: division by zero
    $this->expectException(\DivisionByZeroError::class);
    divide(10, 0);
}

public function testParseArray(): void {
    // Normal case
    $this->assertEquals(['a', 'b'], parseArray('a,b'));
    
    // Edge cases
    $this->assertEquals([], parseArray(''));
    $this->assertEquals(['single'], parseArray('single'));
}
```

### 5. Setup and Teardown

Use for common initialization:

```php
class DatabaseTest extends TestCase {
    private $db;
    
    protected function setUp(): void {
        parent::setUp();
        $this->db = new Database(':memory:');
        $this->db->migrate();
    }
    
    protected function tearDown(): void {
        $this->db->close();
        parent::tearDown();
    }
    
    public function testInsert(): void {
        $result = $this->db->insert('users', ['name' => 'Test']);
        $this->assertTrue($result);
    }
}
```

## Coverage Goals

Target coverage by component:

| Component | Target | Priority |
|-----------|--------|----------|
| YAML Parser | 90% | High |
| Configuration | 85% | High |
| Template Engine | 80% | High |
| Database | 80% | High |
| Router | 85% | High |
| Module System | 75% | Medium |
| Utilities | 70% | Medium |

### Check Coverage

```bash
# Generate HTML coverage report
composer test-coverage

# Open in browser
open coverage/index.html
```

## Continuous Integration

### GitHub Actions Example

Create `.github/workflows/tests.yml`:

```yaml
name: Tests

on:
  push:
    branches: [ main, develop ]
  pull_request:
    branches: [ main, develop ]

jobs:
  tests:
    runs-on: ubuntu-latest
    
    strategy:
      matrix:
        php-version: ['8.2', '8.3']
    
    steps:
      - uses: actions/checkout@v3
      
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php-version }}
          extensions: zip, curl, json
          coverage: xdebug
      
      - name: Install dependencies
        run: composer install --dev --no-interaction
      
      - name: Run tests
        run: composer test
      
      - name: Generate coverage
        run: composer test-coverage
      
      - name: Upload coverage
        uses: codecov/codecov-action@v3
        with:
          files: ./coverage/clover.xml
```

## Troubleshooting

### Tests Not Running

```bash
# Check PHPUnit installation
vendor/bin/phpunit --version

# Reinstall dependencies
rm -rf vendor composer.lock
composer install --dev
```

### Memory Issues

```bash
# Increase memory limit
php -d memory_limit=-1 vendor/bin/phpunit
```

### Slow Tests

```bash
# Run tests in parallel (requires paratest)
vendor/bin/paratest --processes=4
```

## Resources

- PHPUnit Documentation: https://phpunit.de/documentation.html
- Testing Best Practices: https://phpunit.de/best-practices.html
- Test-Driven Development: https://en.wikipedia.org/wiki/Test-driven_development
- Code Coverage: https://phpunit.de/code-coverage.html

## Next Steps

1. Run existing tests: `composer test`
2. Review test examples in `tests/YAMLTest.php`
3. Write tests for new features
4. Maintain 80%+ coverage for critical components
5. Set up CI/CD pipeline

## See Also

- [tests/README.md](../tests/README.md) - Test directory documentation
- [YAML Documentation](usage/Razy.YAML.md) - YAML parser documentation
- [Configuration Documentation](usage/Razy.Configuration.md) - Configuration class documentation
