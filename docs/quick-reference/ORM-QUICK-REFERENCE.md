# ORM Quick Reference

Fast lookup card for Razy's ORM — Model, ModelQuery, ModelCollection, Paginator.

---

## Model Configuration

```php
class User extends Model
{
    protected static string $table      = 'users';
    protected static string $primaryKey = 'id';
    protected static array  $fillable   = ['name', 'email'];
    protected static array  $guarded    = [];
    protected static array  $casts      = ['is_active' => 'bool', 'settings' => 'array'];
    protected static bool   $timestamps = true;
    protected static array  $hidden     = ['password'];
}
```

---

## Static Finders

| Call | Returns |
|------|---------|
| `User::find($db, 42)` | `?User` |
| `User::findOrFail($db, 42)` | `User` (throws if not found) |
| `User::all($db)` | `ModelCollection` |
| `User::create($db, [...])` | `User` (inserted) |
| `User::destroy($db, 1, 2, 3)` | `int` (deleted count) |
| `User::firstOrCreate($db, $search, $extra)` | `User` |
| `User::updateOrCreate($db, $search, $update)` | `User` |

---

## ModelQuery — Where Clauses

All WHERE methods use **Razy Simple Syntax**.

| Method | Example |
|--------|---------|
| `where` | `->where('role=:r', ['r' => 'admin'])` |
| `orWhere` | `->orWhere('role=:r', ['r' => 'super'])` |
| `whereIn` | `->whereIn('status', ['active', 'pending'])` |
| `whereNotIn` | `->whereNotIn('role', ['banned'])` |
| `whereBetween` | `->whereBetween('age', 18, 65)` |
| `whereNotBetween` | `->whereNotBetween('score', 0, 10)` |
| `whereNull` | `->whereNull('deleted_at')` |
| `whereNotNull` | `->whereNotNull('email_verified_at')` |

---

## ModelQuery — Terminal Methods

| Method | Returns | Notes |
|--------|---------|-------|
| `->get()` | `ModelCollection` | All matches |
| `->first()` | `?Model` | First match |
| `->find($id)` | `?Model` | By primary key |
| `->count()` | `int` | Row count |
| `->paginate($page, $perPage)` | `Paginator` | With total count |
| `->simplePaginate($page, $perPage)` | `Paginator` | No COUNT query |
| `->chunk($size, $callback)` | `bool` | Batched processing |
| `->cursor()` | `Generator` | One-at-a-time iteration |

---

## ModelQuery — Mutations

| Method | Returns | Notes |
|--------|---------|-------|
| `->create([...])` | `Model` | INSERT + hydrate |
| `->bulkUpdate([...])` | `int` | Mass UPDATE |
| `->bulkDelete()` | `int` | Mass DELETE |

---

## ModelQuery — Modifiers

| Method | Example |
|--------|---------|
| `orderBy` | `->orderBy('name')` / `->orderBy('age', 'DESC')` |
| `limit` | `->limit(10)` |
| `offset` | `->offset(20)` |
| `select` | `->select('id, name, email')` |
| `with` | `->with('posts', 'profile')` |
| `withoutGlobalScope` | `->withoutGlobalScope('active')` |
| `withoutGlobalScopes` | `->withoutGlobalScopes()` |

---

## Instance CRUD

```php
$user->save();                           // INSERT or UPDATE
$user->delete();                         // DELETE
$user->fill(['name' => 'New']);          // Mass-assign
$user->refresh();                        // Reload from DB
$user->increment('logins');              // Atomic +1
$user->decrement('credits', 5);          // Atomic -5
$user->replicate();                      // Clone (no PK)
$user->touch();                          // Update updated_at
```

---

## Dirty Checking

```php
$user->isDirty();            // Any changes?
$user->isDirty('name');      // Specific attr?
$user->getDirty();           // ['name' => 'new']
$user->getOriginal('name');  // 'old'
```

---

## Relationships

```php
// Define in Model:
$this->hasOne(Profile::class);
$this->hasMany(Post::class);
$this->belongsTo(User::class, 'user_id');
$this->belongsToMany(Role::class, 'user_roles', 'user_id', 'role_id');

// Access:
$user->profile;   // ?Model  (HasOne)
$user->posts;     // ModelCollection (HasMany)
$post->author;    // ?Model  (BelongsTo)
$user->roles;     // ModelCollection (BelongsToMany)

// Pivot operations (BelongsToMany):
$user->roles()->attach([1, 2]);
$user->roles()->detach(3);
$user->roles()->sync([1, 2, 5]);
```

---

## Eager Loading

```php
User::query($db)->with('posts', 'profile')->get();
// 3 queries instead of N+1
```

---

## Scopes

```php
// Local: define scopeXyz on Model → call as ->xyz()
User::query($db)->active()->get();

// Global: addGlobalScope in boot() → auto-applied
User::query($db)->withoutGlobalScope('active')->get();
```

---

## Soft Deletes

```php
use Razy\ORM\SoftDeletes;  // Trait

$post->delete();       // Soft delete
$post->restore();      // Restore
$post->forceDelete();  // Hard delete
$post->trashed();      // Is soft-deleted?

Post::query($db)->withTrashed()->get();
Post::query($db)->onlyTrashed()->get();
```

---

## Paginator

```php
$page->items();          // ModelCollection
$page->total();          // ?int (null for simplePaginate)
$page->currentPage();    // int
$page->perPage();        // int
$page->lastPage();       // ?int
$page->hasMorePages();   // bool
$page->onFirstPage();    // bool
$page->onLastPage();     // bool

// URL generation
$page->setPath('/users');
$page->url(3);
$page->nextPageUrl();
$page->previousPageUrl();
$page->links(3);         // Full nav structure
```

---

## ModelCollection

```php
$c->first() / $c->last()               // ?Model
$c->isEmpty() / $c->isNotEmpty()        // bool
$c->count()                             // int
$c->all()                               // array
$c->pluck('name')                       // ['Alice', 'Bob']
$c->map(fn($m) => $m->name)            // array
$c->filter(fn($m) => $m->active)       // new collection
$c->sum('score') / $c->avg('age')      // numeric
$c->sortBy('name') / $c->unique('email')
$c->groupBy('role') / $c->keyBy('id')
$c->toArray() / $c->toJson()
```

---

## Serialization

```php
$user->toArray();   // Respects $hidden/$visible, applies casts/accessors
$user->toJson();
$page->toArray();   // {data, total, page, per_page, last_page, from, to, links}
json_encode($page); // JsonSerializable
```

---

## Events

```php
// In boot(): creating, created, updating, updated, saving, saved, deleting, deleted, restoring, restored
static::creating(fn(Model $m) => /* return false to cancel */);
```

---

## Cast Types

| Cast | PHP Type |
|------|----------|
| `'int'` | `int` |
| `'float'` | `float` |
| `'bool'` | `bool` |
| `'string'` | `string` |
| `'array'` / `'json'` | `array` (JSON) |
| `'datetime'` | `DateTimeImmutable` |
