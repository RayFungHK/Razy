# Razy\Cache\NullAdapter

## Summary
- No-op cache adapter — discards all data.
- All `get()` calls return the default; all `set()` calls return `true`; `has()` returns `false`.
- Used as fallback when cache is disabled or directory is unwritable.
- Useful for testing or development environments.

## Construction
- `new NullAdapter()` — No arguments required.

## Key Methods

| Method | Return | Description |
|--------|--------|-------------|
| `get($key, $default)` | `$default` | Always returns default |
| `set($key, $value, $ttl)` | `true` | Always succeeds (no-op) |
| `delete($key)` | `true` | Always succeeds (no-op) |
| `clear()` | `true` | Always succeeds (no-op) |
| `has($key)` | `false` | Always returns false |
| `getMultiple($keys, $default)` | `iterable` | All keys map to default |
| `setMultiple($values, $ttl)` | `true` | No-op |
| `deleteMultiple($keys)` | `true` | No-op |

## Usage Example

```php
use Razy\Cache;
use Razy\Cache\NullAdapter;

// Explicitly disable caching
Cache::setAdapter(new NullAdapter());

// Or equivalently
Cache::setEnabled(false);
```

## See Also
- [Razy.Cache.md](Razy.Cache.md) — Cache facade
- [Razy.Cache.CacheInterface.md](Razy.Cache.CacheInterface.md) — Interface contract
