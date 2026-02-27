# Razy\YAML

Native YAML parser and dumper for configuration files. Pure PHP implementation without external dependencies.

**File**: `src/library/Razy/YAML.php`

## Purpose

- Parse YAML files into PHP arrays
- Dump PHP arrays into YAML format
- Configuration file support (.yaml/.yml)
- No external dependencies required

## Key Concepts

### YAML Format

YAML (YAML Ain't Markup Language) is a human-readable data serialization format:

```yaml
# Configuration
database:
  host: localhost
  port: 3306
  name: myapp
  
users:
  - name: John
    email: john@example.com
  - name: Jane
    email: jane@example.com
```

### Supported Features

- **Mappings**: Key-value pairs (objects)
- **Sequences**: Lists/arrays
- **Scalars**: Strings, numbers, booleans, null
- **Comments**: Lines starting with #
- **Multi-line strings**: Literal | and folded >
- **Nested structures**: Indentation-based
- **Flow collections**: Inline [arrays] and {objects}
- **Anchors & aliases**: &anchor and *alias

### Not Supported

- Tags (!tag)
- Complex keys
- Multiline keys
- Explicit typing
- YAML 1.1 specific features

## Public API

### Parsing

#### `static parse(string $yaml): mixed`

Parse YAML string into PHP data.

```php
$yaml = <<<YAML
name: MyApp
version: 1.0
features:
  - authentication
  - api
  - admin
YAML;

$data = YAML::parse($yaml);
// ['name' => 'MyApp', 'version' => 1.0, 'features' => [...]]
```

#### `static parseFile(string $filename): mixed`

Parse YAML file into PHP data.

```php
$config = YAML::parseFile('config/app.yaml');

echo $config['database']['host'];  // localhost
echo $config['database']['port'];  // 3306
```

**Throws**: `Error` if file not found, not readable, or parse error

### Dumping

#### `static dump(mixed $data, int $indent = 2, int $inline = 4): string`

Dump PHP data to YAML string.

```php
$data = [
    'app' => [
        'name' => 'MyApp',
        'debug' => true,
    ],
    'database' => [
        'host' => 'localhost',
        'port' => 3306,
    ],
];

$yaml = YAML::dump($data);
echo $yaml;
```

Output:
```yaml
app:
  name: MyApp
  debug: true
database:
  host: localhost
  port: 3306
```

**Parameters**:
- `$indent`: Indentation spaces (default: 2)
- `$inline`: Inline arrays from this level (default: 4)

#### `static dumpFile(string $filename, mixed $data, int $indent = 2, int $inline = 4): bool`

Dump PHP data to YAML file.

```php
$config = [
    'database' => [
        'host' => 'localhost',
        'port' => 3306,
    ],
];

YAML::dumpFile('config/database.yaml', $config);
```

**Returns**: `true` on success

**Throws**: `Error` if directory creation or write fails

## Usage Patterns

### Configuration Files

```php
// Load configuration
$config = YAML::parseFile('config/app.yaml');

// Modify
$config['database']['host'] = 'prod-server';
$config['cache']['enabled'] = true;

// Save
YAML::dumpFile('config/app.yaml', $config);
```

### With Configuration Class

```php
// Load YAML config
$config = new Configuration('config/app.yaml');

// Access data
echo $config['database']['host'];  // localhost

// Modify
$config['database']['port'] = 5432;

// Auto-save as YAML
$config->save();
```

### Nested Structures

#### Parse nested YAML

config.yaml:
```yaml
app:
  name: MyApp
  settings:
    timezone: UTC
    locale: en_US
    features:
      auth: true
      api: true
      debug: false
```

```php
$config = YAML::parseFile('config.yaml');

echo $config['app']['name'];                    // MyApp
echo $config['app']['settings']['timezone'];    // UTC
echo $config['app']['settings']['features']['auth'];  // true
```

#### Dump nested arrays

```php
$data = [
    'server' => [
        'host' => 'localhost',
        'ssl' => [
            'enabled' => true,
            'cert' => '/path/to/cert.pem',
            'key' => '/path/to/key.pem',
        ],
    ],
];

echo YAML::dump($data);
```

Output:
```yaml
server:
  host: localhost
  ssl:
    enabled: true
    cert: /path/to/cert.pem
    key: /path/to/key.pem
```

### Lists/Arrays

```php
// Sequential array (YAML list)
$users = [
    ['name' => 'John', 'email' => 'john@example.com'],
    ['name' => 'Jane', 'email' => 'jane@example.com'],
];

echo YAML::dump(['users' => $users]);
```

Output:
```yaml
users:
  - name: John
    email: john@example.com
  - name: Jane
    email: jane@example.com
```

### Multi-line Strings

#### Literal (preserves newlines)

config.yaml:
```yaml
message: |
  This is a
  multi-line
  message.
```

```php
$config = YAML::parseFile('config.yaml');
echo $config['message'];
// "This is a\nmulti-line\nmessage."
```

#### Folded (joins lines)

config.yaml:
```yaml
description: >
  This is a long
  description that
  will be folded.
```

```php
$config = YAML::parseFile('config.yaml');
echo $config['description'];
// "This is a long description that will be folded."
```

### Comments

```yaml
# Application configuration
app:
  name: MyApp  # Application name
  version: 1.0  # Version number
  
# Database settings
database:
  host: localhost  # DB host
  port: 3306       # DB port
```

```php
$config = YAML::parseFile('config.yaml');
// Comments are ignored during parsing
```

### Inline Collections

#### Inline arrays

```yaml
colors: [red, green, blue]
sizes: [small, medium, large]
```

```php
$data = YAML::parseFile('config.yaml');
// ['colors' => ['red', 'green', 'blue'], ...]
```

#### Inline objects

```yaml
server: {host: localhost, port: 8080}
cache: {enabled: true, ttl: 3600}
```

```php
$data = YAML::parseFile('config.yaml');
// ['server' => ['host' => 'localhost', 'port' => 8080], ...]
```

### Data Types

```yaml
# String
name: MyApp
quoted: "Hello World"

# Number
port: 3306
version: 1.5

# Boolean
debug: true
cache: false

# Null
value: null
another: ~

# Array
items: [1, 2, 3]

# Object
user: {name: John, age: 30}
```

```php
$data = YAML::parse($yaml);

$data['name'];     // "MyApp" (string)
$data['port'];     // 3306 (int)
$data['version'];  // 1.5 (float)
$data['debug'];    // true (bool)
$data['value'];    // null
$data['items'];    // [1, 2, 3] (array)
$data['user'];     // ['name' => 'John', 'age' => 30] (array)
```

## Configuration File Examples

### Database Configuration

database.yaml:
```yaml
default: mysql

connections:
  mysql:
    driver: mysql
    host: localhost
    port: 3306
    database: myapp
    username: root
    password: secret
    charset: utf8mb4
    
  redis:
    driver: redis
    host: localhost
    port: 6379
    database: 0
```

```php
$config = YAML::parseFile('database.yaml');

$mysql = $config['connections']['mysql'];
$conn = new PDO(
    "mysql:host={$mysql['host']};dbname={$mysql['database']}",
    $mysql['username'],
    $mysql['password']
);
```

### Application Configuration

app.yaml:
```yaml
name: MyApplication
version: 1.0.0
debug: false
timezone: UTC

paths:
  root: /var/www/html
  storage: /var/www/storage
  logs: /var/www/logs

features:
  authentication: true
  api: true
  admin: true
  logging: true

services:
  - database
  - cache
  - mail
  - queue
```

```php
$app = YAML::parseFile('app.yaml');

if ($app['debug']) {
    error_reporting(E_ALL);
}

date_default_timezone_set($app['timezone']);

foreach ($app['services'] as $service) {
    loadService($service);
}
```

### Module Configuration

module.yaml:
```yaml
code: user-manager
version: 2.1.0
author: Ray Fung
description: User management module

dependencies:
  - razit-core: ">=1.0"
  - razit-auth: "~2.0"

routes:
  login: /user/login
  register: /user/register
  profile: /user/profile/:id

settings:
  max_login_attempts: 3
  session_lifetime: 3600
  password_min_length: 8
```

```php
$module = YAML::parseFile('module.yaml');

echo $module['code'];        // user-manager
echo $module['version'];     // 2.1.0

foreach ($module['dependencies'] as $dep => $version) {
    checkDependency($dep, $version);
}

foreach ($module['routes'] as $name => $path) {
    registerRoute($name, $path);
}
```

## Error Handling

```php
try {
    $data = YAML::parseFile('config.yaml');
} catch (Error $e) {
    echo "YAML error: " . $e->getMessage();
}

// Specific errors:
// - YAML file not found: config.yaml
// - YAML file not readable: config.yaml
// - Failed to read YAML file: config.yaml
// - YAML parse error: ...
// - Failed to create directory: ...
// - Failed to write YAML file: ...
```

## Dumping Options

### Indentation

```php
// 2 spaces (default)
echo YAML::dump($data, 2);

// 4 spaces
echo YAML::dump($data, 4);
```

### Inline Level

```php
$data = [
    'level1' => [
        'level2' => [
            'level3' => [
                'level4' => ['a', 'b', 'c']
            ]
        ]
    ]
];

// Inline from level 4 (default)
echo YAML::dump($data, 2, 4);
// level4: [a, b, c]

// Inline from level 2
echo YAML::dump($data, 2, 2);
// level1: {level2: ...}

// Never inline
echo YAML::dump($data, 2, 999);
// All nested structures expanded
```

## Module Integration

### package.php with YAML config

```php
// package.php
return [
    'alias' => 'user',
    'config_file' => 'config.yaml',  // Load YAML config
    'api_name' => 'user-api',
];
```

config.yaml:
```yaml
max_users: 1000
registration:
  enabled: true
  email_verification: true
  
roles:
  - admin
  - moderator
  - user
```

```php
// In controller
$config = $this->getConfig();  // Loads from config.yaml
echo $config['max_users'];  // 1000
```

### Module Configuration API

```php
public function __onInit(Agent $agent): bool {
    // Load module config (supports YAML)
    $config = $this->getModule()->getConfig();
    
    if ($config['feature_enabled']) {
        $this->enableFeature();
    }
    
    return true;
}
```

## Performance Considerations

- **Small files**: Native parser is fast enough (<100ms for typical configs)
- **Large files**: Consider caching parsed results
- **Frequent reads**: Use Configuration class with in-memory caching
- **Production**: Pre-parse YAML to PHP arrays for best performance

## Migration from PHP/JSON Config

### From PHP

```php
// Old: config.php
<?php
return [
    'database' => ['host' => 'localhost'],
];

// New: config.yaml
database:
  host: localhost
```

```php
// Before
$config = require 'config.php';

// After
$config = YAML::parseFile('config.yaml');
```

### From JSON

```php
// Old: config.json
{"database":{"host":"localhost"}}

// New: config.yaml (more readable)
database:
  host: localhost
```

```php
// Before
$config = json_decode(file_get_contents('config.json'), true);

// After
$config = YAML::parseFile('config.yaml');
```

## Best Practices

1. **Use YAML for configuration files** - Human-readable and maintainable
2. **Use consistent indentation** - 2 or 4 spaces, never tabs
3. **Add comments** - Document configuration options
4. **Quote strings with special chars** - Avoid parsing issues
5. **Keep it simple** - Don't nest too deeply (3-4 levels max)
6. **Validate after parsing** - Check required keys exist
7. **Version control** - Track config changes in git

## Related Classes

- **Configuration**: Auto-loads YAML config files
- **ModuleInfo**: Module metadata (can use YAML)
- **Template**: Template configuration

## See Also

- Configuration: [Razy.Configuration.md](Razy.Configuration.md)
- YAML Spec: https://yaml.org/spec/
- YAML Tutorial: https://learnxinyminutes.com/docs/yaml/
