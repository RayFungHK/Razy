<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * @package Razy
 *
 * @license MIT
 */

namespace Razy\ORM;

use BadMethodCallException;
use Generator;
use Razy\Database;
use Razy\ORM\Relation\BelongsTo;
use Razy\ORM\Relation\BelongsToMany;
use Razy\ORM\Relation\HasMany;
use Razy\ORM\Relation\HasOne;
use Razy\ORM\Relation\Relation;
use ReflectionProperty;

/**
 * Fluent query builder for ORM models.
 *
 * Wraps Razy's native Database / Statement API and hydrates results into
 * Model instances or ModelCollection objects.
 *
 * Usage:
 * ```php
 * $users = User::query($db)->where('active=:active', ['active' => 1])->orderBy('name')->get();
 * $user  = User::query($db)->find(42);
 * ```
 *
 * @package Razy\ORM
 */
class ModelQuery
{
    /**
     * Accumulated where fragments as [conjunction, syntax] tuples.
     *
     * Each entry is `[',' | '|', string]` where the first element is the
     * conjunction to use when joining with the previous clause (`,` = AND,
     * `|` = OR).  The conjunction of the first entry is ignored.
     *
     * @var list<array{0: string, 1: string}>
     */
    private array $wheres = [];

    /**
     * Accumulated parameter bindings.
     *
     * @var array<string, mixed>
     */
    private array $bindings = [];

    /**
     * Order-by expressions in Razy Simple Syntax (e.g. '<name', '>created_at').
     *
     * @var list<string>
     */
    private array $orders = [];

    /**
     * LIMIT value (null = no limit).
     */
    private ?int $limitValue = null;

    /**
     * OFFSET value (null = no offset).
     */
    private ?int $offsetValue = null;

    /**
     * SELECT columns expression (default '*').
     */
    private string $selectColumns = '*';

    /**
     * Relation names to eager-load after query execution.
     *
     * @var list<string>
     */
    private array $eagerLoad = [];

    /**
     * Global scope names to exclude from this query.
     *
     * @var array<string, true>
     */
    private array $removedScopes = [];

    /**
     * Whether ALL global scopes should be skipped for this query.
     */
    private bool $withoutAllScopes = false;

    /**
     * Whether global scopes have already been applied to this query.
     */
    private bool $globalScopesApplied = false;

    /**
     * @param Database $database The database connection
     * @param string $modelClass Fully-qualified model class name
     */
    public function __construct(
        private readonly Database $database,
        private readonly string $modelClass,
    ) {
    }

    /**
     * Forward calls to local scope methods defined on the model.
     *
     * Any method named `scope{Name}` on the model class can be invoked on
     * the query builder as `{name}()`.
     *
     * ```php
     * // Model:
     * public function scopeActive(ModelQuery $query): ModelQuery {
     *     return $query->where('active=:_s_active', ['_s_active' => 1]);
     * }
     *
     * // Usage:
     * User::query($db)->active()->get();
     * ```
     *
     * @throws BadMethodCallException If no matching scope method exists
     */
    public function __call(string $method, array $parameters): mixed
    {
        $scopeMethod = 'scope' . \ucfirst($method);

        if (\method_exists($this->modelClass, $scopeMethod)) {
            $instance = new ($this->modelClass)();
            $result = $instance->{$scopeMethod}($this, ...$parameters);

            return $result ?? $this;
        }

        throw new BadMethodCallException(
            \sprintf('Method %s() does not exist on %s.', $method, static::class),
        );
    }

    // -----------------------------------------------------------------------
    //  Constraints
    // -----------------------------------------------------------------------

    /**
     * Add a WHERE IS NULL condition for the given column.
     *
     * @param string $column Column name
     *
     * @return static
     */
    public function whereNull(string $column): static
    {
        $paramKey = '_wn_' . \str_replace('.', '_', $column);
        $this->wheres[] = [',', $column . '=:' . $paramKey];
        $this->bindings[$paramKey] = null;

        return $this;
    }

    /**
     * Add a WHERE IS NOT NULL condition for the given column.
     *
     * @param string $column Column name
     *
     * @return static
     */
    public function whereNotNull(string $column): static
    {
        $paramKey = '_wnn_' . \str_replace('.', '_', $column);
        $this->wheres[] = [',', '!' . $column . '=:' . $paramKey];
        $this->bindings[$paramKey] = null;

        return $this;
    }

    /**
     * Add a WHERE condition using Razy's Simple Syntax.
     *
     * Multiple calls are combined with AND (`,`).
     *
     * @param string $syntax Simple Syntax expression, e.g. `name=:name`
     * @param array<string, mixed> $params Parameter bindings for placeholders
     *
     * @return $this
     */
    public function where(string $syntax, array $params = []): static
    {
        $this->wheres[] = [',', $syntax];
        $this->bindings = \array_merge($this->bindings, $params);

        return $this;
    }

    /**
     * Add a WHERE condition joined with OR.
     *
     * When combined with previous `where()` calls, the condition is
     * joined using OR instead of AND.
     *
     * ```php
     * User::query($db)
     *     ->where('role=:role', ['role' => 'admin'])
     *     ->orWhere('is_super=:s', ['s' => 1])
     *     ->get();
     * // WHERE role = 'admin' OR is_super = 1
     * ```
     *
     * @param string $syntax Simple Syntax expression
     * @param array<string, mixed> $params Parameter bindings
     *
     * @return $this
     */
    public function orWhere(string $syntax, array $params = []): static
    {
        $this->wheres[] = ['|', $syntax];
        $this->bindings = \array_merge($this->bindings, $params);

        return $this;
    }

    /**
     * Add a WHERE IN condition.
     *
     * ```php
     * User::query($db)->whereIn('status', ['active', 'pending'])->get();
     * ```
     *
     * @param string $column Column name
     * @param array $values Values for the IN list
     *
     * @return $this
     */
    public function whereIn(string $column, array $values): static
    {
        $paramKey = '_wi_' . \str_replace('.', '_', $column);
        $this->wheres[] = [',', $column . '|=:' . $paramKey];
        $this->bindings[$paramKey] = $values;

        return $this;
    }

    /**
     * Add a WHERE NOT IN condition.
     *
     * @param string $column Column name
     * @param array $values Values for the NOT IN list
     *
     * @return $this
     */
    public function whereNotIn(string $column, array $values): static
    {
        $paramKey = '_wni_' . \str_replace('.', '_', $column);
        $this->wheres[] = [',', '!' . $column . '|=:' . $paramKey];
        $this->bindings[$paramKey] = $values;

        return $this;
    }

    /**
     * Add a WHERE BETWEEN condition.
     *
     * ```php
     * Product::query($db)->whereBetween('price', 10, 100)->get();
     * ```
     *
     * @param string $column Column name
     * @param mixed $min Minimum value (inclusive)
     * @param mixed $max Maximum value (inclusive)
     *
     * @return $this
     */
    public function whereBetween(string $column, mixed $min, mixed $max): static
    {
        $paramKey = '_wb_' . \str_replace('.', '_', $column);
        $this->wheres[] = [',', $column . '><:' . $paramKey];
        $this->bindings[$paramKey] = [$min, $max];

        return $this;
    }

    /**
     * Add a WHERE NOT BETWEEN condition.
     *
     * @param string $column Column name
     * @param mixed $min Minimum value
     * @param mixed $max Maximum value
     *
     * @return $this
     */
    public function whereNotBetween(string $column, mixed $min, mixed $max): static
    {
        $paramKey = '_wnb_' . \str_replace('.', '_', $column);
        $this->wheres[] = [',', '!' . $column . '><:' . $paramKey];
        $this->bindings[$paramKey] = [$min, $max];

        return $this;
    }

    /**
     * Set the ORDER BY clause.
     *
     * @param string $column Column name
     * @param string $direction 'ASC' or 'DESC'
     *
     * @return $this
     */
    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $prefix = \strtoupper($direction) === 'DESC' ? '>' : '<';
        $this->orders[] = $prefix . $column;

        return $this;
    }

    /**
     * Set the result limit.
     *
     * @return $this
     */
    public function limit(int $count): static
    {
        $this->limitValue = $count;

        return $this;
    }

    /**
     * Set the result offset.
     *
     * @return $this
     */
    public function offset(int $offset): static
    {
        $this->offsetValue = $offset;

        return $this;
    }

    /**
     * Set the SELECT columns.
     *
     * @return $this
     */
    public function select(string $columns): static
    {
        $this->selectColumns = $columns;

        return $this;
    }

    /**
     * Exclude one or more global scopes from this query.
     *
     * ```php
     * User::query($db)->withoutGlobalScope('active')->get();
     * ```
     *
     * @param string ...$names Scope names to exclude
     *
     * @return $this
     */
    public function withoutGlobalScope(string ...$names): static
    {
        foreach ($names as $name) {
            $this->removedScopes[$name] = true;
        }

        return $this;
    }

    /**
     * Exclude ALL global scopes from this query.
     *
     * ```php
     * User::query($db)->withoutGlobalScopes()->get();
     * ```
     *
     * @return $this
     */
    public function withoutGlobalScopes(): static
    {
        $this->withoutAllScopes = true;

        return $this;
    }

    /**
     * Specify relationships to eager-load.
     *
     * Accepts one or more relation method names. When the query is executed
     * via `get()`, the named relations are batch-loaded in a minimal number
     * of queries, preventing N+1.
     *
     * ```php
     * $users = User::query($db)->with('posts', 'profile')->get();
     * ```
     *
     * @param string ...$relations Relation method names
     *
     * @return $this
     */
    public function with(string ...$relations): static
    {
        $this->eagerLoad = \array_unique(\array_merge($this->eagerLoad, $relations));

        return $this;
    }

    // -----------------------------------------------------------------------
    //  Terminal methods — execute and return results
    // -----------------------------------------------------------------------

    /**
     * Execute the query and return all matching models as a ModelCollection.
     */
    public function get(): ModelCollection
    {
        $rows = $this->executeSelect();

        /** @var Model $modelClass */
        $modelClass = $this->modelClass;
        $models = [];

        foreach ($rows as $row) {
            $models[] = $modelClass::newFromRow($row, $this->database);
        }

        $collection = new ModelCollection($models);

        // Eager-load requested relations
        if (!empty($this->eagerLoad) && !empty($models)) {
            $this->loadEagerRelations($models);
        }

        return $collection;
    }

    /**
     * Execute the query and return the first matching model, or null.
     */
    public function first(): ?Model
    {
        $this->applyGlobalScopes();
        $original = $this->limitValue;
        $this->limitValue = 1;

        $stmt = $this->buildSelectStatement();
        $row = $stmt->lazy();

        $this->limitValue = $original;

        if (!$row) {
            return null;
        }

        /** @var Model $modelClass */
        $modelClass = $this->modelClass;

        return $modelClass::newFromRow($row, $this->database);
    }

    /**
     * Find a single model by primary key.
     *
     * @param int|string $id Primary key value
     */
    public function find(int|string $id): ?Model
    {
        /** @var Model $modelClass */
        $modelClass = $this->modelClass;
        $pk = $modelClass::getPrimaryKeyName();

        return $this->where($pk . '=:_pk', ['_pk' => $id])->first();
    }

    /**
     * Return the count of matching rows.
     */
    public function count(): int
    {
        $this->applyGlobalScopes();
        $stmt = $this->database->prepare()
            ->select('COUNT(*) as cnt')
            ->from($this->getTable());

        $this->applyWhere($stmt);

        $row = $stmt->lazy();

        return $row ? (int) $row['cnt'] : 0;
    }

    /**
     * Paginate results with total count.
     *
     * Returns a `Paginator` object with typed getters (`items()`, `total()`,
     * `currentPage()`, etc.), URL generation, JSON serialization, and
     * backward-compatible `ArrayAccess` (`$page['data']`, `$page['total']`).
     *
     * @param int $page Page number (1-based)
     * @param int $perPage Results per page
     */
    public function paginate(int $page = 1, int $perPage = 15): Paginator
    {
        $total = $this->count();
        $perPage = \max(1, $perPage);
        $lastPage = \max(1, (int) \ceil($total / $perPage));
        $page = \max(1, \min($page, $lastPage));

        $this->limitValue = $perPage;
        $this->offsetValue = ($page - 1) * $perPage;

        $data = $this->get();

        return new Paginator($data, $total, $page, $perPage);
    }

    /**
     * Paginate results without a total count.
     *
     * More efficient than `paginate()` because it skips the COUNT query.
     * Instead, it fetches `$perPage + 1` records to determine if more
     * pages exist.
     *
     * Returns a `Paginator` with `total()` = null and `lastPage()` = null.
     *
     * @param int $page Page number (1-based)
     * @param int $perPage Results per page
     */
    public function simplePaginate(int $page = 1, int $perPage = 15): Paginator
    {
        $perPage = \max(1, $perPage);
        $page = \max(1, $page);

        // Fetch one extra record to check if there are more pages
        $this->limitValue = $perPage + 1;
        $this->offsetValue = ($page - 1) * $perPage;

        $data = $this->get();
        $all = $data->all();
        $hasMore = \count($all) > $perPage;

        // Trim the extra record if present
        if ($hasMore) {
            \array_pop($all);
            $data = new ModelCollection($all);
        }

        return new Paginator($data, null, $page, $perPage, $hasMore);
    }

    /**
     * Process query results in fixed-size chunks.
     *
     * The callback receives a `ModelCollection` for each chunk.
     * Return `false` from the callback to stop processing.
     *
     * ```php
     * User::query($db)->chunk(100, function (ModelCollection $users) {
     *     foreach ($users as $user) { ... }
     * });
     * ```
     *
     * @param int $size Number of records per chunk
     * @param callable $callback fn(ModelCollection $chunk, int $page): bool|void
     *
     * @return bool True if all chunks were processed
     */
    public function chunk(int $size, callable $callback): bool
    {
        $page = 1;

        do {
            $this->limitValue = $size;
            $this->offsetValue = ($page - 1) * $size;
            // Reset scopes-applied flag so that the builder can re-apply
            $this->globalScopesApplied = false;

            $results = $this->get();

            if ($results->isEmpty()) {
                break;
            }

            if ($callback($results, $page) === false) {
                return false;
            }

            ++$page;
        } while ($results->count() === $size);

        return true;
    }

    /**
     * Lazily iterate over query results one model at a time.
     *
     * Returns a Generator that yields Model instances without loading
     * the entire result set into memory at once.
     *
     * ```php
     * foreach (User::query($db)->cursor() as $user) {
     *     // Process one user at a time
     * }
     * ```
     *
     * @return Generator<int, Model>
     */
    public function cursor(): Generator
    {
        $rows = $this->executeSelect();

        /** @var Model $modelClass */
        $modelClass = $this->modelClass;

        foreach ($rows as $row) {
            yield $modelClass::newFromRow($row, $this->database);
        }
    }

    // -----------------------------------------------------------------------
    //  Mutating terminal methods
    // -----------------------------------------------------------------------

    /**
     * Insert a new row and return a hydrated Model.
     *
     * @param array<string, mixed> $attributes Column => value pairs
     */
    public function create(array $attributes): Model
    {
        /** @var Model $modelClass */
        $modelClass = $this->modelClass;
        $table = $modelClass::resolveTable();

        $columns = \array_keys($attributes);

        $this->database->execute(
            $this->database->insert($table, $columns)->assign($attributes),
        );

        $id = (int) $this->database->lastID();

        // Build the attribute set with the generated primary key
        $pk = $modelClass::getPrimaryKeyName();
        $attributes[$pk] = $id;

        return $modelClass::newFromRow($attributes, $this->database);
    }

    /**
     * Bulk-update all matching rows. Returns the number of affected rows.
     *
     * @param array<string, mixed> $attributes Column => value pairs to update
     */
    public function bulkUpdate(array $attributes): int
    {
        $this->applyGlobalScopes();
        $table = $this->getTable();
        $updateSyntax = \array_keys($attributes);

        $stmt = $this->database->update($table, $updateSyntax);
        $this->applyWhere($stmt);
        $stmt->assign(\array_merge($attributes, $this->bindings));

        $this->database->execute($stmt);

        return $this->database->affectedRows();
    }

    /**
     * Delete all matching rows. Returns the number of affected rows.
     */
    public function bulkDelete(): int
    {
        $this->applyGlobalScopes();
        $table = $this->getTable();

        // Build a delete with an explicit where syntax
        $whereSyntax = $this->buildWhereSyntax();
        $stmt = $this->database->delete($table, $this->bindings, $whereSyntax);

        $this->database->execute($stmt);

        return $this->database->affectedRows();
    }

    // -----------------------------------------------------------------------
    //  Internal helpers
    // -----------------------------------------------------------------------

    /**
     * Execute a SELECT and return the raw row arrays.
     *
     * @return array<int, array<string, mixed>>
     */
    private function executeSelect(): array
    {
        $this->applyGlobalScopes();
        $stmt = $this->buildSelectStatement();

        return $stmt->lazyGroup();
    }

    /**
     * Build a fully-configured SELECT Statement object.
     */
    private function buildSelectStatement(): Database\Statement
    {
        $stmt = $this->database->prepare()
            ->select($this->selectColumns)
            ->from($this->getTable());

        $this->applyWhere($stmt);

        if (!empty($this->orders)) {
            $stmt->order(\implode(',', $this->orders));
        }

        if ($this->limitValue !== null) {
            if ($this->offsetValue !== null && $this->offsetValue > 0) {
                $stmt->limit($this->offsetValue, $this->limitValue);
            } else {
                $stmt->limit($this->limitValue);
            }
        }

        return $stmt;
    }

    /**
     * Apply accumulated where clauses and bindings to a Statement.
     */
    private function applyWhere(Database\Statement $stmt): void
    {
        $syntax = $this->buildWhereSyntax();

        if ($syntax !== '') {
            $stmt->where($syntax);
        }

        if (!empty($this->bindings)) {
            $stmt->assign($this->bindings);
        }
    }

    /**
     * Build the combined where syntax string from tuples.
     *
     * Each entry is `[conjunction, syntax]`.  The first entry's conjunction
     * is ignored; subsequent entries use their conjunction (`,` or `|`) to
     * join with the previous clause.
     */
    private function buildWhereSyntax(): string
    {
        if (empty($this->wheres)) {
            return '';
        }

        $parts = [];
        foreach ($this->wheres as $i => [$conjunction, $syntax]) {
            if ($i === 0) {
                $parts[] = $syntax;
            } else {
                $parts[] = $conjunction . $syntax;
            }
        }

        return \implode('', $parts);
    }

    /**
     * Apply global scopes to this query (exactly once).
     *
     * Each global scope closure receives this ModelQuery instance and may
     * add where-clauses, orderings, etc.
     */
    private function applyGlobalScopes(): void
    {
        if ($this->globalScopesApplied || $this->withoutAllScopes) {
            $this->globalScopesApplied = true;

            return;
        }

        $this->globalScopesApplied = true;

        /** @var Model $modelClass */
        $modelClass = $this->modelClass;
        $scopes = $modelClass::getGlobalScopes();

        foreach ($scopes as $name => $scope) {
            if (!isset($this->removedScopes[$name])) {
                $scope($this);
            }
        }
    }

    /**
     * Resolve the table name from the model class.
     */
    private function getTable(): string
    {
        /** @var Model $modelClass */
        $modelClass = $this->modelClass;

        return $modelClass::resolveTable();
    }

    // -----------------------------------------------------------------------
    //  Eager loading engine
    // -----------------------------------------------------------------------

    /**
     * Batch-load all requested relations for the given set of parent models.
     *
     * For each relation name, inspects the Relation type and dispatches to
     * the appropriate batch-loading strategy, executing one query per
     * relation (instead of one per model).
     *
     * @param Model[] $models Hydrated parent models
     */
    private function loadEagerRelations(array $models): void
    {
        // Use the first model to introspect relation metadata
        $sample = $models[0];

        foreach ($this->eagerLoad as $relationName) {
            $relation = $sample->getRelationInstance($relationName);

            if ($relation === null) {
                continue;
            }

            match (true) {
                $relation instanceof BelongsToMany => $this->eagerLoadBelongsToMany($models, $relationName, $relation),
                $relation instanceof HasMany => $this->eagerLoadHasMany($models, $relationName, $relation),
                $relation instanceof HasOne => $this->eagerLoadHasOne($models, $relationName, $relation),
                $relation instanceof BelongsTo => $this->eagerLoadBelongsTo($models, $relationName, $relation),
                default => null, // Unknown relation type — skip
            };
        }
    }

    /**
     * Eager-load a HasMany relation.
     *
     * Executes: SELECT * FROM related WHERE foreignKey IN (parent1, parent2, ...)
     * Then groups results by foreign key and injects ModelCollections.
     *
     * @param Model[] $models
     */
    private function eagerLoadHasMany(array $models, string $relationName, HasMany $relation): void
    {
        $localKey = $relation->getLocalKey();
        $foreignKey = $relation->getForeignKey();

        $parentKeys = $this->collectKeys($models, $localKey);
        if (empty($parentKeys)) {
            $this->setEmptyCollections($models, $relationName);
            return;
        }

        $relatedClass = $this->getRelatedClass($models[0], $relationName);
        $relatedRows = $this->queryRelatedWhereIn($relatedClass, $foreignKey, $parentKeys);

        // Group rows by foreign key
        $grouped = [];
        foreach ($relatedRows as $row) {
            $fkValue = $row[$foreignKey] ?? null;
            $grouped[$fkValue][] = $row;
        }

        // Inject into each parent model
        foreach ($models as $model) {
            $key = $model->{$localKey};
            $relatedModels = [];
            if (isset($grouped[$key])) {
                foreach ($grouped[$key] as $row) {
                    $relatedModels[] = $relatedClass::newFromRow($row, $this->database);
                }
            }
            $model->setRelation($relationName, new ModelCollection($relatedModels));
        }
    }

    /**
     * Eager-load a HasOne relation.
     *
     * Same strategy as HasMany but injects single Model|null per parent.
     *
     * @param Model[] $models
     */
    private function eagerLoadHasOne(array $models, string $relationName, HasOne $relation): void
    {
        $localKey = $relation->getLocalKey();
        $foreignKey = $relation->getForeignKey();

        $parentKeys = $this->collectKeys($models, $localKey);
        if (empty($parentKeys)) {
            $this->setNullRelations($models, $relationName);
            return;
        }

        $relatedRows = $this->queryRelatedWhereIn(
            $this->getRelatedClass($models[0], $relationName),
            $foreignKey,
            $parentKeys,
        );

        // Index by foreign key (first match wins for HasOne)
        $indexed = [];
        foreach ($relatedRows as $row) {
            $fkValue = $row[$foreignKey] ?? null;
            if (!isset($indexed[$fkValue])) {
                $indexed[$fkValue] = $row;
            }
        }

        $relatedClass = $this->getRelatedClass($models[0], $relationName);
        foreach ($models as $model) {
            $key = $model->{$localKey};
            if (isset($indexed[$key])) {
                $model->setRelation($relationName, $relatedClass::newFromRow($indexed[$key], $this->database));
            } else {
                $model->setRelation($relationName, null);
            }
        }
    }

    /**
     * Eager-load a BelongsTo relation.
     *
     * Collects foreign key values from parent models, queries the related table
     * with WHERE pk IN (...), then injects.
     *
     * @param Model[] $models
     */
    private function eagerLoadBelongsTo(array $models, string $relationName, BelongsTo $relation): void
    {
        $foreignKey = $relation->getForeignKey();
        $localKey = $relation->getLocalKey(); // This is the owner key on the related model

        // Collect foreign key values from parent models
        $fkValues = $this->collectKeys($models, $foreignKey);
        if (empty($fkValues)) {
            $this->setNullRelations($models, $relationName);
            return;
        }

        $relatedClass = $this->getRelatedClass($models[0], $relationName);

        // Query related table: WHERE ownerKey IN (...)
        $relatedRows = $this->queryRelatedWhereIn($relatedClass, $localKey, $fkValues);

        // Index by owner key
        $indexed = [];
        foreach ($relatedRows as $row) {
            $indexed[$row[$localKey] ?? null] = $row;
        }

        foreach ($models as $model) {
            $fkValue = $model->{$foreignKey};
            if (isset($indexed[$fkValue])) {
                $model->setRelation($relationName, $relatedClass::newFromRow($indexed[$fkValue], $this->database));
            } else {
                $model->setRelation($relationName, null);
            }
        }
    }

    /**
     * Eager-load a BelongsToMany relation.
     *
     * 1. Query pivot table for all parent key values at once
     * 2. Query related table for all found related IDs
     * 3. Group and inject ModelCollections into each parent
     *
     * @param Model[] $models
     */
    private function eagerLoadBelongsToMany(array $models, string $relationName, BelongsToMany $relation): void
    {
        $localKey = $relation->getLocalKey();
        $pivotTable = $relation->getPivotTable();
        $foreignPivotKey = $relation->getForeignPivotKey();
        $relatedPivotKey = $relation->getRelatedPivotKey();
        $relatedKey = $relation->getRelatedKey();

        $parentKeys = $this->collectKeys($models, $localKey);
        if (empty($parentKeys)) {
            $this->setEmptyCollections($models, $relationName);
            return;
        }

        // Step 1: Query pivot table for all parent keys at once
        $pivotRows = $this->queryPivotWhereIn(
            $pivotTable,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKeys,
        );

        // Build parent → relatedIds mapping
        $pivotMap = []; // parentKey => [relatedId, ...]
        $allRelatedIds = [];
        foreach ($pivotRows as $row) {
            $pk = $row[$foreignPivotKey];
            $rk = (int) $row[$relatedPivotKey];
            $pivotMap[$pk][] = $rk;
            $allRelatedIds[$rk] = true;
        }

        if (empty($allRelatedIds)) {
            $this->setEmptyCollections($models, $relationName);
            return;
        }

        // Step 2: Fetch all related models in one query
        $relatedClass = $this->getRelatedClass($models[0], $relationName);
        $relatedRows = $this->queryRelatedWhereIn($relatedClass, $relatedKey, \array_keys($allRelatedIds));

        // Index related models by their key
        $relatedIndex = [];
        foreach ($relatedRows as $row) {
            $relatedIndex[$row[$relatedKey]] = $row;
        }

        // Step 3: Inject into each parent
        foreach ($models as $model) {
            $key = $model->{$localKey};
            $relatedModels = [];
            if (isset($pivotMap[$key])) {
                foreach ($pivotMap[$key] as $relatedId) {
                    if (isset($relatedIndex[$relatedId])) {
                        $relatedModels[] = $relatedClass::newFromRow($relatedIndex[$relatedId], $this->database);
                    }
                }
            }
            $model->setRelation($relationName, new ModelCollection($relatedModels));
        }
    }

    // -----------------------------------------------------------------------
    //  Eager loading helpers
    // -----------------------------------------------------------------------

    /**
     * Collect non-null key values from an array of models.
     *
     * @param Model[] $models
     *
     * @return array Unique key values
     */
    private function collectKeys(array $models, string $key): array
    {
        $keys = [];
        foreach ($models as $model) {
            $value = $model->{$key};
            if ($value !== null) {
                $keys[$value] = true;
            }
        }

        return \array_keys($keys);
    }

    /**
     * Query a model's table with WHERE column IN (...).
     *
     * @param string $modelClass Fully-qualified Model class
     * @param string $column Column name
     * @param array $values Values for IN clause
     *
     * @return array<int, array<string, mixed>>
     */
    private function queryRelatedWhereIn(string $modelClass, string $column, array $values): array
    {
        /** @var Model $modelClass */
        $table = $modelClass::resolveTable();

        $whereParts = [];
        $bindings = [];
        foreach (\array_values($values) as $i => $val) {
            $param = '_ei' . $i;
            $whereParts[] = $column . '=:' . $param;
            $bindings[$param] = $val;
        }

        $stmt = $this->database->prepare()
            ->select('*')
            ->from($table);

        $stmt->where(\implode('|', $whereParts));
        $stmt->assign($bindings);

        return $stmt->lazyGroup();
    }

    /**
     * Query the pivot table for rows where foreignPivotKey IN (...).
     *
     * @return array<int, array<string, mixed>>
     */
    private function queryPivotWhereIn(
        string $pivotTable,
        string $foreignPivotKey,
        string $relatedPivotKey,
        array $parentKeys,
    ): array {
        $whereParts = [];
        $bindings = [];
        foreach (\array_values($parentKeys) as $i => $val) {
            $param = '_pk' . $i;
            $whereParts[] = $foreignPivotKey . '=:' . $param;
            $bindings[$param] = $val;
        }

        $stmt = $this->database->prepare()
            ->select($foreignPivotKey . ',' . $relatedPivotKey)
            ->from($pivotTable);

        $stmt->where(\implode('|', $whereParts));
        $stmt->assign($bindings);

        return $stmt->lazyGroup();
    }

    /**
     * Get the related model class from a relation method.
     *
     * @param Model $model Parent model
     * @param string $relationName Relation method name
     *
     * @return string Fully-qualified class name
     */
    private function getRelatedClass(Model $model, string $relationName): string
    {
        $relation = $model->getRelationInstance($relationName);
        $ref = new ReflectionProperty(Relation::class, 'related');

        return $ref->getValue($relation);
    }

    /**
     * Set empty ModelCollections for all models on a given relation.
     *
     * @param Model[] $models
     */
    private function setEmptyCollections(array $models, string $relationName): void
    {
        foreach ($models as $model) {
            $model->setRelation($relationName, new ModelCollection([]));
        }
    }

    /**
     * Set null for all models on a given relation.
     *
     * @param Model[] $models
     */
    private function setNullRelations(array $models, string $relationName): void
    {
        foreach ($models as $model) {
            $model->setRelation($relationName, null);
        }
    }
}
