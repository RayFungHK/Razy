# Razy\Cache\ApcuAdapter

## Summary
- APCu-based shared-memory cache adapter.
- Requires `ext-apcu` to be installed and enabled.
- All keys are prefixed with a configurable namespace (default: `razy_`).
- High-performance — no file I/O, data persists across PHP requests.

## Construction
- `new ApcuAdapter(string $prefix = 'razy_')` — Creates adapter with the given key prefix.
- Throws `InvalidArgumentException` if APCu is not available or not enabled.

## Key Methods

| Method | Return | Description |
|--------|--------|-------------|
| `get(string $key, mixed $default = null)` | `mixed` | Fetch from APCu store |
| `set(string $key, mixed $value, null\|int\|DateInterval $ttl = null)` | `bool` | Store in APCu with optional TTL |
| `delete(string $key)` | `bool` | Remove from APCu |
| `clear()` | `bool` | Remove only keys with the configured prefix |
| `has(string $key)` | `bool` | Check existence in APCu |
| `getMultiple(iterable $keys, mixed $default = null)` | `iterable` | Batch get |
| `setMultiple(iterable $values, null\|int\|DateInterval $ttl = null)` | `bool` | Batch set |
| `deleteMultiple(iterable $keys)` | `bool` | Batch delete |

## Usage Example

```php
use Razy\Cache;
use Razy\Cache\ApcuAdapter;

// Initialize Cache with APCu
Cache::initialize('', new ApcuAdapter());

// Custom prefix for multi-app servers
Cache::initialize('', new ApcuAdapter('myapp_'));
```

## Usage Notes
- `clear()` uses `APCUIterator` to selectively remove only keys with the configured prefix
- TTL is passed directly to `apcu_store()` — APCu handles expiry internally
- No garbage collection needed — APCu manages memory automatically
- Not suitable for CLI scripts unless `apc.enable_cli` is set in php.ini

## See Also
- [Razy.Cache.md](Razy.Cache.md) — Cache facade
- [Razy.Cache.CacheInterface.md](Razy.Cache.CacheInterface.md) — Interface contract
