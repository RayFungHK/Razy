# Razy\Cache

## Summary
- Static facade for the PSR-16 cache system.
- Provides `get`/`set`/`delete`/`has`/`clear` operations.
- Supports batch operations, file-validated caching, and adapter swapping.
- Auto-initializes with `FileAdapter` during Razy bootstrap.
- Graceful degradation — never throws exceptions; returns defaults on failure.

## Initialization
- `Cache::initialize($cacheDir, $adapter?)` sets up the cache system.
- Called automatically by `bootstrap.inc.php` with `CACHE_FOLDER`.
- If the directory is unwritable, falls back to `NullAdapter`.

## Key Methods

### Core Operations

| Method | Return | Description |
|--------|--------|-------------|
| `get(string $key, mixed $default = null)` | `mixed` | Fetch a cached value |
| `set(string $key, mixed $value, null\|int\|DateInterval $ttl = null)` | `bool` | Store a value with optional TTL |
| `delete(string $key)` | `bool` | Remove a value by key |
| `has(string $key)` | `bool` | Check if key exists and is not expired |
| `clear()` | `bool` | Wipe all cached data |

### Batch Operations

| Method | Return | Description |
|--------|--------|-------------|
| `getMultiple(iterable $keys, mixed $default = null)` | `iterable` | Fetch multiple keys |
| `setMultiple(iterable $values, null\|int\|DateInterval $ttl = null)` | `bool` | Store multiple key => value pairs |
| `deleteMultiple(iterable $keys)` | `bool` | Delete multiple keys |

### File-Validated Caching

| Method | Return | Description |
|--------|--------|-------------|
| `getValidated(string $key, string $filePath, mixed $default = null)` | `mixed` | Retrieve cached data, auto-invalidate if file mtime changed |
| `setValidated(string $key, string $filePath, mixed $data, null\|int\|DateInterval $ttl = null)` | `bool` | Store data with file mtime metadata |

### System Control

| Method | Return | Description |
|--------|--------|-------------|
| `initialize(string $cacheDir = '', ?CacheInterface $adapter = null)` | `void` | Initialize the cache system |
| `isInitialized()` | `bool` | Whether `initialize()` has been called |
| `isEnabled()` | `bool` | Whether cache is initialized and enabled |
| `setEnabled(bool $enabled)` | `void` | Enable or disable caching |
| `setAdapter(CacheInterface $adapter)` | `void` | Swap the active adapter |
| `getAdapter()` | `CacheInterface` | Get the active adapter |
| `reset()` | `void` | Reset to uninitialized state (for testing) |

## Usage Example

```php
use Razy\Cache;

// Basic get/set
Cache::set('user.profile', ['name' => 'Ray', 'role' => 'admin'], 3600);
$profile = Cache::get('user.profile');

// File-validated caching
$data = Cache::getValidated('config.db', '/app/config/db.yaml');
if ($data === null) {
    $data = parseConfig('/app/config/db.yaml');
    Cache::setValidated('config.db', '/app/config/db.yaml', $data);
}

// Batch operations
Cache::setMultiple(['key1' => 'val1', 'key2' => 'val2'], 600);
$values = Cache::getMultiple(['key1', 'key2']);
```

## Usage Notes
- All operations are exception-safe — errors return defaults silently
- When disabled (`setEnabled(false)`), all gets return default, all sets return false
- `getValidated()`/`setValidated()` compare file mtime to detect changes
- `clear()` works even when cache is disabled (requires only initialization)
- `reset()` is intended for testing only

## See Also
- [Razy.Cache.CacheInterface.md](Razy.Cache.CacheInterface.md) — PSR-16 interface
- [Razy.Cache.FileAdapter.md](Razy.Cache.FileAdapter.md) — File-based adapter
- [Razy.Cache.ApcuAdapter.md](Razy.Cache.ApcuAdapter.md) — APCu adapter
- [Razy.Cache.NullAdapter.md](Razy.Cache.NullAdapter.md) — No-op adapter
