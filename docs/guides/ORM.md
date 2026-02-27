# ORM Guide

Complete guide to Razy's ORM (Object-Relational Mapping) system for working with database records as PHP objects.

---

## Table of Contents

1. [Overview](#overview)
2. [Defining Models](#defining-models)
3. [Querying with ModelQuery](#querying-with-modelquery)
4. [Where Clauses](#where-clauses)
5. [Ordering, Limiting & Selecting](#ordering-limiting--selecting)
6. [Terminal Methods](#terminal-methods)
7. [Pagination](#pagination)
8. [Chunked Processing & Cursor](#chunked-processing--cursor)
9. [Creating & Mutating Records](#creating--mutating-records)
10. [Relationships](#relationships)
11. [Eager Loading](#eager-loading)
12. [Scopes](#scopes)
13. [Soft Deletes](#soft-deletes)
14. [Model Events](#model-events)
15. [Serialization](#serialization)
16. [ModelCollection](#modelcollection)

---

## Overview

Razy's ORM provides an Active Record implementation built on top of the native `Database` and `Statement` APIs. Every database table maps to a `Model` subclass, and queries are built fluently through `ModelQuery` — which uses **Razy Simple Syntax** for WHERE clauses (not Laravel-style three-argument calls).

```php
use Razy\ORM\Model;
use Razy\ORM\ModelQuery;
use Razy\ORM\ModelCollection;

// Find a user by primary key
$user = User::find($db, 42);

// Fluent query
$admins = User::query($db)
    ->where('role=:role', ['role' => 'admin'])
    ->orderBy('name')
    ->get();
```

### Key Concepts

| Concept | Class | Purpose |
|---------|-------|---------|
| Model | `Razy\ORM\Model` | Abstract base — maps a table row to an object |
| Query Builder | `Razy\ORM\ModelQuery` | Fluent interface for building and executing queries |
| Collection | `Razy\ORM\ModelCollection` | Typed collection of Model instances with rich API |
| Paginator | `Razy\ORM\Paginator` | Pagination result with navigation helpers and URL generation |
| Soft Deletes | `Razy\ORM\SoftDeletes` | Trait for soft-delete support (`deleted_at` column) |
| Relations | `Razy\ORM\Relation\*` | HasOne, HasMany, BelongsTo, BelongsToMany |

---

## Defining Models

Extend `Razy\ORM\Model` and configure static properties:

```php
use Razy\ORM\Model;

class User extends Model
{
    protected static string $table      = 'users';       // Auto-derived if empty (class + 's')
    protected static string $primaryKey = 'id';          // Default 'id'
    protected static array  $fillable   = ['name', 'email', 'role'];
    protected static array  $guarded    = [];
    protected static array  $casts      = [
        'is_active' => 'bool',
        'settings'  => 'array',      // JSON decode/encode
        'created_at' => 'datetime',
    ];
    protected static bool   $timestamps = true;          // Auto-manage created_at/updated_at
    protected static array  $hidden     = ['password'];  // Hidden from toArray()/toJson()
    protected static array  $visible    = [];            // Whitelist (overrides $hidden)
}
```

### Table Name Resolution

If `$table` is empty, the ORM derives it by lowercasing the class name and appending `'s'`:
- `User` → `users`
- `Post` → `posts`

### Attribute Casting

| Cast Type | PHP Type | Notes |
|-----------|----------|-------|
| `'int'` | `int` | |
| `'float'` | `float` | |
| `'bool'` | `bool` | |
| `'string'` | `string` | |
| `'array'` / `'json'` | `array` | JSON decode on get, JSON encode on set |
| `'datetime'` | `\DateTimeImmutable` | Converts to/from datetime string |

### Accessors & Mutators

Define get/set transformations using `get{Name}Attribute` / `set{Name}Attribute` conventions:

```php
class User extends Model
{
    // Accessor: $user->full_name
    public function getFullNameAttribute(): string
    {
        return $this->attributes['first_name'] . ' ' . $this->attributes['last_name'];
    }

    // Mutator: $user->email = 'FOO@BAR.COM' → stored lowercase
    public function setEmailAttribute(string $value): void
    {
        $this->attributes['email'] = strtolower($value);
    }
}
```

---

## Querying with ModelQuery

Start a query via `Model::query($db)`:

```php
$query = User::query($db);  // Returns ModelQuery instance
```

All WHERE methods use **Razy Simple Syntax** — a single string expression with named parameter bindings:

```php
// Simple Syntax: 'column=:param'
$users = User::query($db)
    ->where('active=:active', ['active' => 1])
    ->get();
```

---

## Where Clauses

### Basic WHERE (AND)

```php
User::query($db)->where('role=:role', ['role' => 'admin'])->get();
User::query($db)->where('age>=:age', ['age' => 18])->get();

// Multiple AND conditions — chain where() calls
User::query($db)
    ->where('role=:role', ['role' => 'admin'])
    ->where('active=:active', ['active' => 1])
    ->get();
```

### OR WHERE

```php
User::query($db)
    ->where('role=:r1', ['r1' => 'admin'])
    ->orWhere('role=:r2', ['r2' => 'superadmin'])
    ->get();
// WHERE role = 'admin' OR role = 'superadmin'
```

### WHERE IN / NOT IN

```php
User::query($db)->whereIn('status', ['active', 'pending'])->get();
User::query($db)->whereNotIn('role', ['banned', 'suspended'])->get();
```

### WHERE BETWEEN / NOT BETWEEN

```php
Product::query($db)->whereBetween('price', 10, 100)->get();
Product::query($db)->whereNotBetween('score', 0, 10)->get();
```

### WHERE NULL / NOT NULL

```php
User::query($db)->whereNull('deleted_at')->get();
User::query($db)->whereNotNull('email_verified_at')->get();
```

### Method Signatures

| Method | Signature | Notes |
|--------|-----------|-------|
| `where` | `(string $syntax, array $params = []): static` | AND conjunction, Simple Syntax |
| `orWhere` | `(string $syntax, array $params = []): static` | OR conjunction, Simple Syntax |
| `whereIn` | `(string $column, array $values): static` | Column + value array |
| `whereNotIn` | `(string $column, array $values): static` | Column + value array |
| `whereBetween` | `(string $column, mixed $min, mixed $max): static` | Inclusive range |
| `whereNotBetween` | `(string $column, mixed $min, mixed $max): static` | Exclusive range |
| `whereNull` | `(string $column): static` | IS NULL |
| `whereNotNull` | `(string $column): static` | IS NOT NULL |

---

## Ordering, Limiting & Selecting

```php
User::query($db)
    ->select('id, name, email')      // Specific columns (default '*')
    ->orderBy('name')                 // ASC (default)
    ->orderBy('created_at', 'DESC')   // DESC
    ->limit(10)
    ->offset(20)
    ->get();
```

| Method | Signature |
|--------|-----------|
| `orderBy` | `(string $column, string $direction = 'ASC'): static` |
| `limit` | `(int $count): static` |
| `offset` | `(int $offset): static` |
| `select` | `(string $columns): static` |

---

## Terminal Methods

Terminal methods execute the query and return results:

```php
// Get all matching rows as ModelCollection
$users = User::query($db)->where('active=:a', ['a' => 1])->get();

// Get first matching row (or null)
$user = User::query($db)->where('email=:e', ['e' => 'foo@bar.com'])->first();

// Find by primary key
$user = User::query($db)->find(42);

// Count matching rows
$count = User::query($db)->where('role=:r', ['r' => 'admin'])->count();
```

| Method | Signature | Returns |
|--------|-----------|---------|
| `get` | `(): ModelCollection` | All matching models |
| `first` | `(): ?Model` | First model or null |
| `find` | `(int\|string $id): ?Model` | By primary key |
| `count` | `(): int` | Row count |

### Static Convenience Methods (on Model)

```php
$user  = User::find($db, 42);                   // Find by PK or null
$user  = User::findOrFail($db, 42);             // Find or throw ModelNotFoundException
$all   = User::all($db);                         // All rows as ModelCollection
$user  = User::create($db, ['name' => 'Alice']); // Insert and return
User::destroy($db, 1, 2, 3);                     // Delete multiple by PK
$user  = User::firstOrCreate($db, ['email' => 'a@b.com'], ['name' => 'Alice']);
$user  = User::firstOrNew($db, ['email' => 'a@b.com'], ['name' => 'Alice']);
$user  = User::updateOrCreate($db, ['email' => 'a@b.com'], ['name' => 'Updated']);
```

---

## Pagination

### Full Pagination (with COUNT)

```php
$page = User::query($db)
    ->where('active=:a', ['a' => 1])
    ->orderBy('name')
    ->paginate(2, 15);    // page 2, 15 per page

$page->items();        // ModelCollection of current page
$page->total();        // Total records (e.g. 105)
$page->currentPage();  // 2
$page->perPage();      // 15
$page->lastPage();     // 7
$page->count();        // Items on this page (≤ perPage)
```

### Simple Pagination (no COUNT — faster)

```php
$page = User::query($db)
    ->orderBy('created_at', 'DESC')
    ->simplePaginate(1, 25);    // page 1, 25 per page

$page->total();     // null (no count query executed)
$page->lastPage();  // null
```

### Navigation Helpers

```php
$page->hasPages();      // total > perPage?
$page->hasMorePages();  // currentPage < lastPage?
$page->onFirstPage();   // currentPage === 1?
$page->onLastPage();    // currentPage === lastPage?
```

### URL Generation

```php
$page->setPath('/users');
$page->appends(['sort' => 'name']);

$page->url(3);             // /users?page=3&sort=name
$page->firstPageUrl();     // /users?page=1&sort=name
$page->lastPageUrl();      // /users?page=7&sort=name
$page->previousPageUrl();  // /users?page=1&sort=name (null if page 1)
$page->nextPageUrl();      // /users?page=3&sort=name (null if last)
```

### Page Range (for pagination UI)

```php
$page->getPageRange(3);  // [1, 2, 3, 4, 5] (window around current page)
$page->links(3);         // {first, last, prev, next, pages: [{url, label, active}...]}
```

### Paginator Signatures

| Method | Signature |
|--------|-----------|
| `paginate` | `(int $page = 1, int $perPage = 15): Paginator` |
| `simplePaginate` | `(int $page = 1, int $perPage = 15): Paginator` |

---

## Chunked Processing & Cursor

### Chunked Processing

Process large result sets in fixed-size batches:

```php
User::query($db)->chunk(200, function (ModelCollection $users, int $page) {
    foreach ($users as $user) {
        // Process each user
    }
    // Return false to stop early
});
```

`chunk()` returns `bool` — `true` if all chunks processed, `false` if callback stopped early.

### Cursor (Generator)

Iterate one model at a time without loading everything into memory:

```php
foreach (User::query($db)->cursor() as $user) {
    // Process one user at a time — memory efficient
}
```

| Method | Signature | Returns |
|--------|-----------|---------|
| `chunk` | `(int $size, callable $callback): bool` | `true` if fully processed |
| `cursor` | `(): \Generator<int, Model>` | Yields models one at a time |

---

## Creating & Mutating Records

### Insert via Model

```php
$user = new User(['name' => 'Alice', 'email' => 'alice@example.com']);
$user->setDatabase($db);
$user->save();  // INSERT
```

### Insert via ModelQuery

```php
$user = User::query($db)->create(['name' => 'Alice', 'email' => 'alice@example.com']);
// Returns hydrated Model with auto-generated PK
```

### Update

```php
$user = User::find($db, 42);
$user->name = 'Bob';
$user->save();  // UPDATE (only dirty attributes)
```

### Bulk Update

```php
$affected = User::query($db)
    ->where('role=:r', ['r' => 'guest'])
    ->bulkUpdate(['role' => 'member']);
// Returns number of affected rows
```

### Delete

```php
$user->delete();  // Hard delete

// Bulk delete
$affected = User::query($db)
    ->where('active=:a', ['a' => 0])
    ->bulkDelete();
```

### Increment / Decrement

```php
$user->increment('login_count');
$user->decrement('credits', 5);
```

### Other Instance Methods

```php
$user->fill(['name' => 'New Name']);   // Mass-assign (respects fillable/guarded)
$user->refresh();                       // Reload from DB
$user->replicate();                     // Clone without PK/timestamps
$user->touch();                         // Update updated_at only

$user->isDirty();                       // Has unsaved changes?
$user->isDirty('name');                 // Specific attribute dirty?
$user->getDirty();                      // ['name' => 'New Name']
$user->getOriginal('name');             // Original value from DB
```

---

## Relationships

Define relationships as methods on your Model:

### HasOne

```php
class User extends Model
{
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
        // Assumes: profiles.user_id → users.id
    }
}

$profile = $user->profile;  // Returns ?Model
```

### HasMany

```php
class User extends Model
{
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
        // Assumes: posts.user_id → users.id
    }
}

$posts = $user->posts;  // Returns ModelCollection
```

### BelongsTo

```php
class Post extends Model
{
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

$author = $post->author;  // Returns ?Model
```

### BelongsToMany

```php
class User extends Model
{
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'user_roles',          // Pivot table
            'user_id',             // Foreign pivot key (this model)
            'role_id',             // Related pivot key
        );
    }
}

$roles = $user->roles;  // Returns ModelCollection
```

### Pivot Table Operations (BelongsToMany)

```php
$user->roles()->attach(3);                         // Add role ID 3
$user->roles()->attach([1, 2, 3]);                 // Add multiple
$user->roles()->attach([1 => ['assigned_by' => 'admin']]);  // With extra pivot data

$user->roles()->detach(3);                         // Remove role ID 3
$user->roles()->detach([1, 2]);                    // Remove multiple
$user->roles()->detach();                          // Remove all

$result = $user->roles()->sync([1, 2, 5]);         // Sync exactly these IDs
// Returns: ['attached' => [5], 'detached' => [3]]
```

### Convention Defaults

| Relation | Foreign Key | Local Key |
|----------|-------------|-----------|
| `hasOne` | `{model}_id` on related | `id` on parent |
| `hasMany` | `{model}_id` on related | `id` on parent |
| `belongsTo` | `{related}_id` on this model | `id` on related |
| `belongsToMany` | Configured via pivot table params | |

---

## Eager Loading

Prevent N+1 queries by eager-loading relations:

```php
$users = User::query($db)
    ->with('posts', 'profile')
    ->get();

// Only 3 queries executed:
// 1. SELECT * FROM users
// 2. SELECT * FROM posts WHERE user_id IN (1, 2, 3, ...)
// 3. SELECT * FROM profiles WHERE user_id IN (1, 2, 3, ...)

foreach ($users as $user) {
    $user->posts;    // Already loaded — no additional query
    $user->profile;  // Already loaded — no additional query
}
```

---

## Scopes

### Local Scopes

Define reusable query constraints as `scope{Name}` methods:

```php
class User extends Model
{
    public function scopeActive(ModelQuery $query): ModelQuery
    {
        return $query->where('active=:_s_active', ['_s_active' => 1]);
    }

    public function scopeRole(ModelQuery $query, string $role): ModelQuery
    {
        return $query->where('role=:_s_role', ['_s_role' => $role]);
    }
}

// Usage — called as method on ModelQuery:
User::query($db)->active()->get();
User::query($db)->active()->role('admin')->get();
```

### Global Scopes

Automatically applied to every query (register in `boot()`):

```php
class User extends Model
{
    protected static function boot(): void
    {
        static::addGlobalScope('active', function (ModelQuery $query) {
            $query->where('active=:_gs_active', ['_gs_active' => 1]);
        });
    }
}

// All queries now include WHERE active = 1 automatically
User::query($db)->get();

// Exclude global scopes when needed:
User::query($db)->withoutGlobalScope('active')->get();
User::query($db)->withoutGlobalScopes()->get();  // Exclude ALL
```

| Method | Signature |
|--------|-----------|
| `withoutGlobalScope` | `(string ...$names): static` |
| `withoutGlobalScopes` | `(): static` |

---

## Soft Deletes

Use the `SoftDeletes` trait to mark records as deleted without removing them:

```php
use Razy\ORM\SoftDeletes;

class Post extends Model
{
    use SoftDeletes;

    // Optionally override the column name:
    // public static function getDeletedAtColumn(): string { return 'removed_at'; }
}
```

### Usage

```php
$post->delete();       // Sets deleted_at = NOW (soft delete)
$post->trashed();      // true
$post->restore();      // Clears deleted_at
$post->forceDelete();  // Hard delete from database

// Global scope automatically excludes soft-deleted records
Post::query($db)->get();  // Only non-deleted posts

// Include soft-deleted
Post::query($db)->withTrashed()->get();

// Only soft-deleted
Post::query($db)->onlyTrashed()->get();
```

---

## Model Events

Register lifecycle callbacks:

```php
class User extends Model
{
    protected static function boot(): void
    {
        static::creating(function (Model $user) {
            // Before INSERT — return false to cancel
            $user->uuid = generateUUID();
        });

        static::created(function (Model $user) {
            // After INSERT
        });

        static::updating(function (Model $user) {
            // Before UPDATE — return false to cancel
        });

        static::deleting(function (Model $user) {
            // Before DELETE — return false to cancel
        });
    }
}
```

### Available Events

| Event | Timing | Can Cancel? |
|-------|--------|-------------|
| `creating` / `created` | INSERT | Yes (`creating`) |
| `updating` / `updated` | UPDATE | Yes (`updating`) |
| `saving` / `saved` | INSERT or UPDATE | Yes (`saving`) |
| `deleting` / `deleted` | DELETE | Yes (`deleting`) |
| `restoring` / `restored` | Soft-delete restore | Yes (`restoring`) |

---

## Serialization

```php
$user->toArray();   // Array with casts/accessors applied, respects $hidden/$visible
$user->toJson();    // JSON string

// Collections
$users->toArray();  // Array of arrays
$users->toJson();   // JSON array

// Paginator serializes as:
// { data: [...], total: 105, page: 2, per_page: 15, last_page: 7, from: 16, to: 30, links: {...} }
$page->toArray();
$page->toJson();
json_encode($page);  // Implements JsonSerializable
```

---

## ModelCollection

`ModelCollection` is a typed, iterable collection of Model instances with a rich functional API.

### Access

```php
$users->first();       // ?Model
$users->last();        // ?Model
$users->isEmpty();     // bool
$users->isNotEmpty();  // bool
$users->all();         // array of models
$users->count();       // int
```

### Transformation

```php
$users->pluck('name');                    // ['Alice', 'Bob', ...]
$users->pluck('name', 'id');             // [1 => 'Alice', 2 => 'Bob']
$users->map(fn($u) => $u->name);         // ['Alice', 'Bob']
$users->filter(fn($u) => $u->active);    // New collection
$users->each(fn($u) => doSomething($u)); // Iterate (return false to break)
$users->contains(fn($u) => $u->id === 1); // bool
$users->firstWhere('role', 'admin');      // First admin or null
$users->flatMap(fn($u) => $u->posts->all()); // Map + flatten
$users->chunk(10);                        // Split into chunks
```

### Aggregation

```php
$users->sum('score');
$users->avg('age');
$users->min('created_at');
$users->max('login_count');
$users->reduce(fn($carry, $u) => $carry + $u->score, 0);
```

### Sorting & Grouping

```php
$users->sortBy('name');                   // New sorted collection
$users->sortBy('age', 'desc');
$users->unique('email');                  // Deduplicate
$users->groupBy('role');                  // ['admin' => ModelCollection, ...]
$users->keyBy('id');                      // [1 => Model, 2 => Model, ...]
```

---

## Complete API Summary

### ModelQuery Methods

| Method | Signature | Description |
|--------|-----------|-------------|
| `where` | `(string $syntax, array $params = []): static` | AND condition (Simple Syntax) |
| `orWhere` | `(string $syntax, array $params = []): static` | OR condition (Simple Syntax) |
| `whereIn` | `(string $col, array $vals): static` | IN clause |
| `whereNotIn` | `(string $col, array $vals): static` | NOT IN clause |
| `whereBetween` | `(string $col, mixed $min, mixed $max): static` | BETWEEN |
| `whereNotBetween` | `(string $col, mixed $min, mixed $max): static` | NOT BETWEEN |
| `whereNull` | `(string $col): static` | IS NULL |
| `whereNotNull` | `(string $col): static` | IS NOT NULL |
| `orderBy` | `(string $col, string $dir = 'ASC'): static` | Sort |
| `limit` | `(int $n): static` | Limit rows |
| `offset` | `(int $n): static` | Skip rows |
| `select` | `(string $columns): static` | Column selection |
| `with` | `(string ...$relations): static` | Eager load |
| `withoutGlobalScope` | `(string ...$names): static` | Remove named scope(s) |
| `withoutGlobalScopes` | `(): static` | Remove all scopes |
| `get` | `(): ModelCollection` | Execute query |
| `first` | `(): ?Model` | First result |
| `find` | `(int\|string $id): ?Model` | By PK |
| `count` | `(): int` | Count rows |
| `paginate` | `(int $page = 1, int $perPage = 15): Paginator` | Full pagination |
| `simplePaginate` | `(int $page = 1, int $perPage = 15): Paginator` | No total count |
| `chunk` | `(int $size, callable $callback): bool` | Batch processing |
| `cursor` | `(): Generator` | Lazy iteration |
| `create` | `(array $attributes): Model` | Insert row, return Model |
| `bulkUpdate` | `(array $values): int` | Mass UPDATE |
| `bulkDelete` | `(): int` | Mass DELETE |

### Model Static Methods

| Method | Signature | Returns |
|--------|-----------|---------|
| `query` | `(Database $db): ModelQuery` | New query builder |
| `find` | `(Database $db, int\|string $id): ?static` | Find by PK |
| `findOrFail` | `(Database $db, int\|string $id): static` | Find or throw |
| `all` | `(Database $db): ModelCollection` | All rows |
| `create` | `(Database $db, array $attrs): static` | Insert & return |
| `destroy` | `(Database $db, int\|string ...$ids): int` | Delete by PKs |
| `firstOrCreate` | `(Database $db, array $search, array $extra = []): static` | Find or create |
| `firstOrNew` | `(Database $db, array $search, array $extra = []): static` | Find or new (unsaved) |
| `updateOrCreate` | `(Database $db, array $search, array $update): static` | Upsert |

### Model Instance Methods

| Method | Signature | Returns |
|--------|-----------|---------|
| `fill` | `(array $attributes): static` | Mass-assign |
| `save` | `(): bool` | INSERT or UPDATE |
| `delete` | `(): bool` | DELETE |
| `refresh` | `(): static` | Reload from DB |
| `isDirty` | `(?string $attr = null): bool` | Has changes? |
| `getDirty` | `(): array` | Changed attributes |
| `getOriginal` | `(?string $attr = null): mixed` | Original values |
| `increment` | `(string $col, int\|float $amt = 1, array $extra = []): bool` | Atomic increment |
| `decrement` | `(string $col, int\|float $amt = 1, array $extra = []): bool` | Atomic decrement |
| `replicate` | `(array $except = []): static` | Clone without PK |
| `touch` | `(): bool` | Update updated_at |
| `toArray` | `(): array` | Serialise (respects hidden) |
| `toJson` | `(int $options = 0): string` | JSON output |
| `getKey` | `(): mixed` | PK value |
| `exists` | `(): bool` | Is persisted? |
