# Razy\Cache\FileAdapter

## Summary
- Default cache adapter for the Razy framework.
- Stores cache entries as serialized files with TTL metadata.
- Uses directory sharding (MD5 first 2 chars) for filesystem performance.
- Atomic writes via temp-file + rename pattern.
- Supports garbage collection and statistics.

## Construction
- `new FileAdapter(string $directory)` — Creates adapter with the specified cache directory.
- The directory is created recursively if it does not exist.
- Throws `InvalidArgumentException` if directory is not writable.

## Storage Format

```
{directory}/
    {xx}/                        ← Shard directory (first 2 hex chars of MD5 hash)
        {md5hash}.cache          ← Serialized: ['e' => expiry_timestamp, 'd' => data]
```

- Expiry `e = 0` means no expiry
- Expiry `e > 0` is the Unix timestamp when the entry expires

## Key Methods

### PSR-16 Interface

| Method | Return | Description |
|--------|--------|-------------|
| `get(string $key, mixed $default = null)` | `mixed` | Fetch value; lazy-purges expired entries |
| `set(string $key, mixed $value, null\|int\|DateInterval $ttl = null)` | `bool` | Store atomically (temp + rename) |
| `delete(string $key)` | `bool` | Remove cache file |
| `clear()` | `bool` | Recursively delete all cache files |
| `has(string $key)` | `bool` | Check existence; lazy-purges expired |
| `getMultiple(iterable $keys, mixed $default = null)` | `iterable` | Batch get |
| `setMultiple(iterable $values, null\|int\|DateInterval $ttl = null)` | `bool` | Batch set |
| `deleteMultiple(iterable $keys)` | `bool` | Batch delete |

### Extended Methods

| Method | Return | Description |
|--------|--------|-------------|
| `getStats()` | `array{directory: string, files: int, size: int}` | Cache directory stats |
| `gc()` | `int` | Garbage collect expired entries, returns count removed |

## Usage Example

```php
use Razy\Cache\FileAdapter;

$adapter = new FileAdapter('/path/to/cache');

// Basic operations
$adapter->set('user.data', ['name' => 'Ray'], 3600);
$data = $adapter->get('user.data');   // ['name' => 'Ray']
$adapter->has('user.data');           // true

// Statistics
$stats = $adapter->getStats();
// ['directory' => '/path/to/cache', 'files' => 42, 'size' => 128000]

// Garbage collection
$removed = $adapter->gc();  // 5 (removed 5 expired entries)
```

## Usage Notes
- Windows: Uses `unlink()` before `rename()` since Windows `rename()` fails if target exists
- Corrupted cache files are automatically detected and removed on read
- `clear()` preserves the root cache directory but removes all shard dirs and files
- `gc()` also removes empty shard directories after cleanup
- Key validation rejects empty keys and PSR-16 reserved characters: `{}()/\@:`

## See Also
- [Razy.Cache.md](Razy.Cache.md) — Cache facade
- [Razy.Cache.CacheInterface.md](Razy.Cache.CacheInterface.md) — Interface contract
