<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Database\Statement;

use Closure;
use Razy\Database\Statement;

class Builder
{
	protected ?Closure $postProcess = null;
    protected ?Statement $statement = null;

    final public function init(Statement $statement = null): static
    {
        if (!$this->statement) {
            $this->statement = $statement;
        }
        return $this;
    }

    /**
     * Build the statement.
     *
     * @param string $tableName
     * @return void
     */
	public function build(string $tableName): void
	{

	}
}