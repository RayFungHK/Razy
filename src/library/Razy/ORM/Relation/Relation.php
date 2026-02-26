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

use Razy\ORM\Model;
use Razy\ORM\ModelCollection;
use Razy\ORM\ModelQuery;

/**
 * Base class for all model relationships.
 *
 * Defines the common structure for relationship resolution between models.
 *
 * @package Razy\ORM\Relation
 */
abstract class Relation
{
    /**
     * @param Model  $parent     The parent model instance
     * @param string $related    Fully-qualified class name of the related model
     * @param string $foreignKey The foreign key column
     * @param string $localKey   The local key column
     */
    public function __construct(
        protected readonly Model $parent,
        protected readonly string $related,
        protected readonly string $foreignKey,
        protected readonly string $localKey,
    ) {}

    /**
     * Resolve the relationship — execute the query and return the result.
     *
     * @return Model|ModelCollection|null
     */
    abstract public function resolve(): Model|ModelCollection|null;

    /**
     * Get a query builder pre-configured for this relationship.
     */
    protected function newQuery(): ModelQuery
    {
        /** @var Model $related */
        $related = $this->related;

        return $related::query($this->parent->getDatabase());
    }

    /**
     * Get the parent's local key value.
     */
    protected function getLocalKeyValue(): mixed
    {
        return $this->parent->{$this->localKey};
    }

    /**
     * Get the foreign key column name.
     */
    public function getForeignKey(): string
    {
        return $this->foreignKey;
    }

    /**
     * Get the local key column name.
     */
    public function getLocalKey(): string
    {
        return $this->localKey;
    }
}
