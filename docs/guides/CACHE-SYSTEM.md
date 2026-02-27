# Cache System Guide

Complete guide to the Razy built-in PSR-16 cache system — initialization, usage, adapters, file-validated caching, framework integration, and CLI management.

---

## Table of Contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Getting Started](#getting-started)
4. [Basic Operations](#basic-operations)
5. [TTL (Time-To-Live)](#ttl-time-to-live)
6. [Batch Operations](#batch-operations)
7. [File-Validated Caching](#file-validated-caching)
8. [Cache Adapters](#cache-adapters)
   - [FileAdapter](#fileadapter)
   - [ApcuAdapter](#apcuadapter)
   - [NullAdapter](#nulladapter)
   - [Custom Adapters](#custom-adapters)
9. [Framework Integration](#framework-integration)
10. [CLI Commands](#cli-commands)
11. [Cache Key Rules](#cache-key-rules)
12. [Best Practices](#best-practices)
13. [Troubleshooting](#troubleshooting)

---

## Overview

Razy includes a built-in caching layer that follows the PSR-16 (Simple Cache) interface. The cache system is designed to accelerate repeated operations — YAML parsing, configuration loading, module discovery — without external dependencies.

### Key Features

- **PSR-16 Compatible**: Standard `get`/`set`/`delete`/`has` interface
- **Zero Configuration**: Auto-initializes with file-based caching in `data/cache/`
- **File-Validated Caching**: Automatic invalidation when source files change (mtime-based)
- **Multiple Adapters**: FileAdapter (default), ApcuAdapter, NullAdapter
- **Graceful Degradation**: Falls back to NullAdapter if cache directory is unwritable
- **CLI Management**: Built-in commands for clearing, garbage collection, and statistics
- **Atomic Writes**: FileAdapter uses temp-file + rename to prevent corruption

### Architecture Pattern

```
Cache (Static Facade)
    ├── initialize(dir, adapter?)
    ├── get(key, default?)
    ├── set(key, value, ttl?)
    ├── delete(key)
    ├── has(key)
    ├── getValidated(key, filePath)
    ├── setValidated(key, filePath, data, ttl?)
    └── Adapter (CacheInterface)
            ├── FileAdapter (default)
            ├── ApcuAdapter
            ├── NullAdapter
            └── Custom (implements CacheInterface)
```

---

## Architecture

### Components

| Component | Namespace | Role |
|-----------|-----------|------|
| `Cache` | `Razy\Cache` | Static facade — entry point for all cache operations |
| `CacheInterface` | `Razy\Cache\CacheInterface` | PSR-16 interface contract for adapters |
| `FileAdapter` | `Razy\Cache\FileAdapter` | File-based storage with directory sharding |
| `ApcuAdapter` | `Razy\Cache\ApcuAdapter` | APCu shared-memory storage |
| `NullAdapter` | `Razy\Cache\NullAdapter` | No-op adapter (disables caching) |
| `InvalidArgumentException` | `Razy\Cache\InvalidArgumentException` | PSR-16 exception |

### Directory Structure

```
src/library/Razy/
    Cache.php                        ← Static facade
    Cache/
        CacheInterface.php           ← PSR-16 interface
        FileAdapter.php              ← File-based adapter
        ApcuAdapter.php              ← APCu adapter
        NullAdapter.php              ← Null/no-op adapter
        InvalidArgumentException.php ← Exception class

src/system/terminal/
    cache.inc.php                    ← CLI command handler

data/cache/                          ← Default cache storage (auto-created)
    {xx}/                            ← Shard directories (first 2 chars of MD5)
        {hash}.cache                 ← Serialized cache entries
```

---

## Getting Started

### Automatic Initialization

The cache system is automatically initialized during Razy bootstrap. No manual setup is required for standard usage:

```php
// The framework calls this in bootstrap.inc.php:
// Cache::initialize(CACHE_FOLDER);
// CACHE_FOLDER = SYSTEM_ROOT . '/data/cache'

// You can immediately use the cache:
use Razy\Cache;

Cache::set('my.key', 'Hello World');
$value = Cache::get('my.key'); // "Hello World"
```

### Manual Initialization

For standalone scripts or custom setups:

```php
use Razy\Cache;

// Initialize with default FileAdapter
Cache::initialize('/path/to/cache/directory');

// Initialize with a custom adapter
use Razy\Cache\ApcuAdapter;
Cache::initialize('', new ApcuAdapter());
```

### Checking Status

```php
Cache::isInitialized(); // true if initialize() has been called
Cache::isEnabled();     // true if initialized AND not disabled
```

---

## Basic Operations

### Set a Value

```php
use Razy\Cache;

// Store a value (no expiry)
Cache::set('user.profile', ['name' => 'Ray', 'role' => 'admin']);

// Store with TTL (seconds)
Cache::set('session.token', $token, 3600); // 1 hour
```

### Get a Value

```php
// Get with null default
$profile = Cache::get('user.profile');

// Get with custom default
$theme = Cache::get('user.theme', 'light');
```

### Check Existence

```php
if (Cache::has('user.profile')) {
    $profile = Cache::get('user.profile');
}
```

### Delete a Value

```php
Cache::delete('user.profile');
```

### Clear All

```php
Cache::clear(); // Wipes the entire cache
```

---

## TTL (Time-To-Live)

TTL controls how long a cache entry remains valid.

### Integer Seconds

```php
Cache::set('key', 'value', 60);      // Expires in 60 seconds
Cache::set('key', 'value', 3600);    // Expires in 1 hour
Cache::set('key', 'value', 86400);   // Expires in 1 day
```

### DateInterval

```php
Cache::set('key', 'value', new \DateInterval('PT30M'));  // 30 minutes
Cache::set('key', 'value', new \DateInterval('P7D'));    // 7 days
```

### No Expiry

```php
Cache::set('key', 'value');          // null TTL = never expires
Cache::set('key', 'value', null);    // Explicit null = never expires
```

### Zero/Negative TTL

```php
Cache::set('key', 'value', 0);      // Immediately deleted (no-op)
Cache::set('key', 'value', -1);     // Immediately deleted (no-op)
```

---

## Batch Operations

### Get Multiple

```php
$values = Cache::getMultiple(['user.name', 'user.email', 'user.role']);
// Returns: ['user.name' => 'Ray', 'user.email' => null, 'user.role' => 'admin']

// With default for missing keys
$values = Cache::getMultiple(['key1', 'key2'], 'N/A');
```

### Set Multiple

```php
Cache::setMultiple([
    'config.app_name' => 'MyApp',
    'config.version'  => '1.0.0',
    'config.debug'    => true,
], 3600); // All expire in 1 hour
```

### Delete Multiple

```php
Cache::deleteMultiple(['session.token', 'session.user', 'session.flash']);
```

---

## File-Validated Caching

The most powerful feature of the Razy cache system. File-validated caching stores data alongside the source file's modification time (mtime). When retrieved, the system automatically checks if the source file has been modified — if so, the cache entry is considered stale and discarded.

This is used internally by YAML, Configuration, and Distributor to avoid re-parsing files that haven't changed.

### How It Works

```
setValidated('key', '/path/to/file', $data)
    → Stores: { mtime: file_mtime, data: $data }

getValidated('key', '/path/to/file')
    → If cached mtime == current file mtime → return data
    → If file modified → delete stale entry → return default
```

### Usage

```php
use Razy\Cache;

// Cache parsed data with file validation
$configPath = '/app/config/database.yaml';
$cacheKey   = 'config.database';

// Try cache first
$data = Cache::getValidated($cacheKey, $configPath);

if ($data === null) {
    // Cache miss or file was modified — parse fresh
    $data = YAML::parseFile($configPath);
    Cache::setValidated($cacheKey, $configPath, $data, 86400);
}

// $data is now the parsed config — fresh or cached
```

### Real-World Pattern (from YAML.php)

```php
public static function parseFile(string $path): mixed
{
    $realPath = realpath($path);
    $cacheKey = 'yaml.' . md5($realPath);

    // Try the cache with file validation
    $cached = Cache::getValidated($cacheKey, $realPath);
    if ($cached !== null) {
        return $cached;
    }

    // Parse fresh
    $result = self::parse(file_get_contents($realPath));

    // Store with file validation
    Cache::setValidated($cacheKey, $realPath, $result);

    return $result;
}
```

---

## Cache Adapters

### FileAdapter

The default adapter. Stores each cache entry as a serialized file in a sharded directory structure.

#### Storage Format

```
data/cache/
    a3/                              ← Shard directory (first 2 hex chars of MD5)
        a3f5c8d9e1b2...4567.cache   ← Serialized: ['e' => expiry, 'd' => data]
    7b/
        7bef01234abc...def0.cache
```

#### Features

- **Directory Sharding**: Keys are MD5-hashed, first 2 chars used as subdirectory (up to 256 shards)
- **Atomic Writes**: Data is written to a temp file, then atomically renamed
- **Lazy Purge**: Expired entries are cleaned up on access
- **Garbage Collection**: `gc()` method removes all expired entries
- **Statistics**: `getStats()` returns file count and total size

#### Direct Usage

```php
use Razy\Cache\FileAdapter;

$adapter = new FileAdapter('/path/to/cache');

// Standard operations
$adapter->set('key', 'value', 3600);
$value = $adapter->get('key');

// Statistics
$stats = $adapter->getStats();
// ['directory' => '/path/to/cache', 'files' => 42, 'size' => 128000]

// Garbage collection
$removed = $adapter->gc(); // Returns count of removed expired entries
```

### ApcuAdapter

High-performance shared-memory adapter using PHP's APCu extension.

#### Requirements

- `ext-apcu` must be installed and enabled
- APCu is shared across all PHP processes on the same server

#### Features

- **Namespace Isolation**: All keys are prefixed (default: `razy_`)
- **No File I/O**: Stored in shared memory — extremely fast
- **Per-Request Persistence**: Data survives across HTTP requests (unlike opcache)
- **Selective Clear**: `clear()` only removes keys with the configured prefix

#### Usage

```php
use Razy\Cache;
use Razy\Cache\ApcuAdapter;

// Initialize with APCu
Cache::initialize('', new ApcuAdapter());

// Or with custom prefix
Cache::initialize('', new ApcuAdapter('myapp_'));

// Or swap at runtime
Cache::setAdapter(new ApcuAdapter());
```

### NullAdapter

A no-op adapter that discards all data. Useful for disabling caching in development or testing.

```php
use Razy\Cache;
use Razy\Cache\NullAdapter;

// Explicit null adapter
Cache::setAdapter(new NullAdapter());

// Or simply disable
Cache::setEnabled(false);
```

All operations are safe to call — `get()` returns the default, `set()` returns `true`, `has()` returns `false`.

### Custom Adapters

Create a custom adapter by implementing `CacheInterface`:

```php
use Razy\Cache\CacheInterface;
use DateInterval;

class RedisAdapter implements CacheInterface
{
    private \Redis $redis;

    public function __construct(\Redis $redis)
    {
        $this->redis = $redis;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->redis->get($key);
        return $value === false ? $default : unserialize($value);
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $serialized = serialize($value);
        if ($ttl === null) {
            return $this->redis->set($key, $serialized);
        }
        $seconds = $ttl instanceof DateInterval
            ? (new \DateTime())->add($ttl)->getTimestamp() - time()
            : $ttl;
        return $this->redis->setex($key, max(1, $seconds), $serialized);
    }

    public function delete(string $key): bool
    {
        return $this->redis->del($key) >= 0;
    }

    public function clear(): bool
    {
        return $this->redis->flushDB();
    }

    public function has(string $key): bool
    {
        return $this->redis->exists($key) > 0;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $success = true;
        foreach ($values as $key => $value) {
            if (!$this->set($key, $value, $ttl)) {
                $success = false;
            }
        }
        return $success;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $success = true;
        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $success = false;
            }
        }
        return $success;
    }
}

// Register the custom adapter
Cache::setAdapter(new RedisAdapter($redis));
```

---

## Framework Integration

The cache system is integrated into several core components automatically.

### YAML Parser

`YAML::parseFile()` caches parsed results with mtime validation. If a YAML file hasn't changed since the last parse, the cached result is returned instantly.

```php
// First call: parses file, caches result
$config = YAML::parseFile('config/app.yaml');

// Second call: returns cached result (no parsing)
$config = YAML::parseFile('config/app.yaml');

// After editing config/app.yaml: re-parses automatically
$config = YAML::parseFile('config/app.yaml');
```

### Configuration

The `Configuration` class caches JSON, INI, and YAML config files with mtime validation. PHP config files are excluded (OPcache handles them).

```php
// First load: reads and parses file
$config = new Configuration('config/database.json');

// Subsequent loads: uses cache if file unchanged
$config = new Configuration('config/database.json');
```

### Module Discovery

`Distributor::scanModule()` caches the module manifest. The cache is invalidated when the module directory structure changes (file count, modification times).

```php
// First scan: reads filesystem, caches manifest
// Subsequent scans: uses cache if directory unchanged
```

---

## CLI Commands

Manage the cache from the command line using the `cache` command:

```bash
# Show cache system status
php Razy.phar cache status

# Output:
# Cache System Status:
#   Initialized:   Yes
#   Enabled:       Yes
#   Adapter:       FileAdapter
#   Directory:     /path/to/data/cache
#   Storage:       42 entries (128 KB)
```

### Available Subcommands

| Command | Description |
|---------|-------------|
| `php Razy.phar cache status` | Show initialization, adapter, and storage info |
| `php Razy.phar cache stats` | Display file count and total cache size |
| `php Razy.phar cache clear` | Wipe all cached data |
| `php Razy.phar cache gc` | Remove only expired entries (garbage collection) |

### Garbage Collection

The `gc` command scans all cache files, removes expired ones, and cleans up empty shard directories:

```bash
php Razy.phar cache gc
# Garbage collection complete. 15 expired entries removed.
```

---

## Cache Key Rules

Cache keys must follow PSR-16 rules:

### Valid Keys

```
user.profile
config.database
yaml.a3f5c8d9e1b24567
module.blog.manifest
template_compiled_header
```

### Invalid Keys (Reserved Characters)

The following characters are **not allowed** in cache keys: `{ } ( ) / \ @ :`

```php
// These will throw InvalidArgumentException:
Cache::set('user/profile', $data);   // Contains /
Cache::set('user:profile', $data);   // Contains :
Cache::set('cache@key', $data);      // Contains @
Cache::set('', $data);               // Empty key
```

### Key Naming Conventions

Use dot-separated namespaces for organizing keys:

```
yaml.{md5}              ← YAML file cache
config.{md5}            ← Configuration file cache
module.{md5}            ← Module manifest cache
app.user.count          ← Application-level cache
session.{id}.data       ← Session data
```

---

## Best Practices

### 1. Use File-Validated Caching for Configs

```php
// Good — automatically invalidates when file changes
$data = Cache::getValidated('config.app', $configPath);

// Avoid — stale data if file changes
$data = Cache::get('config.app');
```

### 2. Use Meaningful Key Names

```php
// Good
Cache::set('user.profile.' . $userId, $profile);

// Avoid
Cache::set('data1', $profile);
```

### 3. Set Appropriate TTLs

```php
// Frequently changing data — short TTL
Cache::set('api.rate_limit', $count, 60);

// Rarely changing data — long TTL or no expiry
Cache::set('app.version', $version);

// Session data — match session lifetime
Cache::set('session.' . $id, $data, 1800);
```

### 4. Graceful Fallback

The cache facade never throws exceptions — all errors return defaults silently. Always write code that works without cache:

```php
$data = Cache::get('expensive.result');
if ($data === null) {
    $data = computeExpensiveResult();
    Cache::set('expensive.result', $data, 3600);
}
```

### 5. Periodic Garbage Collection

Set up a cron job or use the CLI to periodically clean expired entries:

```bash
# Daily garbage collection
0 3 * * * php /path/to/Razy.phar cache gc
```

---

## Troubleshooting

### Cache Not Working

1. Check initialization: `Cache::isInitialized()` should return `true`
2. Check enabled: `Cache::isEnabled()` should return `true`
3. Check adapter: `Cache::getAdapter()` — should not be `NullAdapter`
4. Check permissions: The `data/cache/` directory must be writable

### Stale Data

- Use `getValidated()`/`setValidated()` for file-based data
- Set appropriate TTLs for volatile data
- Run `php Razy.phar cache clear` to force a fresh start

### Disk Space

- Run `php Razy.phar cache stats` to check cache size
- Run `php Razy.phar cache gc` to remove expired entries
- Consider setting TTLs on all cache entries

### Performance

- FileAdapter is suitable for most deployments
- For high-traffic applications, consider ApcuAdapter
- Cache entries are lazy-purged on access — no background process needed
- Use `gc` for periodic cleanup of entries that are never re-accessed

---

## Related Documentation

- [CACHE-QUICK-REFERENCE.md](../quick-reference/CACHE-QUICK-REFERENCE.md) — Quick lookup card
- [Razy.Cache.md](../usage/Razy.Cache.md) — API reference for Cache facade
- [Razy.Cache.FileAdapter.md](../usage/Razy.Cache.FileAdapter.md) — FileAdapter API reference
- [Razy.Cache.CacheInterface.md](../usage/Razy.Cache.CacheInterface.md) — CacheInterface API reference
- [YAML-QUICK-REFERENCE.md](../quick-reference/YAML-QUICK-REFERENCE.md) — YAML parser (uses cache)
