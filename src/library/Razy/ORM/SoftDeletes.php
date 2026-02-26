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
 * @license MIT
 */

namespace Razy\ORM;

/**
 * Soft-delete trait for ORM models.
 *
 * When used on a Model subclass, records are not physically deleted from
 * the database.  Instead, a `deleted_at` timestamp is set, and a global
 * scope automatically excludes soft-deleted rows from all queries.
 *
 * ```php
 * class Post extends Model
 * {
 *     use SoftDeletes;
 *
 *     protected static string $table = 'posts';
 *     protected static array $fillable = ['title', 'body'];
 * }
 *
 * // Soft-delete (sets deleted_at)
 * $post->delete();
 *
 * // Restore
 * $post->restore();
 *
 * // Query including trashed
 * Post::query($db)->withTrashed()->get();
 *
 * // Query only trashed
 * Post::query($db)->onlyTrashed()->get();
 *
 * // Permanently delete
 * $post->forceDelete();
 * ```
 *
 * @package Razy\ORM
 */
trait SoftDeletes
{
    /**
     * Boot the SoftDeletes trait.
     *
     * Registers a global scope that excludes rows where `deleted_at` is
     * not null, so all standard queries return only non-deleted records.
     *
     * Called automatically by `Model::bootIfNotBooted()` via the
     * `boot{TraitName}` convention.
     */
    public static function bootSoftDeletes(): void
    {
        static::addGlobalScope('softDeletes', function (ModelQuery $query): void {
            $query->whereNull(static::getDeletedAtColumn());
        });
    }

    /**
     * Soft-delete this model (set `deleted_at` to the current timestamp).
     *
     * Overrides `Model::delete()`.  The model remains in the database but
     * is excluded from normal queries by the global scope.
     *
     * @return bool True if the update was performed
     */
    public function delete(): bool
    {
        if (!$this->exists || $this->database === null) {
            return false;
        }

        if ($this->fireModelEvent('deleting', true) === false) {
            return false;
        }

        $result = $this->performSoftDelete();

        if ($result) {
            $this->fireModelEvent('deleted');
        }

        return $result;
    }

    /**
     * Permanently remove this model from the database (hard delete).
     *
     * Bypasses the soft-delete mechanism entirely.
     *
     * @return bool True if a row was deleted
     */
    public function forceDelete(): bool
    {
        if (!$this->exists || $this->database === null) {
            return false;
        }

        if ($this->fireModelEvent('forceDeleting', true) === false) {
            return false;
        }

        $pk = static::$primaryKey;
        $id = $this->attributes[$pk] ?? null;

        if ($id === null) {
            return false;
        }

        $table = static::resolveTable();
        $this->database->execute(
            $this->database->delete($table, [$pk => $id])
        );

        $deleted = $this->database->affectedRows() > 0;

        if ($deleted) {
            $this->exists = false;
            $this->fireModelEvent('forceDeleted');
        }

        return $deleted;
    }

    /**
     * Restore a soft-deleted model (clear `deleted_at`).
     *
     * @return bool True if the update was performed
     */
    public function restore(): bool
    {
        if ($this->database === null) {
            return false;
        }

        if ($this->fireModelEvent('restoring', true) === false) {
            return false;
        }

        $pk = static::$primaryKey;
        $id = $this->attributes[$pk] ?? null;

        if ($id === null) {
            return false;
        }

        $column = static::getDeletedAtColumn();
        $table = static::resolveTable();

        $this->database->execute(
            $this->database->update($table, [$column])
                ->where($pk . '=:_pk')
                ->assign([$column => null, '_pk' => $id])
        );

        $updated = $this->database->affectedRows() > 0;

        if ($updated) {
            $this->attributes[$column] = null;
            $this->original[$column] = null;
            $this->exists = true;
            $this->fireModelEvent('restored');
        }

        return $updated;
    }

    /**
     * Check whether this model instance has been soft-deleted.
     */
    public function trashed(): bool
    {
        $column = static::getDeletedAtColumn();

        return !empty($this->attributes[$column]);
    }

    /**
     * Local scope: include soft-deleted records in the query.
     *
     * Usage: `Model::query($db)->withTrashed()->get()`
     */
    public function scopeWithTrashed(ModelQuery $query): ModelQuery
    {
        return $query->withoutGlobalScope('softDeletes');
    }

    /**
     * Local scope: return ONLY soft-deleted records.
     *
     * Usage: `Model::query($db)->onlyTrashed()->get()`
     */
    public function scopeOnlyTrashed(ModelQuery $query): ModelQuery
    {
        $column = static::getDeletedAtColumn();

        return $query->withoutGlobalScope('softDeletes')
            ->whereNotNull($column);
    }

    /**
     * Get the name of the "deleted at" column.
     *
     * Override in your model to use a different column name.
     */
    public static function getDeletedAtColumn(): string
    {
        return 'deleted_at';
    }

    // -----------------------------------------------------------------------
    //  Internal
    // -----------------------------------------------------------------------

    /**
     * Perform the soft-delete (UPDATE deleted_at = now()).
     */
    private function performSoftDelete(): bool
    {
        $pk = static::$primaryKey;
        $id = $this->attributes[$pk] ?? null;

        if ($id === null) {
            return false;
        }

        $column = static::getDeletedAtColumn();
        $now = date('Y-m-d H:i:s');
        $table = static::resolveTable();

        $this->database->execute(
            $this->database->update($table, [$column])
                ->where($pk . '=:_pk')
                ->assign([$column => $now, '_pk' => $id])
        );

        $updated = $this->database->affectedRows() > 0;

        if ($updated) {
            $this->attributes[$column] = $now;
            $this->original[$column] = $now;
        }

        return $updated;
    }
}
