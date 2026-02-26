# Razy Framework Tests

Unit tests for the Razy PHP framework using PHPUnit.

## Setup

Install PHPUnit via Composer:

```bash
composer install --dev
```

## Running Tests

### Run all tests

```bash
composer test
```

Or directly with PHPUnit:

```bash
vendor/bin/phpunit
```

### Run specific test file

```bash
vendor/bin/phpunit tests/YAMLTest.php
```

### Run with code coverage

```bash
composer test-coverage
```

Coverage report will be generated in `coverage/` directory.

### Run specific test method

```bash
vendor/bin/phpunit --filter testParseSimpleKeyValue
```

## Test Structure

```
tests/
├── bootstrap.php       # PHPUnit bootstrap
├── YAMLTest.php       # YAML parser tests
└── README.md          # This file
```

## Test Coverage

Current test files:
- **YAMLTest.php**: Tests for YAML parser and dumper
  - Parsing: simple values, nested structures, lists, data types
  - Dumping: array to YAML conversion, formatting options
  - File operations: parseFile(), dumpFile()
  - Round-trip: parse → dump → parse validation
  - Edge cases: empty arrays, special characters, multiline strings

## Writing New Tests

1. Create a new test file in `tests/` directory
2. Extend `PHPUnit\Framework\TestCase`
3. Use namespace `Razy\Tests`
4. Add test methods with `test` prefix

Example:

```php
<?php

namespace Razy\Tests;

use PHPUnit\Framework\TestCase;
use Razy\YourClass;

class YourClassTest extends TestCase
{
    public function testYourMethod(): void
    {
        $obj = new YourClass();
        $result = $obj->yourMethod();
        
        $this->assertEquals('expected', $result);
    }
}
```

## PHPUnit Configuration

Configuration is in `phpunit.xml` at the root:
- Test suite: All files in `tests/` directory
- Bootstrap: `tests/bootstrap.php`
- Code coverage: `src/library/Razy/` directory

## Continuous Integration

Tests can be integrated with CI/CD pipelines:

### GitHub Actions

```yaml
name: Tests

on: [push, pull_request]

jobs:
  tests:
    runs-on: ubuntu-latest
    
    steps:
      - uses: actions/checkout@v3
      
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          extensions: zip, curl, json
          
      - name: Install dependencies
        run: composer install --dev
        
      - name: Run tests
        run: composer test
```

## Best Practices

1. **Test naming**: Use descriptive names starting with `test`
2. **Assertions**: Use specific assertions (`assertEquals`, `assertTrue`, etc.)
3. **Setup/Teardown**: Use `setUp()` and `tearDown()` for common setup
4. **Test isolation**: Each test should be independent
5. **Edge cases**: Test boundary conditions and error cases
6. **Documentation**: Add comments for complex test logic

## Test Coverage Goals

- **Critical classes**: 80%+ coverage
- **Parser/Dumper**: 90%+ coverage
- **Configuration**: 80%+ coverage
- **Database**: 75%+ coverage
- **Utilities**: 70%+ coverage

## See Also

- PHPUnit Documentation: https://phpunit.de/documentation.html
- Composer Scripts: https://getcomposer.org/doc/articles/scripts.md
- Testing Best Practices: https://phpunit.de/best-practices.html
