# Razy\ORM\ModelCollection

## Summary

Typed, iterable collection of Model instances with a rich functional API for transformation, filtering, aggregation, and serialisation.

## Namespace

`Razy\ORM`

## Implements

`ArrayAccess`, `Countable`, `IteratorAggregate`

## Constructor

```php
public function __construct(array $items = [])
```

## Access Methods

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `first` | `(): ?Model` | `?Model` | First item or null |
| `last` | `(): ?Model` | `?Model` | Last item or null |
| `isEmpty` | `(): bool` | `bool` | Has no items? |
| `isNotEmpty` | `(): bool` | `bool` | Has items? |
| `all` | `(): array` | `array<int, Model>` | Raw array of models |
| `count` | `(): int` | `int` | Number of items |

## Transformation Methods

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `toArray` | `(): array` | `array<int, array>` | All models → arrays (calls `Model::toArray()`) |
| `toJson` | `(int $options = 0): string` | `string` | JSON string of model arrays |
| `pluck` | `(string $attribute, ?string $keyBy = null): array` | `array` | Extract attribute values. Optional key-by column |
| `map` | `(callable $callback): array` | `array` | `fn(Model $m, int $i): mixed` |
| `filter` | `(callable $callback): static` | `static` | `fn(Model $m): bool` → new collection |
| `each` | `(callable $callback): static` | `static` | `fn(Model $m, int $i): void` — return `false` to break |
| `contains` | `(callable $callback): bool` | `bool` | `fn(Model $m): bool` — any match? |
| `firstWhere` | `(string $attribute, mixed $value): ?Model` | `?Model` | First model where attr loosely equals value |
| `flatMap` | `(callable $callback): array` | `array` | Map + flatten one level |
| `chunk` | `(int $size): array` | `array<int, static>` | Split into sized chunks |

## Aggregation Methods

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `reduce` | `(callable $callback, mixed $initial = null): mixed` | `mixed` | `fn(mixed $carry, Model $m): mixed` |
| `sum` | `(string\|callable $attribute): int\|float` | `int\|float` | Sum of attribute or callback values |
| `avg` | `(string\|callable $attribute): int\|float\|null` | `int\|float\|null` | Average. Null if empty |
| `min` | `(string\|callable $attribute): mixed` | `mixed` | Minimum. Null if empty |
| `max` | `(string\|callable $attribute): mixed` | `mixed` | Maximum. Null if empty |

## Sorting & Grouping

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `sortBy` | `(string\|callable $attribute, string $direction = 'asc'): static` | `static` | New sorted collection |
| `unique` | `(string\|callable $attribute): static` | `static` | Deduplicate by attribute/callback |
| `groupBy` | `(string\|callable $attribute): array` | `array<string\|int, static>` | Group into sub-collections |
| `keyBy` | `(string\|callable $attribute): array` | `array<string\|int, Model>` | Index by attribute/callback |

## ArrayAccess

| Method | Signature | Notes |
|--------|-----------|-------|
| `offsetExists` | `(mixed $offset): bool` | |
| `offsetGet` | `(mixed $offset): mixed` | |
| `offsetSet` | `(mixed $offset, mixed $value): void` | |
| `offsetUnset` | `(mixed $offset): void` | Re-indexes array |

## Usage Examples

```php
$users = User::query($db)->where('active=:a', ['a' => 1])->get();

// Pluck names
$names = $users->pluck('name');  // ['Alice', 'Bob', ...]

// Pluck with key
$map = $users->pluck('name', 'id');  // [1 => 'Alice', 2 => 'Bob']

// Filter
$admins = $users->filter(fn($u) => $u->role === 'admin');

// Aggregation
$totalScore = $users->sum('score');
$avgAge     = $users->avg('age');

// Sort
$sorted = $users->sortBy('name');

// Group
$byRole = $users->groupBy('role');  // ['admin' => ModelCollection, 'user' => ModelCollection]

// Chunk
$chunks = $users->chunk(10);  // [ModelCollection(10), ModelCollection(10), ...]

// Serialise
$array = $users->toArray();
$json  = $users->toJson();
```
