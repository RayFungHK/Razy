# YAML Configuration Quick Reference

Quick syntax guide for YAML configuration files in Razy.

---

### YAML Syntax

#### Basic Structure

```yaml
# Comments start with #
key: value
number: 42
boolean: true
null_value: null

# Nested objects (mappings)
database:
  host: localhost
  port: 3306
  username: root

# Lists (sequences)
features:
  - authentication
  - api
  - admin

# Inline arrays
colors: [red, green, blue]

# Inline objects
server: {host: localhost, port: 8080}
```

#### Data Types

```yaml
# Strings
name: MyApp
quoted: "Hello World"
multi_word: Hello World  # No quotes needed

# Numbers
port: 3306
version: 1.5

# Booleans
debug: true
cache: false
enabled: yes  # Also true
disabled: no  # Also false

# Null
value: null
another: ~

# Lists
items:
  - item1
  - item2
  - item3

# Nested structures
user:
  name: John
  email: john@example.com
  roles:
    - admin
    - user
```

### Multi-line Strings

```yaml
# Literal (preserves newlines)
message: |
  This is line 1
  This is line 2
  This is line 3

# Folded (joins lines)
description: >
  This text will be
  folded into a single
  line with spaces.
```

## Razy Usage

### Load YAML Config

```php
use Razy\YAML;

// Parse YAML string
$data = YAML::parse($yamlString);

// Load YAML file
$config = YAML::parseFile('config/app.yaml');
```

### Save YAML Config

```php
$data = [
    'database' => [
        'host' => 'localhost',
        'port' => 3306,
    ],
];

// Dump to string
$yaml = YAML::dump($data);

// Save to file
YAML::dumpFile('config/database.yaml', $data);
```

### With Configuration Class

```php
use Razy\Configuration;

// Load YAML (auto-detected by extension)
$config = new Configuration('config/app.yaml');

// Access data
echo $config['database']['host'];  // localhost

// Modify
$config['database']['port'] = 5432;
$config['debug'] = true;

// Save (preserves YAML format)
$config->save();
```

## Common Config Examples

### Database Config

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
    
  redis:
    driver: redis
    host: localhost
    port: 6379
```

```php
$db = YAML::parseFile('database.yaml');
$mysql = $db['connections']['mysql'];
```

### Application Config

app.yaml:
```yaml
name: MyApp
version: 1.0.0
debug: false
timezone: UTC

paths:
  root: /var/www/html
  storage: /var/www/storage
  logs: /var/www/logs

features:
  auth: true
  api: true
  admin: true
```

```php
$app = YAML::parseFile('app.yaml');

if ($app['debug']) {
    error_reporting(E_ALL);
}
```

### Module Config

module.yaml:
```yaml
code: user-manager
version: 2.0.0
author: Ray Fung

dependencies:
  razit-core: ">=1.0"
  razit-auth: "~2.0"

routes:
  login: /user/login
  profile: /user/:id

settings:
  max_attempts: 3
  session_ttl: 3600
```

```php
$module = YAML::parseFile('module.yaml');
echo $module['code'];  // user-manager
```

## Module Integration

### package.php

```php
return [
    'alias' => 'mymodule',
    'config_file' => 'config.yaml',  // Load YAML config
];
```

config.yaml:
```yaml
enabled: true
max_items: 100
features:
  - feature1
  - feature2
```

```php
// In controller
$config = $this->getConfig();
echo $config['max_items'];  // 100
```

## API Reference

| Method | Returns | Description |
|--------|---------|-------------|
| `YAML::parse($yaml)` | `mixed` | Parse YAML string |
| `YAML::parseFile($file)` | `mixed` | Parse YAML file |
| `YAML::dump($data)` | `string` | Dump to YAML string |
| `YAML::dumpFile($file, $data)` | `bool` | Save to YAML file |

## Configuration Class

| Method | Description |
|--------|-------------|
| `new Configuration($path)` | Load config (PHP/JSON/INI/YAML) |
| `$config['key']` | Access value |
| `$config['key'] = $value` | Set value |
| `$config->save()` | Save changes |

**Supported Extensions**: `.php`, `.json`, `.ini`, `.yaml`, `.yml`

## YAML vs Other Formats

| Format | Readable | Nested | Comments | Best For |
|--------|----------|--------|----------|----------|
| YAML | ⭐⭐⭐⭐⭐ | ✅ | ✅ | Config files |
| JSON | ⭐⭐⭐ | ✅ | ❌ | APIs, data exchange |
| INI | ⭐⭐⭐⭐ | ⚠️ | ✅ | Simple configs |
| PHP | ⭐⭐ | ✅ | ✅ | Dynamic configs |

## Quick Syntax Rules

✅ **Do:**
- Use 2 or 4 spaces for indentation
- Add comments to document options
- Quote strings with special characters
- Use consistent naming (snake_case)
- Keep nesting depth reasonable (3-4 levels)

❌ **Don't:**
- Use tabs for indentation
- Mix tabs and spaces
- Nest too deeply (>5 levels)
- Use complex anchors/aliases for configs
- Store sensitive data without encryption

## Common Errors

| Error | Cause | Fix |
|-------|-------|-----|
| Parse error | Invalid YAML syntax | Check indentation, quotes |
| File not found | Wrong path | Verify file path |
| Not readable | Permission issue | Check file permissions |
| Write failed | Directory issue | Check directory exists/writable |

## Indentation Examples

✅ **Correct:**
```yaml
database:
  host: localhost
  port: 3306
```

❌ **Wrong (tabs):**
```yaml
database:
	host: localhost
```

❌ **Wrong (inconsistent):**
```yaml
database:
  host: localhost
   port: 3306
```

## Migration

### From PHP

```php
// config.php
<?php
return ['db' => ['host' => 'localhost']];
```

```yaml
# config.yaml (more readable)
db:
  host: localhost
```

### From JSON

```json
{"db":{"host":"localhost","port":3306}}
```

```yaml
# config.yaml (more readable)
db:
  host: localhost
  port: 3306
```

## Best Practices

1. ✅ Use YAML for configuration files
2. ✅ Add comments to document options
3. ✅ Use consistent indentation (2 spaces)
4. ✅ Version control your configs
5. ✅ Validate configs after loading
6. ✅ Keep configs simple and flat when possible
7. ✅ Use environment-specific configs (dev/prod)

## Environment-Specific Configs

```php
// Load environment-specific config
$env = getenv('APP_ENV') ?: 'dev';
$config = YAML::parseFile("config/app.{$env}.yaml");
```

app.dev.yaml:
```yaml
debug: true
database:
  host: localhost
```

app.prod.yaml:
```yaml
debug: false
database:
  host: prod-server
```

## Tips

💡 **Tip 1**: Use YAML for human-edited configs, JSON for machine-generated
💡 **Tip 2**: Keep secrets in environment variables, not YAML files
💡 **Tip 3**: Use `---` to separate multiple YAML documents in one file
💡 **Tip 4**: Validate YAML with online tools during development
💡 **Tip 5**: Create a config schema/template for consistency

## See Also

- Full guide: [usage/Razy.YAML.md](usage/Razy.YAML.md)
- Configuration: [usage/Razy.Configuration.md](usage/Razy.Configuration.md)
- YAML Spec: https://yaml.org/spec/
- YAML Tutorial: https://learnxinyminutes.com/docs/yaml/
