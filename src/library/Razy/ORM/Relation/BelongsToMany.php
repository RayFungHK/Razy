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

namespace Razy\ORM\Relation;

use Razy\Database;
use Razy\ORM\Model;
use Razy\ORM\ModelCollection;

/**
 * BelongsToMany relationship — many-to-many via a pivot (junction) table.
 *
 * Example: A User belongsToMany Roles through the `role_user` pivot table.
 *
 * The pivot table must have at least two foreign key columns:
 *  - foreignPivotKey  → references the parent model's primary key
 *  - relatedPivotKey  → references the related model's primary key
 *
 * @package Razy\ORM\Relation
 */
class BelongsToMany extends Relation
{
    /**
     * @param Model  $parent          The parent model instance
     * @param string $related         Fully-qualified class of the related model
     * @param string $pivotTable      Name of the pivot / junction table
     * @param string $foreignPivotKey Column in pivot referencing the parent model
     * @param string $relatedPivotKey Column in pivot referencing the related model
     * @param string $parentKey       Column on the parent model (usually PK)
     * @param string $relatedKey      Column on the related model (usually PK)
     */
    public function __construct(
        Model $parent,
        string $related,
        private readonly string $pivotTable,
        private readonly string $foreignPivotKey,
        private readonly string $relatedPivotKey,
        string $parentKey,
        private readonly string $relatedKey,
    ) {
        // Parent Relation stores: parent, related, foreignKey (foreignPivotKey), localKey (parentKey)
        parent::__construct($parent, $related, $foreignPivotKey, $parentKey);
    }

    /**
     * Resolve the relationship: query the pivot table, then fetch related models.
     *
     * Uses a two-query strategy:
     *  1. SELECT relatedPivotKey FROM pivot WHERE foreignPivotKey = parentKeyValue
     *  2. SELECT * FROM related WHERE relatedKey IN (…ids)
     */
    public function resolve(): Model|ModelCollection|null
    {
        $parentKeyValue = $this->getLocalKeyValue();

        if ($parentKeyValue === null) {
            return new ModelCollection([]);
        }

        $db = $this->parent->getDatabase();

        // Step 1: fetch related IDs from pivot table
        $relatedIds = $this->fetchPivotIds($db, $parentKeyValue);

        if (empty($relatedIds)) {
            return new ModelCollection([]);
        }

        // Step 2: fetch related models by their IDs
        return $this->fetchRelatedModels($db, $relatedIds);
    }

    /**
     * Attach one or more related models to the parent via the pivot table.
     *
     * Accepts a single ID, an array of IDs, or an associative array of
     * ID => extra pivot attributes.
     *
     * @param int|array<int|string, mixed> $ids
     * @param array<string, mixed>         $extraAttributes Extra columns to set on ALL pivot rows
     */
    public function attach(int|array $ids, array $extraAttributes = []): void
    {
        $db = $this->parent->getDatabase();
        $parentKeyValue = $this->getLocalKeyValue();

        if ($parentKeyValue === null) {
            throw new \RuntimeException('Cannot attach: parent model has no primary key value.');
        }

        $ids = $this->normalizeIds($ids);

        foreach ($ids as $relatedId => $pivotAttributes) {
            $data = array_merge(
                $extraAttributes,
                $pivotAttributes,
                [
                    $this->foreignPivotKey => $parentKeyValue,
                    $this->relatedPivotKey => $relatedId,
                ],
            );

            $columns = array_keys($data);
            $db->execute(
                $db->insert($this->pivotTable, $columns)->assign($data)
            );
        }
    }

    /**
     * Detach one or more related models from the pivot table.
     *
     * With no arguments, detaches ALL related models.
     *
     * @param int|array<int>|null $ids IDs to detach (null = detach all)
     *
     * @return int Number of rows deleted
     */
    public function detach(int|array|null $ids = null): int
    {
        $db = $this->parent->getDatabase();
        $parentKeyValue = $this->getLocalKeyValue();

        if ($parentKeyValue === null) {
            return 0;
        }

        if ($ids === null) {
            // Detach all
            $db->execute(
                $db->delete(
                    $this->pivotTable,
                    ['_fk' => $parentKeyValue],
                    $this->foreignPivotKey . '=:_fk',
                )
            );

            return $db->affectedRows();
        }

        if (is_int($ids)) {
            $ids = [$ids];
        }

        $totalDeleted = 0;

        foreach ($ids as $relatedId) {
            $db->execute(
                $db->delete(
                    $this->pivotTable,
                    [
                        '_fk' => $parentKeyValue,
                        '_rk' => $relatedId,
                    ],
                    $this->foreignPivotKey . '=:_fk,' . $this->relatedPivotKey . '=:_rk',
                )
            );
            $totalDeleted += $db->affectedRows();
        }

        return $totalDeleted;
    }

    /**
     * Sync the pivot table to match the given set of IDs exactly.
     *
     * Attaches missing IDs and detaches IDs not in the given list.
     *
     * @param array<int> $ids The desired set of related IDs
     *
     * @return array{attached: int[], detached: int[]} Summary of changes
     */
    public function sync(array $ids): array
    {
        $db = $this->parent->getDatabase();
        $parentKeyValue = $this->getLocalKeyValue();

        if ($parentKeyValue === null) {
            throw new \RuntimeException('Cannot sync: parent model has no primary key value.');
        }

        $currentIds = $this->fetchPivotIds($db, $parentKeyValue);

        $toAttach = array_diff($ids, $currentIds);
        $toDetach = array_diff($currentIds, $ids);

        if (!empty($toDetach)) {
            $this->detach(array_values($toDetach));
        }

        if (!empty($toAttach)) {
            $this->attach(array_values($toAttach));
        }

        return [
            'attached' => array_values($toAttach),
            'detached' => array_values($toDetach),
        ];
    }

    /**
     * Get the pivot table name.
     */
    public function getPivotTable(): string
    {
        return $this->pivotTable;
    }

    /**
     * Get the foreign pivot key column name.
     */
    public function getForeignPivotKey(): string
    {
        return $this->foreignPivotKey;
    }

    /**
     * Get the related pivot key column name.
     */
    public function getRelatedPivotKey(): string
    {
        return $this->relatedPivotKey;
    }

    /**
     * Get the related model's key column name.
     */
    public function getRelatedKey(): string
    {
        return $this->relatedKey;
    }

    // -----------------------------------------------------------------------
    //  Internal helpers
    // -----------------------------------------------------------------------

    /**
     * Fetch related model IDs from the pivot table.
     *
     * @return int[]
     */
    private function fetchPivotIds(Database $db, mixed $parentKeyValue): array
    {
        $stmt = $db->prepare()
            ->select($this->relatedPivotKey)
            ->from($this->pivotTable);

        $stmt->where($this->foreignPivotKey . '=:_fk');
        $stmt->assign(['_fk' => $parentKeyValue]);

        $rows = $stmt->lazyGroup();

        return array_map(
            fn(array $row) => (int) $row[$this->relatedPivotKey],
            $rows,
        );
    }

    /**
     * Fetch related models whose key is in the given set of IDs.
     */
    private function fetchRelatedModels(Database $db, array $relatedIds): ModelCollection
    {
        /** @var Model $related */
        $related = $this->related;

        // Build WHERE IN via OR syntax: relatedKey=:_r0|relatedKey=:_r1|...
        $whereParts = [];
        $bindings = [];
        foreach (array_values($relatedIds) as $i => $id) {
            $param = '_r' . $i;
            $whereParts[] = $this->relatedKey . '=:' . $param;
            $bindings[$param] = $id;
        }

        $whereSyntax = implode('|', $whereParts);

        return $related::query($db)
            ->where($whereSyntax, $bindings)
            ->get();
    }

    /**
     * Normalise attach input into an associative array of id => extra attributes.
     *
     * @param int|array<int|string, mixed> $ids
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalizeIds(int|array $ids): array
    {
        if (is_int($ids)) {
            return [$ids => []];
        }

        $normalized = [];
        foreach ($ids as $key => $value) {
            if (is_int($key)) {
                // Numeric key — value is either an ID or extra attributes
                if (is_array($value)) {
                    // Should not happen in simple case, treat key as ID
                    $normalized[$key] = $value;
                } else {
                    // Value is the ID itself (sequential array)
                    $normalized[(int) $value] = [];
                }
            } else {
                // String key — key is the ID, value is extra attributes
                $normalized[(int) $key] = is_array($value) ? $value : [];
            }
        }

        return $normalized;
    }
}
