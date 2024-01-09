<?php
/**
 * This file is part of Razy v0.4.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Action;

use Closure;
use Razy\Action;
use Razy\Error;

class Validate
{
	private ?Closure $postProcess = null;

	private array $plugins = [];

	private mixed $storage = null;

	public function __construct(
		private readonly Action   $action,
		private readonly string   $name,
		private readonly ?Closure $preProcess = null
	)
	{

	}

	/**
	 * @param string $plugins
	 * @return $this
	 * @throws Error
	 */
	public function add(string $plugins): self
	{
		if (preg_match('/[\w-]+(\s*,\s*[\w-]+)*/', $plugins)) {
			$clips = explode(',', $plugins);
			foreach ($clips as $clip) {
				if ($plugin = $this->action->loadPlugin($clip)) {
					$this->plugins[$clip] = new $plugin($this, $clip);
				}
			}
		}
		return $this;
	}

	/**
	 * @param string $plugin
	 * @return Plugin|null
	 */
	public function get(string $plugin): ?Plugin
	{
		return $this->plugins[$plugin] ?? null;
	}

	/**
	 * @return string
	 */
	public function getName(): string
	{
		return $this->name;
	}

	/**
	 * @return Action
	 */
	public function getAction(): Action
	{
		return $this->action;
	}

	/**
	 * @param string $plugin
	 * @return Plugin|null
	 */
	public function __invoke(string $plugin): ?Plugin
	{
		return $this->plugins[$plugin] ?? null;
	}

	/**
	 * @param Closure $closure
	 * @return $this
	 */
	public function onProcess(Closure $closure): self
	{
		$this->postProcess = $closure;
		return $this;
	}

	/**
	 * @param mixed $value
	 * @param mixed|null $compare
	 * @return bool
	 */
	public function process(mixed $value, mixed $compare = null): mixed
	{
		if ($this->preProcess) {
			$closure = $this->preProcess->bindTo($this);
			$value = call_user_func($closure, $value);
		}

		if (!$this->action->hasRejected($this->name)) {
			foreach ($this->plugins as $plugin) {
				if (!$plugin->onValidate($value, $compare)) {
					break;
				}
			}
		}

		if ($this->postProcess && !$this->action->hasRejected($this->name)) {
			$closure = $this->postProcess->bindTo($this);
			$value = call_user_func($closure, $value, $compare);
		}

		return $value;
	}

	/**
	 * @param array|string $code
	 * @return $this
	 */
	public function reject(array|string $code): self
	{
		$this->action->reject($this->name, $code);
		return $this;
	}

	/**
	 * Save the data into storage.
	 *
	 * @param mixed $data
	 * @return $this
	 */
	public function setStorage(mixed $data): self
	{
		$this->storage = $data;
		return $this;
	}

	/**
	 * Get the data from the storage.
	 * @return mixed
	 */
	public function getStorage(): mixed
	{
		return $this->storage;
	}
}