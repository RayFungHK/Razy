<?php
/**
 * This file is part of Razy v0.4.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy;

use Throwable;

class API
{
	/**
	 * @var \Razy\Distributor
	 */
	private Distributor $distributor;

	/**
	 * API constructor.
	 *
	 * @param Distributor $distributor The Distributor instance
	 */
	public function __construct(Distributor $distributor)
	{
		$this->distributor = $distributor;
	}

	/**
	 * Execute the API command.
	 *
	 * @param string $command The API command
	 * @param mixed  ...$args The arguments will pass to the API command
	 *
	 * @throws Throwable
	 *
	 * @return null|mixed
	 */
	public function api(string $command, ...$args)
	{
		return $this->distributor->execute($command, $args);
	}
}
