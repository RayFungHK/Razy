# Razy\ORM\ModelQuery

## Summary

Fluent query builder for ORM models. Wraps Razy's native Database/Statement API using **Simple Syntax** for WHERE clauses, and hydrates results into Model instances or ModelCollection objects.

## Namespace

`Razy\ORM`

## Construction

```php
// Always created via Model::query()
$query = User::query($db);  // Returns ModelQuery
```

## Constructor

```php
public function __construct(Database $database, string $modelClass)
```

## Constraint Methods

All WHERE methods use Razy Simple Syntax — a string expression with named parameter bindings.

| Method | Signature | Description |
|--------|-----------|-------------|
| `where` | `(string $syntax, array $params = []): static` | AND condition. Example: `'name=:name'` |
| `orWhere` | `(string $syntax, array $params = []): static` | OR condition |
| `whereIn` | `(string $column, array $values): static` | WHERE column IN (...) |
| `whereNotIn` | `(string $column, array $values): static` | WHERE column NOT IN (...) |
| `whereBetween` | `(string $column, mixed $min, mixed $max): static` | WHERE column BETWEEN min AND max |
| `whereNotBetween` | `(string $column, mixed $min, mixed $max): static` | WHERE column NOT BETWEEN min AND max |
| `whereNull` | `(string $column): static` | WHERE column IS NULL |
| `whereNotNull` | `(string $column): static` | WHERE column IS NOT NULL |

### Simple Syntax Mapping

| Convenience Method | Internal Simple Syntax |
|--------------------|------------------------|
| `whereIn('col', $vals)` | `col\|=:param` with array binding |
| `whereNotIn('col', $vals)` | `!col\|=:param` with array binding |
| `whereBetween('col', $min, $max)` | `col><:param` with `[$min, $max]` |
| `whereNotBetween('col', $min, $max)` | `!col><:param` with `[$min, $max]` |
| `whereNull('col')` | `col=:param` with `null` binding |
| `whereNotNull('col')` | `!col=:param` with `null` binding |

## Ordering, Limiting & Selection

| Method | Signature | Description |
|--------|-----------|-------------|
| `orderBy` | `(string $column, string $direction = 'ASC'): static` | Sort results. `'ASC'` or `'DESC'` |
| `limit` | `(int $count): static` | Set result limit |
| `offset` | `(int $offset): static` | Set result offset |
| `select` | `(string $columns): static` | Column selection (default `'*'`) |

## Eager Loading & Scopes

| Method | Signature | Description |
|--------|-----------|-------------|
| `with` | `(string ...$relations): static` | Eager-load named relations (prevents N+1) |
| `withoutGlobalScope` | `(string ...$names): static` | Exclude specific global scope(s) |
| `withoutGlobalScopes` | `(): static` | Exclude ALL global scopes |

## Terminal Methods (Read)

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `get` | `(): ModelCollection` | `ModelCollection` | Execute and return all matches |
| `first` | `(): ?Model` | `?Model` | First match (auto-limits to 1) |
| `find` | `(int\|string $id): ?Model` | `?Model` | Find by primary key |
| `count` | `(): int` | `int` | COUNT(*) query |
| `paginate` | `(int $page = 1, int $perPage = 15): Paginator` | `Paginator` | Full pagination with total count |
| `simplePaginate` | `(int $page = 1, int $perPage = 15): Paginator` | `Paginator` | Efficient pagination (no COUNT query) |
| `chunk` | `(int $size, callable $callback): bool` | `bool` | Process in batches. Callback: `fn(ModelCollection, int $page)`. Return `false` to stop. Returns `true` if all processed |
| `cursor` | `(): \Generator<int, Model>` | `Generator` | Yield one model at a time (memory efficient) |

## Terminal Methods (Write)

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `create` | `(array $attributes): Model` | `Model` | INSERT row and return hydrated Model with auto PK |
| `bulkUpdate` | `(array $attributes): int` | `int` | UPDATE all matching rows. Returns affected count |
| `bulkDelete` | `(): int` | `int` | DELETE all matching rows. Returns affected count |

## Magic Method

```php
public function __call(string $method, array $parameters): mixed
```

Forwards to `scope{Method}()` on the model class. Enables local scope chaining:

```php
// If User has scopeActive(ModelQuery $query):
User::query($db)->active()->get();
```

Throws `\BadMethodCallException` if no matching scope exists.

## Usage Examples

### Basic Query

```php
$users = User::query($db)
    ->where('active=:a', ['a' => 1])
    ->orderBy('name')
    ->limit(10)
    ->get();
```

### Complex WHERE

```php
$users = User::query($db)
    ->where('role=:r', ['r' => 'admin'])
    ->orWhere('is_super=:s', ['s' => 1])
    ->whereNotNull('email_verified_at')
    ->get();
```

### Pagination

```php
$page = User::query($db)
    ->where('active=:a', ['a' => 1])
    ->orderBy('name')
    ->paginate(2, 15);  // page 2, 15 per page
```

### Chunked Processing

```php
User::query($db)->chunk(200, function (ModelCollection $users, int $page) {
    foreach ($users as $user) {
        // process
    }
});
```

### Eager Loading

```php
$users = User::query($db)
    ->with('posts', 'profile')
    ->where('active=:a', ['a' => 1])
    ->get();
```

### Bulk Operations

```php
// Update all matching
$affected = User::query($db)
    ->where('role=:r', ['r' => 'guest'])
    ->bulkUpdate(['role' => 'member']);

// Delete all matching
$deleted = User::query($db)
    ->where('active=:a', ['a' => 0])
    ->bulkDelete();
```

### Insert via Query Builder

```php
$user = User::query($db)->create([
    'name'  => 'Alice',
    'email' => 'alice@example.com',
    'role'  => 'member',
]);
// Returns hydrated User with auto-generated PK
```
