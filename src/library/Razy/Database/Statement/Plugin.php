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

class Plugin
{
	protected ?Closure $postProcess = null;
	final public function __construct(protected readonly ?Statement $statement = null)
	{

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