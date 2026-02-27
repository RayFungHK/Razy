# Razy\ORM\Model

## Summary

Abstract base class for ORM models. Each subclass maps to a database table and provides Active Record features: attribute access with casting, mass assignment protection, dirty tracking, lifecycle events, relationships, and scopes.

## Namespace

`Razy\ORM`

## Configuration Properties

Override these `protected static` properties in subclasses:

| Property | Type | Default | Purpose |
|----------|------|---------|---------|
| `$table` | `string` | `''` | Table name. Auto-derived as `lcfirst(ClassName) . 's'` if empty |
| `$primaryKey` | `string` | `'id'` | Primary key column |
| `$fillable` | `array` | `[]` | Mass-assignable columns |
| `$guarded` | `array` | `['*']` | Non-mass-assignable columns |
| `$casts` | `array` | `[]` | Attribute casting map (`'int'`, `'float'`, `'bool'`, `'string'`, `'array'`/`'json'`, `'datetime'`) |
| `$timestamps` | `bool` | `true` | Auto-manage `created_at` / `updated_at` |
| `$hidden` | `array` | `[]` | Attributes hidden from `toArray()`/`toJson()` |
| `$visible` | `array` | `[]` | Whitelist for serialisation (overrides `$hidden`) |

## Constructor

```php
public function __construct(array $attributes = [])
```

## Static Methods

### Query & Find

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `query` | `(Database $db): ModelQuery` | `ModelQuery` | Create fluent query builder. Triggers `boot()` on first call |
| `find` | `(Database $db, int\|string $id): ?static` | `?static` | Find by primary key or null |
| `findOrFail` | `(Database $db, int\|string $id): static` | `static` | Find or throw `ModelNotFoundException` |
| `all` | `(Database $db): ModelCollection` | `ModelCollection` | Retrieve all rows |
| `newFromRow` | `(array $row, Database $db): static` | `static` | Hydrate from DB row, marks `exists = true` |

### Create & Destroy

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `create` | `(Database $db, array $attributes): static` | `static` | Insert and return hydrated model |
| `destroy` | `(Database $db, int\|string ...$ids): int` | `int` | Delete by PKs, returns count |
| `firstOrCreate` | `(Database $db, array $search, array $extra = []): static` | `static` | Find first match or create |
| `firstOrNew` | `(Database $db, array $search, array $extra = []): static` | `static` | Find first match or new (unsaved) |
| `updateOrCreate` | `(Database $db, array $search, array $update): static` | `static` | Upsert |

### Scopes

| Method | Signature | Description |
|--------|-----------|-------------|
| `addGlobalScope` | `(string $name, \Closure $scope): void` | Register a global scope |
| `removeGlobalScope` | `(string $name): void` | Remove a global scope |
| `getGlobalScopes` | `(): array` | Get all registered scopes |
| `clearBootedModels` | `(): void` | Reset boot state, scopes, events (testing) |

### Events

| Method | Signature | Description |
|--------|-----------|-------------|
| `creating` / `created` | `(\Closure $callback): void` | Before/after INSERT |
| `updating` / `updated` | `(\Closure $callback): void` | Before/after UPDATE |
| `saving` / `saved` | `(\Closure $callback): void` | Before/after INSERT or UPDATE |
| `deleting` / `deleted` | `(\Closure $callback): void` | Before/after DELETE |
| `restoring` / `restored` | `(\Closure $callback): void` | Before/after soft-delete restore |

### Metadata

| Method | Signature | Returns |
|--------|-----------|---------|
| `resolveTable` | `(): string` | Table name |
| `getPrimaryKeyName` | `(): string` | Primary key column |
| `getHidden` | `(): array` | Hidden attributes list |
| `getVisible` | `(): array` | Visible attributes list |

## Instance Methods

### Persistence

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `save` | `(): bool` | `bool` | INSERT (new) or UPDATE (existing, only dirty) |
| `delete` | `(): bool` | `bool` | Hard delete |
| `refresh` | `(): static` | `static` | Reload from DB |
| `touch` | `(): bool` | `bool` | Update `updated_at` only |
| `increment` | `(string $col, int\|float $amt = 1, array $extra = []): bool` | `bool` | Atomic increment |
| `decrement` | `(string $col, int\|float $amt = 1, array $extra = []): bool` | `bool` | Atomic decrement |

### Attributes

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `fill` | `(array $attributes): static` | `static` | Mass-assign respecting fillable/guarded |
| `isFillable` | `(string $column): bool` | `bool` | Check if column is fillable |
| `getRawAttribute` | `(string $name): mixed` | `mixed` | Get without casting |
| `setRawAttribute` | `(string $name, mixed $value): static` | `static` | Set without casting |
| `isDirty` | `(?string $attribute = null): bool` | `bool` | Has unsaved changes (optionally for specific attr) |
| `getDirty` | `(): array` | `array` | All changed attributes |
| `getOriginal` | `(?string $attribute = null): mixed` | `mixed` | Original value(s) from DB |
| `replicate` | `(array $except = []): static` | `static` | Clone without PK/timestamps |

### Serialisation

| Method | Signature | Returns |
|--------|-----------|---------|
| `toArray` | `(): array` | Applies casts/accessors, respects hidden/visible |
| `toJson` | `(int $options = 0): string` | JSON string |

### Identity & State

| Method | Signature | Returns |
|--------|-----------|---------|
| `getKey` | `(): mixed` | Primary key value |
| `exists` | `(): bool` | Is persisted to DB? |
| `getDatabase` | `(): ?Database` | Associated connection |
| `setDatabase` | `(Database $db): static` | Set connection |

### Relations

| Method | Signature | Returns |
|--------|-----------|---------|
| `setRelation` | `(string $name, Model\|ModelCollection\|null $value): static` | Pre-set relation cache |
| `relationLoaded` | `(string $name): bool` | Is relation cached? |
| `getRelationInstance` | `(string $name): ?Relation` | Get Relation without resolving |

### Protected Relationship Definitions

| Method | Signature | Returns |
|--------|-----------|---------|
| `hasOne` | `(string $related, ?string $foreignKey = null, ?string $localKey = null): HasOne` | `HasOne` |
| `hasMany` | `(string $related, ?string $foreignKey = null, ?string $localKey = null): HasMany` | `HasMany` |
| `belongsTo` | `(string $related, ?string $foreignKey = null, ?string $ownerKey = null): BelongsTo` | `BelongsTo` |
| `belongsToMany` | `(string $related, ?string $pivotTable = null, ?string $foreignPivotKey = null, ?string $relatedPivotKey = null, ?string $parentKey = null, ?string $relatedKey = null): BelongsToMany` | `BelongsToMany` |

## Magic Methods

- `__get($name)` — Accessor/cast/relation resolution
- `__set($name, $value)` — Mutator support
- `__isset($name)` — Attribute/relation existence
- `__unset($name)` — Remove attribute

## Example

```php
use Razy\ORM\Model;

class User extends Model
{
    protected static string $table    = 'users';
    protected static array  $fillable = ['name', 'email', 'role'];
    protected static array  $casts    = ['is_active' => 'bool'];
    protected static array  $hidden   = ['password'];

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function scopeActive(ModelQuery $query): ModelQuery
    {
        return $query->where('active=:_s', ['_s' => 1]);
    }
}

// Usage
$user = User::find($db, 42);
$user->name = 'Updated';
$user->save();

$admins = User::query($db)->where('role=:r', ['r' => 'admin'])->active()->get();
```
