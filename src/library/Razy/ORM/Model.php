<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 *
 * @license MIT
 */

namespace Razy\ORM;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use Razy\Database;
use Razy\Exception\ModelNotFoundException;
use Razy\ORM\Relation\BelongsTo;
use Razy\ORM\Relation\BelongsToMany;
use Razy\ORM\Relation\HasMany;
use Razy\ORM\Relation\HasOne;
use Razy\ORM\Relation\Relation;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Abstract Active Record base class.
 *
 * Extend this class and configure the static properties to map your database
 * table to an object.
 *
 * ```php
 * class User extends Model
 * {
 *     protected static string $table = 'users';
 *     protected static array $fillable = ['name', 'email'];
 * }
 *
 * // Create
 * $user = User::create($db, ['name' => 'Ray', 'email' => 'ray@example.com']);
 *
 * // Read
 * $user = User::find($db, 1);
 * $users = User::all($db);
 *
 * // Update
 * $user->name = 'Updated';
 * $user->save();
 *
 * // Delete
 * $user->delete();
 * ```
 */
abstract class Model
{
    // -----------------------------------------------------------------------
    //  Table / schema configuration — override in subclasses
    // -----------------------------------------------------------------------

    /**
     * The table name. When empty, it is auto-derived from the short class name
     * by lowercasing and appending 's' (e.g. User → users).
     */
    protected static string $table = '';

    /**
     * The primary key column name.
     */
    protected static string $primaryKey = 'id';

    /**
     * Columns that are mass-assignable via `fill()` / `create()`.
     * An empty array means *nothing* is fillable unless `$guarded` is empty.
     *
     * @var list<string>
     */
    protected static array $fillable = [];

    /**
     * Columns that are NOT mass-assignable. `['*']` guards everything.
     *
     * @var list<string>
     */
    protected static array $guarded = ['*'];

    /**
     * Attribute casting definitions.
     *
     * Supported types: `int`, `float`, `bool`, `string`, `array`/`json`, `datetime`.
     *
     * @var array<string, string>
     */
    protected static array $casts = [];

    /**
     * Whether the model should manage `created_at` / `updated_at` columns.
     */
    protected static bool $timestamps = true;

    /**
     * Attributes that should be hidden from serialisation (`toArray` / `toJson`).
     *
     * @var list<string>
     */
    protected static array $hidden = [];

    /**
     * If set, only these attributes will appear in serialisation output.
     * Takes precedence over `$hidden` when non-empty.
     *
     * @var list<string>
     */
    protected static array $visible = [];

    // -----------------------------------------------------------------------
    //  Boot & Global Scopes (class-level, stored per concrete class)
    // -----------------------------------------------------------------------

    /**
     * Registry of global scopes keyed by [className][scopeName].
     *
     * Global scopes are automatically applied to every SELECT query built
     * through `ModelQuery`.  They can be removed per-query with
     * `withoutGlobalScope()` / `withoutGlobalScopes()`.
     *
     * @var array<class-string, array<string, Closure>>
     */
    private static array $globalScopes = [];

    /**
     * Tracks which concrete model classes have been booted.
     *
     * @var array<class-string, true>
     */
    private static array $booted = [];

    /**
     * Registered model event listeners keyed by [className][eventName].
     *
     * Supported events:
     * - `creating` / `created`   — INSERT operations
     * - `updating` / `updated`   — UPDATE operations
     * - `saving`   / `saved`     — any persistence (INSERT or UPDATE)
     * - `deleting`  / `deleted`  — DELETE (soft or hard)
     * - `restoring` / `restored` — SoftDeletes restore
     *
     * "Before" events (`creating`, `updating`, `saving`, `deleting`,
     * `restoring`) can return `false` from any listener to cancel the
     * operation.
     *
     * @var array<class-string, array<string, list<Closure>>>
     */
    private static array $events = [];

    // -----------------------------------------------------------------------
    //  Instance state
    // -----------------------------------------------------------------------

    /**
     * The current attribute values.
     *
     * @var array<string, mixed>
     */
    protected array $attributes = [];

    /**
     * The original attribute values as loaded from the database.
     *
     * @var array<string, mixed>
     */
    protected array $original = [];

    /**
     * Whether this model has been persisted to the database.
     */
    protected bool $exists = false;

    /**
     * Database connection associated with this model instance.
     */
    protected ?Database $database = null;

    /**
     * Resolved relationship cache (lazy-loaded).
     *
     * @var array<string, Model|ModelCollection|null>
     */
    private array $relationCache = [];

    // -----------------------------------------------------------------------
    //  Construction
    // -----------------------------------------------------------------------

    /**
     * Create a new model instance (not yet persisted).
     */
    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    // -----------------------------------------------------------------------
    //  Attribute access
    // -----------------------------------------------------------------------

    /**
     * Get an attribute value, with accessor and casting applied.
     *
     * Resolution order:
     * 1. Relationship method (e.g. `posts()` → resolves relation)
     * 2. Accessor method `get{StudlyName}Attribute($rawValue)`
     * 3. Cast via `$casts` configuration
     */
    public function __get(string $name): mixed
    {
        // Check if a relationship method exists (must accept 0 arguments)
        if (\method_exists($this, $name)) {
            $ref = new ReflectionMethod($this, $name);
            if ($ref->getNumberOfRequiredParameters() === 0 && !$ref->isStatic()) {
                return $this->resolveRelation($name);
            }
        }

        // Check for an accessor: getNameAttribute()
        $accessor = 'get' . self::studly($name) . 'Attribute';
        if (\method_exists($this, $accessor)) {
            return $this->{$accessor}($this->attributes[$name] ?? null);
        }

        if (!\array_key_exists($name, $this->attributes)) {
            return null;
        }

        return $this->castGet($name, $this->attributes[$name]);
    }

    /**
     * Set an attribute value, with mutator support.
     *
     * If a mutator `set{StudlyName}Attribute($value)` exists on the model,
     * it is called instead of the default cast-and-store logic.
     */
    public function __set(string $name, mixed $value): void
    {
        $mutator = 'set' . self::studly($name) . 'Attribute';
        if (\method_exists($this, $mutator)) {
            $this->{$mutator}($value);

            return;
        }

        $this->attributes[$name] = $this->castSet($name, $value);
    }

    /**
     * Check if an attribute is set.
     */
    public function __isset(string $name): bool
    {
        return \array_key_exists($name, $this->attributes) || \method_exists($this, $name);
    }

    /**
     * Unset an attribute.
     */
    public function __unset(string $name): void
    {
        unset($this->attributes[$name]);
    }

    /**
     * Hydrate a model from a database row (marks it as existing).
     *
     * @param array<string, mixed> $row Raw database row
     * @param Database $database Database connection
     *
     * @return static
     */
    public static function newFromRow(array $row, Database $database): static
    {
        $model = new static();
        $model->attributes = $row;
        $model->original = $row;
        $model->exists = true;
        $model->database = $database;

        return $model;
    }

    // -----------------------------------------------------------------------
    //  Static convenience methods
    // -----------------------------------------------------------------------

    /**
     * Get a new query builder for this model.
     *
     * Triggers `boot()` on first call per concrete class, allowing
     * global scopes and other one-time setup to be registered.
     */
    public static function query(Database $database): ModelQuery
    {
        static::bootIfNotBooted();

        return new ModelQuery($database, static::class);
    }

    // -----------------------------------------------------------------------
    //  Global Scopes API
    // -----------------------------------------------------------------------

    /**
     * Register a global scope for this model.
     *
     * Global scopes are applied to every SELECT query.  They can be
     * removed per-query via `ModelQuery::withoutGlobalScope()`.
     *
     * Typically called inside the `boot()` method:
     * ```php
     * protected static function boot(): void
     * {
     *     static::addGlobalScope('active', function (ModelQuery $q) {
     *         $q->where('active=:_gs_active', ['_gs_active' => 1]);
     *     });
     * }
     * ```
     *
     * @param string $name Unique scope identifier
     * @param Closure $scope Receives a ModelQuery instance
     */
    public static function addGlobalScope(string $name, Closure $scope): void
    {
        self::$globalScopes[static::class][$name] = $scope;
    }

    /**
     * Remove a previously registered global scope.
     */
    public static function removeGlobalScope(string $name): void
    {
        unset(self::$globalScopes[static::class][$name]);
    }

    /**
     * Get all global scopes registered for this model class.
     *
     * @return array<string, Closure>
     */
    public static function getGlobalScopes(): array
    {
        return self::$globalScopes[static::class] ?? [];
    }

    /**
     * Clear the booted state, global scopes, and event listeners for
     * all model classes.
     *
     * Useful in test tearDown to ensure a clean state between tests.
     */
    public static function clearBootedModels(): void
    {
        self::$booted = [];
        self::$globalScopes = [];
        self::$events = [];
    }

    /**
     * Register a "creating" listener (fires before INSERT).
     * Return false from the callback to cancel the insert.
     */
    public static function creating(Closure $callback): void
    {
        static::registerModelEvent('creating', $callback);
    }

    /**
     * Register a "created" listener (fires after INSERT).
     */
    public static function created(Closure $callback): void
    {
        static::registerModelEvent('created', $callback);
    }

    /**
     * Register an "updating" listener (fires before UPDATE).
     * Return false from the callback to cancel the update.
     */
    public static function updating(Closure $callback): void
    {
        static::registerModelEvent('updating', $callback);
    }

    /**
     * Register an "updated" listener (fires after UPDATE).
     */
    public static function updated(Closure $callback): void
    {
        static::registerModelEvent('updated', $callback);
    }

    /**
     * Register a "saving" listener (fires before INSERT or UPDATE).
     * Return false from the callback to cancel the operation.
     */
    public static function saving(Closure $callback): void
    {
        static::registerModelEvent('saving', $callback);
    }

    /**
     * Register a "saved" listener (fires after INSERT or UPDATE).
     */
    public static function saved(Closure $callback): void
    {
        static::registerModelEvent('saved', $callback);
    }

    /**
     * Register a "deleting" listener (fires before DELETE — soft or hard).
     * Return false from the callback to cancel the delete.
     */
    public static function deleting(Closure $callback): void
    {
        static::registerModelEvent('deleting', $callback);
    }

    /**
     * Register a "deleted" listener (fires after DELETE — soft or hard).
     */
    public static function deleted(Closure $callback): void
    {
        static::registerModelEvent('deleted', $callback);
    }

    /**
     * Register a "restoring" listener (fires before SoftDeletes restore).
     * Return false from the callback to cancel the restore.
     */
    public static function restoring(Closure $callback): void
    {
        static::registerModelEvent('restoring', $callback);
    }

    /**
     * Register a "restored" listener (fires after SoftDeletes restore).
     */
    public static function restored(Closure $callback): void
    {
        static::registerModelEvent('restored', $callback);
    }

    /**
     * Find a model by primary key, or return null.
     */
    public static function find(Database $database, int|string $id): ?static
    {
        /** @var static|null $result */
        $result = static::query($database)->find($id);

        return $result;
    }

    /**
     * Find a model by primary key, or throw ModelNotFoundException.
     *
     * @throws ModelNotFoundException
     */
    public static function findOrFail(Database $database, int|string $id): static
    {
        $model = static::find($database, $id);

        if ($model === null) {
            throw (new ModelNotFoundException())->setModel(static::class, [$id]);
        }

        return $model;
    }

    /**
     * Retrieve all rows as a ModelCollection.
     */
    public static function all(Database $database): ModelCollection
    {
        return static::query($database)->get();
    }

    /**
     * Insert a new row and return the hydrated model.
     *
     * @param array<string, mixed> $attributes
     */
    public static function create(Database $database, array $attributes): static
    {
        $model = new static();
        $model->database = $database;
        $model->fill($attributes);
        $model->save();

        return $model;
    }

    /**
     * Delete one or more models by primary key.
     *
     * @param int|string ...$ids
     *
     * @return int Number of deleted rows
     */
    public static function destroy(Database $database, int|string ...$ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        $table = static::resolveTable();
        $pk = static::$primaryKey;
        $count = 0;

        foreach ($ids as $id) {
            $database->execute(
                $database->delete($table, [$pk => $id]),
            );
            $count += $database->affectedRows();
        }

        return $count;
    }

    // -----------------------------------------------------------------------
    //  Convenience Methods
    // -----------------------------------------------------------------------

    /**
     * Find the first model matching the search attributes, or create it.
     *
     * @param Database $database Database connection
     * @param array<string, mixed> $search Attributes to search by (WHERE conditions)
     * @param array<string, mixed> $extra Extra attributes to set only when creating
     *
     * @return static
     */
    public static function firstOrCreate(Database $database, array $search, array $extra = []): static
    {
        $model = static::firstByAttributes($database, $search);

        if ($model !== null) {
            return $model;
        }

        return static::create($database, \array_merge($search, $extra));
    }

    /**
     * Find the first model matching the search attributes, or instantiate a new one (unsaved).
     *
     * @param Database $database Database connection
     * @param array<string, mixed> $search Attributes to search by (WHERE conditions)
     * @param array<string, mixed> $extra Extra attributes to set on the new instance
     *
     * @return static
     */
    public static function firstOrNew(Database $database, array $search, array $extra = []): static
    {
        $model = static::firstByAttributes($database, $search);

        if ($model !== null) {
            return $model;
        }

        $instance = new static();
        $instance->database = $database;
        $instance->fill(\array_merge($search, $extra));

        return $instance;
    }

    /**
     * Find the first model matching the search attributes and update it,
     * or create a new one with the merged attributes.
     *
     * @param Database $database Database connection
     * @param array<string, mixed> $search Attributes to search by (WHERE conditions)
     * @param array<string, mixed> $update Attributes to update (or set on create)
     *
     * @return static
     */
    public static function updateOrCreate(Database $database, array $search, array $update): static
    {
        $model = static::firstByAttributes($database, $search);

        if ($model !== null) {
            $model->fill($update);
            $model->save();

            return $model;
        }

        return static::create($database, \array_merge($search, $update));
    }

    /**
     * Get the list of hidden attribute names for this model.
     *
     * @return list<string>
     */
    public static function getHidden(): array
    {
        return static::$hidden;
    }

    /**
     * Get the list of visible attribute names for this model.
     *
     * @return list<string>
     */
    public static function getVisible(): array
    {
        return static::$visible;
    }

    // -----------------------------------------------------------------------
    //  Meta accessors used by ModelQuery / Relation
    // -----------------------------------------------------------------------

    /**
     * Resolve the table name for this model.
     */
    public static function resolveTable(): string
    {
        if (static::$table !== '') {
            return static::$table;
        }

        // Derive from short class name: User → users
        $shortName = (new ReflectionClass(static::class))->getShortName();

        return \strtolower($shortName) . 's';
    }

    /**
     * Get the primary key column name.
     */
    public static function getPrimaryKeyName(): string
    {
        return static::$primaryKey;
    }

    /**
     * Override in subclasses to register global scopes or perform
     * other one-time class-level setup.
     *
     * Called exactly once per concrete class, on the first `query()` call.
     */
    protected static function boot(): void
    {
        // Override in subclasses
    }

    // -----------------------------------------------------------------------
    //  Model Events API
    // -----------------------------------------------------------------------

    /**
     * Register a listener for a model event.
     *
     * @param string $event Event name (e.g. 'creating', 'updated')
     * @param Closure $callback Receives the model instance; return false to cancel "before" events
     */
    protected static function registerModelEvent(string $event, Closure $callback): void
    {
        self::$events[static::class][$event][] = $callback;
    }

    /**
     * Ensure `boot()` has been called for the current concrete class.
     */
    private static function bootIfNotBooted(): void
    {
        if (!isset(self::$booted[static::class])) {
            self::$booted[static::class] = true;
            static::boot();
            static::bootTraits();
        }
    }

    /**
     * Call `boot{TraitName}()` for each trait used by this model.
     *
     * This allows traits like `SoftDeletes` to register global scopes
     * or do other one-time setup via `bootSoftDeletes()`.
     */
    private static function bootTraits(): void
    {
        $traits = static::collectTraits(static::class);

        foreach ($traits as $trait) {
            $method = 'boot' . (new ReflectionClass($trait))->getShortName();

            if (\method_exists(static::class, $method)) {
                static::{$method}();
            }
        }
    }

    /**
     * Recursively collect all traits used by a class and its parents.
     *
     * @return list<class-string>
     */
    private static function collectTraits(string $class): array
    {
        $traits = [];

        foreach (\class_uses($class) ?: [] as $trait) {
            $traits[] = $trait;
            // Also collect traits used by traits
            foreach (static::collectTraits($trait) as $nested) {
                $traits[] = $nested;
            }
        }

        // Also collect traits from parent classes
        $parent = \get_parent_class($class);
        if ($parent) {
            foreach (static::collectTraits($parent) as $inherited) {
                $traits[] = $inherited;
            }
        }

        return \array_unique($traits);
    }

    /**
     * Find the first model matching a set of attribute conditions.
     *
     * @param Database $database Database connection
     * @param array<string, mixed> $attributes Column => value pairs for WHERE
     *
     * @return static|null
     */
    private static function firstByAttributes(Database $database, array $attributes): ?static
    {
        $query = static::query($database);

        // Build Simple Syntax: col1=:col1,col2=:col2 (comma = AND)
        $conditions = \implode(',', \array_map(fn ($key) => "$key=:$key", \array_keys($attributes)));
        $query->where($conditions, $attributes);

        return $query->first();
    }

    /**
     * Convert a snake_case string to StudlyCase.
     *
     * Example: `first_name` → `FirstName`, `email` → `Email`
     */
    private static function studly(string $value): string
    {
        return \str_replace(' ', '', \ucwords(\str_replace(['_', '-'], ' ', $value)));
    }

    /**
     * Increment a column's value by the given amount.
     *
     * Uses an atomic SQL `SET column = column + amount` statement.
     *
     * @param string $column Column to increment
     * @param int|float $amount Amount to increment by (default 1)
     * @param array<string, mixed> $extra Additional columns to update
     *
     * @return bool True if the update was performed
     */
    public function increment(string $column, int|float $amount = 1, array $extra = []): bool
    {
        if (!$this->exists || $this->database === null) {
            return false;
        }

        $table = static::resolveTable();
        $pk = static::$primaryKey;

        // Build update syntax: "column+=:_inc_amount" for atomic increment
        $updateSyntax = [$column . '+=:_inc_amount'];
        $params = ['_inc_amount' => $amount, '_pk' => $this->attributes[$pk]];

        // Add any extra columns as simple assignments
        foreach ($extra as $col => $val) {
            $updateSyntax[] = $col;
            $params[$col] = $val;
        }

        if (static::$timestamps) {
            $now = \date('Y-m-d H:i:s');
            $updateSyntax[] = 'updated_at';
            $params['updated_at'] = $now;
        }

        $this->database->execute(
            $this->database->update($table, $updateSyntax)
                ->where($pk . '=:_pk')
                ->assign($params),
        );

        // Sync in-memory state
        $this->attributes[$column] = ($this->attributes[$column] ?? 0) + $amount;
        foreach ($extra as $col => $val) {
            $this->attributes[$col] = $val;
        }
        if (static::$timestamps) {
            $this->attributes['updated_at'] = $params['updated_at'];
        }
        $this->original = $this->attributes;

        return true;
    }

    /**
     * Decrement a column's value by the given amount.
     *
     * Uses an atomic SQL `SET column = column - amount` statement.
     *
     * @param string $column Column to decrement
     * @param int|float $amount Amount to decrement by (default 1)
     * @param array<string, mixed> $extra Additional columns to update
     *
     * @return bool True if the update was performed
     */
    public function decrement(string $column, int|float $amount = 1, array $extra = []): bool
    {
        return $this->increment($column, -$amount, $extra);
    }

    /**
     * Create a copy of this model without the primary key or timestamps.
     *
     * The returned model is not persisted — call `save()` on it to insert.
     *
     * @param list<string> $except Additional attribute keys to exclude from the copy
     *
     * @return static A new unsaved model instance
     */
    public function replicate(array $except = []): static
    {
        // Always exclude the primary key
        $exclude = \array_merge([static::$primaryKey], $except);

        if (static::$timestamps) {
            $exclude[] = 'created_at';
            $exclude[] = 'updated_at';
        }

        $exclude = \array_unique($exclude);

        $clone = new static();
        $clone->database = $this->database;

        foreach ($this->attributes as $key => $value) {
            if (!\in_array($key, $exclude, true)) {
                $clone->attributes[$key] = $value;
            }
        }

        return $clone;
    }

    /**
     * Update the model's `updated_at` timestamp without changing other data.
     *
     * @return bool True if the timestamp was updated
     */
    public function touch(): bool
    {
        if (!$this->exists || $this->database === null || !static::$timestamps) {
            return false;
        }

        $now = \date('Y-m-d H:i:s');
        $this->attributes['updated_at'] = $now;

        $table = static::resolveTable();
        $pk = static::$primaryKey;

        $this->database->execute(
            $this->database->update($table, ['updated_at'])
                ->where($pk . '=:_pk')
                ->assign(['updated_at' => $now, '_pk' => $this->attributes[$pk]]),
        );

        $this->original['updated_at'] = $now;

        return true;
    }

    /**
     * Get the raw attribute value without casting.
     */
    public function getRawAttribute(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    /**
     * Set a raw attribute value without casting.
     */
    public function setRawAttribute(string $name, mixed $value): static
    {
        $this->attributes[$name] = $value;

        return $this;
    }

    // -----------------------------------------------------------------------
    //  Mass assignment
    // -----------------------------------------------------------------------

    /**
     * Mass-assign attributes respecting `$fillable` / `$guarded`.
     */
    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            if ($this->isFillable($key)) {
                $this->{$key} = $value;
            }
        }

        return $this;
    }

    /**
     * Determine if a column is mass-assignable.
     */
    public function isFillable(string $column): bool
    {
        // If fillable is explicitly defined, only those are allowed
        if (!empty(static::$fillable)) {
            return \in_array($column, static::$fillable, true);
        }

        // If guarded is ['*'], nothing is fillable
        if (static::$guarded === ['*']) {
            return false;
        }

        // Otherwise, allow anything not in guarded
        return !\in_array($column, static::$guarded, true);
    }

    // -----------------------------------------------------------------------
    //  Dirty tracking
    // -----------------------------------------------------------------------

    /**
     * Check if any attribute (or a specific one) has been modified since loading.
     */
    public function isDirty(?string $attribute = null): bool
    {
        if ($attribute !== null) {
            return ($this->attributes[$attribute] ?? null) !== ($this->original[$attribute] ?? null);
        }

        return $this->attributes !== $this->original;
    }

    /**
     * Get all attributes that differ from the original.
     *
     * @return array<string, mixed>
     */
    public function getDirty(): array
    {
        $dirty = [];

        foreach ($this->attributes as $key => $value) {
            if (!\array_key_exists($key, $this->original) || $this->original[$key] !== $value) {
                $dirty[$key] = $value;
            }
        }

        return $dirty;
    }

    /**
     * Get the original value(s).
     *
     * @return mixed A single value if $attribute is given, or the full original array.
     */
    public function getOriginal(?string $attribute = null): mixed
    {
        if ($attribute !== null) {
            return $this->original[$attribute] ?? null;
        }

        return $this->original;
    }

    // -----------------------------------------------------------------------
    //  Persistence
    // -----------------------------------------------------------------------

    /**
     * Persist the model — INSERT if new, UPDATE if existing.
     *
     * @return bool True if the operation was performed
     */
    public function save(): bool
    {
        if ($this->database === null) {
            throw new RuntimeException('Cannot save a model without a database connection. Use Model::setDatabase() or pass $db.');
        }

        if ($this->fireModelEvent('saving', true) === false) {
            return false;
        }

        if ($this->exists) {
            $result = $this->performUpdate();
        } else {
            $result = $this->performInsert();
        }

        if ($result) {
            $this->fireModelEvent('saved');
        }

        return $result;
    }

    /**
     * Delete this model from the database.
     *
     * @return bool True if a row was deleted
     */
    public function delete(): bool
    {
        if (!$this->exists || $this->database === null) {
            return false;
        }

        if ($this->fireModelEvent('deleting', true) === false) {
            return false;
        }

        $pk = static::$primaryKey;
        $id = $this->attributes[$pk] ?? null;

        if ($id === null) {
            return false;
        }

        $table = static::resolveTable();
        $this->database->execute(
            $this->database->delete($table, [$pk => $id]),
        );

        $deleted = $this->database->affectedRows() > 0;

        if ($deleted) {
            $this->exists = false;
            $this->fireModelEvent('deleted');
        }

        return $deleted;
    }

    /**
     * Reload the model from the database, discarding in-memory changes.
     *
     * @return static
     */
    public function refresh(): static
    {
        if (!$this->exists || $this->database === null) {
            return $this;
        }

        $pk = static::$primaryKey;
        $id = $this->attributes[$pk] ?? null;

        if ($id === null) {
            return $this;
        }

        $row = $this->database->prepare()
            ->select('*')
            ->from(static::resolveTable())
            ->where($pk . '=:_pk')
            ->assign(['_pk' => $id])
            ->lazy();

        if ($row) {
            $this->attributes = $row;
            $this->original = $row;
            $this->relationCache = [];
        }

        return $this;
    }

    // -----------------------------------------------------------------------
    //  Serialisation
    // -----------------------------------------------------------------------

    /**
     * Convert the model to an associative array.
     *
     * Applies accessors and casts, then filters through `$hidden` / `$visible`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [];
        foreach ($this->attributes as $key => $value) {
            // Apply accessor if present, otherwise cast
            $accessor = 'get' . self::studly($key) . 'Attribute';
            if (\method_exists($this, $accessor)) {
                $result[$key] = $this->{$accessor}($value);
            } else {
                $result[$key] = $this->castGet($key, $value);
            }
        }

        return $this->filterAttributes($result);
    }

    /**
     * Convert the model to a JSON string.
     *
     * @param int $options `json_encode` flags (e.g. `JSON_PRETTY_PRINT`)
     *
     * @return string
     *
     * @throws JsonException
     */
    public function toJson(int $options = 0): string
    {
        return \json_encode($this->toArray(), $options | JSON_THROW_ON_ERROR);
    }

    /**
     * Get the primary key value.
     */
    public function getKey(): mixed
    {
        return $this->attributes[static::$primaryKey] ?? null;
    }

    /**
     * Whether this model exists in the database.
     */
    public function exists(): bool
    {
        return $this->exists;
    }

    /**
     * Get the database connection for this model instance.
     */
    public function getDatabase(): ?Database
    {
        return $this->database;
    }

    /**
     * Explicitly set the database connection.
     */
    public function setDatabase(Database $database): static
    {
        $this->database = $database;

        return $this;
    }

    /**
     * Pre-set a relation result in the cache (used by eager loading).
     *
     * @param string $name Relation method name
     * @param Model|ModelCollection|null $value The pre-loaded result
     *
     * @return static
     */
    public function setRelation(string $name, self|ModelCollection|null $value): static
    {
        $this->relationCache[$name] = $value;

        return $this;
    }

    /**
     * Check whether a relation has been loaded (cached).
     */
    public function relationLoaded(string $name): bool
    {
        return \array_key_exists($name, $this->relationCache);
    }

    /**
     * Get the Relation object (without resolving it) for a named relationship.
     *
     * Used internally by eager loading to inspect relation metadata.
     *
     * @return Relation|null
     */
    public function getRelationInstance(string $name): ?Relation
    {
        if (!\method_exists($this, $name)) {
            return null;
        }

        $result = $this->{$name}();

        return $result instanceof Relation ? $result : null;
    }

    /**
     * Fire all listeners for a model event.
     *
     * @param string $event Event name
     * @param bool $halt If true, return false when any listener returns false ("before" events)
     *
     * @return bool False only when $halt is true and a listener returned false
     */
    protected function fireModelEvent(string $event, bool $halt = false): bool
    {
        $listeners = self::$events[static::class][$event] ?? [];

        foreach ($listeners as $listener) {
            $result = $listener($this);

            if ($halt && $result === false) {
                return false;
            }
        }

        return true;
    }

    // -----------------------------------------------------------------------
    //  Relationships
    // -----------------------------------------------------------------------

    /**
     * Define a has-one relationship.
     *
     * @param string $related Related model class
     * @param string|null $foreignKey Foreign key on the related table (default: thisModel_id)
     * @param string|null $localKey Local key (default: primary key)
     */
    protected function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): HasOne
    {
        $foreignKey ??= $this->inferForeignKey();
        $localKey ??= static::$primaryKey;

        return new HasOne($this, $related, $foreignKey, $localKey);
    }

    /**
     * Define a has-many relationship.
     */
    protected function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): HasMany
    {
        $foreignKey ??= $this->inferForeignKey();
        $localKey ??= static::$primaryKey;

        return new HasMany($this, $related, $foreignKey, $localKey);
    }

    /**
     * Define a belongs-to relationship.
     *
     * @param string $related Related (parent) model class
     * @param string|null $foreignKey Column on *this* model holding the reference
     * @param string|null $ownerKey Column on the related model (default: its primary key)
     */
    protected function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null): BelongsTo
    {
        // Infer foreign key from the related class name: Post::belongsTo(User::class) → 'user_id'
        if ($foreignKey === null) {
            $shortName = (new ReflectionClass($related))->getShortName();
            $foreignKey = \strtolower($shortName) . '_id';
        }
        $ownerKey ??= $related::$primaryKey;

        return new BelongsTo($this, $related, $foreignKey, $ownerKey);
    }

    /**
     * Define a many-to-many (belongs-to-many) relationship via a pivot table.
     *
     * @param string $related Related model class
     * @param string|null $pivotTable Pivot table name (default: alphabetically sorted table names joined by _)
     * @param string|null $foreignPivotKey Column in pivot referencing this model (default: thisModel_id)
     * @param string|null $relatedPivotKey Column in pivot referencing the related model (default: relatedModel_id)
     * @param string|null $parentKey Column on this model (default: primary key)
     * @param string|null $relatedKey Column on the related model (default: its primary key)
     */
    protected function belongsToMany(
        string $related,
        ?string $pivotTable = null,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        ?string $parentKey = null,
        ?string $relatedKey = null,
    ): BelongsToMany {
        $foreignPivotKey ??= $this->inferForeignKey();

        // Infer related pivot key: relatedClassName_id
        if ($relatedPivotKey === null) {
            $shortName = (new ReflectionClass($related))->getShortName();
            $relatedPivotKey = \strtolower($shortName) . '_id';
        }

        // Infer pivot table: alphabetically sorted table name stems joined by _
        if ($pivotTable === null) {
            $parentStem = \strtolower((new ReflectionClass(static::class))->getShortName());
            $relatedStem = \strtolower((new ReflectionClass($related))->getShortName());
            $parts = [$parentStem, $relatedStem];
            \sort($parts);
            $pivotTable = \implode('_', $parts);
        }

        $parentKey ??= static::$primaryKey;
        $relatedKey ??= $related::$primaryKey;

        return new BelongsToMany(
            $this,
            $related,
            $pivotTable,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey,
            $relatedKey,
        );
    }

    // -----------------------------------------------------------------------
    //  Internal helpers
    // -----------------------------------------------------------------------

    /**
     * Perform an INSERT for a model that doesn't yet exist.
     */
    private function performInsert(): bool
    {
        if ($this->fireModelEvent('creating', true) === false) {
            return false;
        }

        if (static::$timestamps) {
            $now = \date('Y-m-d H:i:s');
            $this->attributes['created_at'] = $now;
            $this->attributes['updated_at'] = $now;
        }

        $table = static::resolveTable();
        $data = $this->attributes;
        $columns = \array_keys($data);

        $this->database->execute(
            $this->database->insert($table, $columns)->assign($data),
        );

        $this->attributes[static::$primaryKey] = (int) $this->database->lastID();
        $this->original = $this->attributes;
        $this->exists = true;

        $this->fireModelEvent('created');

        return true;
    }

    /**
     * Perform an UPDATE for a model that already exists.
     */
    private function performUpdate(): bool
    {
        $dirty = $this->getDirty();

        if (empty($dirty)) {
            return false; // Nothing to update
        }

        if ($this->fireModelEvent('updating', true) === false) {
            return false;
        }

        if (static::$timestamps) {
            $now = \date('Y-m-d H:i:s');
            $dirty['updated_at'] = $now;
            $this->attributes['updated_at'] = $now;
        }

        $table = static::resolveTable();
        $pk = static::$primaryKey;
        $id = $this->attributes[$pk];

        // Build update syntax: bare column names (auto-bind to :column)
        $updateColumns = \array_keys($dirty);

        $this->database->execute(
            $this->database->update($table, $updateColumns)
                ->where($pk . '=:_pk')
                ->assign(\array_merge($dirty, ['_pk' => $id])),
        );

        $this->original = $this->attributes;

        $this->fireModelEvent('updated');

        return true;
    }

    /**
     * Infer a foreign key name from this model's class name.
     * e.g. User → user_id.
     */
    private function inferForeignKey(): string
    {
        $shortName = (new ReflectionClass(static::class))->getShortName();

        return \strtolower($shortName) . '_id';
    }

    /**
     * Resolve and cache a relationship.
     */
    private function resolveRelation(string $name): self|ModelCollection|null
    {
        if (\array_key_exists($name, $this->relationCache)) {
            return $this->relationCache[$name];
        }

        $result = $this->{$name}();

        if ($result instanceof Relation) {
            $result = $result->resolve();
        }

        $this->relationCache[$name] = $result;

        return $result;
    }

    /**
     * Filter an associative array through `$visible` / `$hidden`.
     *
     * If `$visible` is non-empty it acts as a whitelist; otherwise
     * `$hidden` acts as a blacklist.
     *
     * @param array<string, mixed> $attributes
     *
     * @return array<string, mixed>
     */
    private function filterAttributes(array $attributes): array
    {
        if (!empty(static::$visible)) {
            return \array_intersect_key($attributes, \array_flip(static::$visible));
        }

        if (!empty(static::$hidden)) {
            return \array_diff_key($attributes, \array_flip(static::$hidden));
        }

        return $attributes;
    }

    /**
     * Cast a value when reading from the attributes store.
     */
    private function castGet(string $key, mixed $value): mixed
    {
        if (!isset(static::$casts[$key]) || $value === null) {
            return $value;
        }

        return match (static::$casts[$key]) {
            'int', 'integer' => (int) $value,
            'float', 'double' => (float) $value,
            'bool', 'boolean' => (bool) $value,
            'string' => (string) $value,
            'array', 'json' => \is_array($value) ? $value : \json_decode((string) $value, true),
            'datetime' => $value instanceof DateTimeInterface
                ? $value
                : new DateTimeImmutable((string) $value),
            default => $value,
        };
    }

    /**
     * Cast a value when writing to the attributes store.
     */
    private function castSet(string $key, mixed $value): mixed
    {
        if (!isset(static::$casts[$key]) || $value === null) {
            return $value;
        }

        return match (static::$casts[$key]) {
            'int', 'integer' => (int) $value,
            'float', 'double' => (float) $value,
            'bool', 'boolean' => $value ? 1 : 0, // Store booleans as int for DB compatibility
            'string' => (string) $value,
            'array', 'json' => \is_array($value) ? \json_encode($value, JSON_THROW_ON_ERROR) : $value,
            'datetime' => $value instanceof DateTimeInterface
                ? $value->format('Y-m-d H:i:s')
                : $value,
            default => $value,
        };
    }
}
