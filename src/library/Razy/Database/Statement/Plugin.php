<?php
/**
 * This file is part of Razy v0.4.
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
	 * @param Closure $postProcess
	 * @return $this
	 */
	public function onPostProcess(Closure $postProcess): self
	{
		$this->postProcess = $postProcess;

		return $this;
	}

	/**
	 * @return void
	 */
	public function build(string $tableName): void
	{

	}
}