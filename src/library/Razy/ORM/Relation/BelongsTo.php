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

namespace Razy\ORM\Relation;

use Razy\ORM\Model;
use Razy\ORM\ModelCollection;

/**
 * BelongsTo relationship — the inverse of HasOne / HasMany.
 *
 * Example: A Post belongsTo User, where posts.user_id references users.id.
 * Here foreignKey lives on the *child* (Post) and localKey is on the parent (User).
 *
 * The constructor signature is the same as Relation, but the semantics differ:
 *   - $foreignKey is the column on **this** model that holds the reference
 *   - $localKey   is the column on the **related** model that is referenced
 *
 * @package Razy\ORM\Relation
 */
class BelongsTo extends Relation
{
    /**
     * Resolve the relationship: find the parent model.
     */
    public function resolve(): Model|ModelCollection|null
    {
        // $foreignKey lives on *this* model (the child) and points to the related (parent) model's $localKey
        $value = $this->parent->{$this->foreignKey};

        if ($value === null) {
            return null;
        }

        return $this->newQuery()
            ->where($this->localKey . '=:_pk', ['_pk' => $value])
            ->first();
    }
}
