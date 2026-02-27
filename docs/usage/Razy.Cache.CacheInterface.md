# Razy\Cache\CacheInterface

## Summary
- PSR-16 (Simple Cache) compatible interface.
- Defines the contract for all cache adapters in the Razy framework.
- Implemented by `FileAdapter`, `ApcuAdapter`, and `NullAdapter`.

## Interface Methods

| Method | Return | Description |
|--------|--------|-------------|
| `get(string $key, mixed $default = null)` | `mixed` | Fetch a value by key |
| `set(string $key, mixed $value, null\|int\|DateInterval $ttl = null)` | `bool` | Store a value with optional TTL |
| `delete(string $key)` | `bool` | Remove a value by key |
| `clear()` | `bool` | Wipe all cached data |
| `has(string $key)` | `bool` | Check if a key exists and is not expired |
| `getMultiple(iterable $keys, mixed $default = null)` | `iterable` | Fetch multiple keys at once |
| `setMultiple(iterable $values, null\|int\|DateInterval $ttl = null)` | `bool` | Store multiple key => value pairs |
| `deleteMultiple(iterable $keys)` | `bool` | Delete multiple keys at once |

## TTL Parameter

- `null` — No expiry (persist indefinitely)
- `int` — Seconds from now until expiry
- `DateInterval` — PHP DateInterval object
- `0` or negative — Item should be deleted immediately

## Key Validation

Keys must be non-empty strings. The following characters are reserved and must not appear in keys:
`{ } ( ) / \ @ :`

Invalid keys throw `Razy\Cache\InvalidArgumentException`.

## Implementing a Custom Adapter

```php
use Razy\Cache\CacheInterface;
use DateInterval;

class MemcachedAdapter implements CacheInterface
{
    private \Memcached $mc;

    public function __construct(\Memcached $mc) { $this->mc = $mc; }

    public function get(string $key, mixed $default = null): mixed { /* ... */ }
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool { /* ... */ }
    public function delete(string $key): bool { /* ... */ }
    public function clear(): bool { /* ... */ }
    public function has(string $key): bool { /* ... */ }
    public function getMultiple(iterable $keys, mixed $default = null): iterable { /* ... */ }
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool { /* ... */ }
    public function deleteMultiple(iterable $keys): bool { /* ... */ }
}
```

## See Also
- [Razy.Cache.md](Razy.Cache.md) — Cache facade
- [Razy.Cache.FileAdapter.md](Razy.Cache.FileAdapter.md) — File-based adapter
- [Razy.Cache.ApcuAdapter.md](Razy.Cache.ApcuAdapter.md) — APCu adapter
