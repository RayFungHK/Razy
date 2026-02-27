# Cache System Quick Reference

Quick lookup for the Razy PSR-16 cache system.

---

## Cache Facade (`Razy\Cache`)

```php
use Razy\Cache;
```

### Core Operations

| Method | Return | Description |
|--------|--------|-------------|
| `Cache::get($key, $default = null)` | `mixed` | Fetch a cached value |
| `Cache::set($key, $value, $ttl = null)` | `bool` | Store a value |
| `Cache::delete($key)` | `bool` | Remove a cached value |
| `Cache::has($key)` | `bool` | Check if key exists |
| `Cache::clear()` | `bool` | Wipe entire cache |

### Batch Operations

| Method | Return | Description |
|--------|--------|-------------|
| `Cache::getMultiple($keys, $default)` | `iterable` | Fetch multiple values |
| `Cache::setMultiple($values, $ttl)` | `bool` | Store multiple key => value pairs |
| `Cache::deleteMultiple($keys)` | `bool` | Remove multiple keys |

### File-Validated Caching

| Method | Return | Description |
|--------|--------|-------------|
| `Cache::getValidated($key, $filePath, $default)` | `mixed` | Get cached data, auto-invalidate if file changed |
| `Cache::setValidated($key, $filePath, $data, $ttl)` | `bool` | Store data with file mtime tracking |

### System Control

| Method | Return | Description |
|--------|--------|-------------|
| `Cache::initialize($dir, $adapter?)` | `void` | Initialize with directory or adapter |
| `Cache::isInitialized()` | `bool` | Check if initialized |
| `Cache::isEnabled()` | `bool` | Check if enabled |
| `Cache::setEnabled($bool)` | `void` | Enable/disable caching |
| `Cache::setAdapter($adapter)` | `void` | Swap cache adapter at runtime |
| `Cache::getAdapter()` | `CacheInterface` | Get current adapter |
| `Cache::reset()` | `void` | Reset to uninitialized state (testing) |

---

## TTL Reference

| Value | Behavior |
|-------|----------|
| `null` | No expiry (persists indefinitely) |
| `3600` | Expires in 3600 seconds (1 hour) |
| `new DateInterval('PT30M')` | Expires in 30 minutes |
| `0` or negative | Immediately deleted |

---

## Key Rules

**Valid characters**: Letters, numbers, dots, hyphens, underscores.

**Reserved (forbidden)**: `{ } ( ) / \ @ :`

**Empty keys**: Not allowed — throws `InvalidArgumentException`.

```
✅ user.profile.123
✅ yaml.a3f5c8d9e1b2
✅ config_database
❌ user/profile     (contains /)
❌ cache@key        (contains @)
❌ ""               (empty)
```

---

## Adapters

| Adapter | Storage | Best For |
|---------|---------|----------|
| `FileAdapter` | `data/cache/{xx}/{hash}.cache` | Default — works everywhere |
| `ApcuAdapter` | PHP shared memory (APCu) | High-traffic — requires `ext-apcu` |
| `NullAdapter` | None (discards all) | Testing / development |

### FileAdapter Extras

```php
$adapter = Cache::getAdapter();
if ($adapter instanceof FileAdapter) {
    $stats   = $adapter->getStats();   // ['directory', 'files', 'size']
    $removed = $adapter->gc();         // Garbage collect expired entries
}
```

---

## Common Patterns

### Simple Get-or-Compute

```php
$data = Cache::get('expensive.key');
if ($data === null) {
    $data = computeExpensiveResult();
    Cache::set('expensive.key', $data, 3600);
}
```

### File-Validated Config

```php
$key  = 'config.' . md5($path);
$data = Cache::getValidated($key, $path);
if ($data === null) {
    $data = YAML::parseFile($path);
    Cache::setValidated($key, $path, $data);
}
```

### Disable Caching Temporarily

```php
Cache::setEnabled(false);
// ... operations run without cache ...
Cache::setEnabled(true);
```

---

## CLI Commands

```bash
php Razy.phar cache status    # Show system status
php Razy.phar cache stats     # File count & size
php Razy.phar cache clear     # Wipe all cached data
php Razy.phar cache gc        # Remove expired entries only
```

---

## Framework Auto-Caching

| Component | Cache Key Pattern | Validation |
|-----------|------------------|------------|
| `YAML::parseFile()` | `yaml.{md5(realpath)}` | mtime-based |
| `Configuration` ctor | `config.{md5(realpath)}` | mtime-based |
| `Distributor::scanModule()` | `module.{md5(path)}` | Directory signature |

---

## See Also

- [CACHE-SYSTEM.md](../guides/CACHE-SYSTEM.md) — Full guide & tutorial
- [Razy.Cache.md](../usage/Razy.Cache.md) — Facade API reference
- [Razy.Cache.FileAdapter.md](../usage/Razy.Cache.FileAdapter.md) — FileAdapter API
- [Razy.Cache.CacheInterface.md](../usage/Razy.Cache.CacheInterface.md) — Interface contract
