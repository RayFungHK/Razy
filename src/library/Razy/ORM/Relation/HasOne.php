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

namespace Razy\ORM\Relation;

use Razy\ORM\Model;
use Razy\ORM\ModelCollection;

/**
 * HasOne relationship — the parent has one related model.
 *
 * Example: A User hasOne Profile, where profiles.user_id references users.id.
 */
class HasOne extends Relation
{
    /**
     * Resolve the relationship: return the single related model or null.
     */
    public function resolve(): Model|ModelCollection|null
    {
        $value = $this->getLocalKeyValue();

        if ($value === null) {
            return null;
        }

        return $this->newQuery()
            ->where($this->foreignKey . '=:_fk', ['_fk' => $value])
            ->first();
    }
}
