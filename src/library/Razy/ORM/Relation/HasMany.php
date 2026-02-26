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

/**
 * HasMany relationship — the parent has many related models.
 *
 * Example: A User hasMany Posts, where posts.user_id references users.id.
 *
 * @package Razy\ORM\Relation
 */
class HasMany extends Relation
{
    /**
     * Resolve the relationship: return a collection of related models.
     */
    public function resolve(): Model|ModelCollection|null
    {
        $value = $this->getLocalKeyValue();

        if ($value === null) {
            return new ModelCollection([]);
        }

        return $this->newQuery()
            ->where($this->foreignKey . '=:_fk', ['_fk' => $value])
            ->get();
    }
}
