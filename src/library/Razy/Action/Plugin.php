<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Action;

use Razy\Database;

class Plugin
{
	private bool $ignored = false;

    /**
     * Plugin constructor.
     *
     * @param Validate|null $validate
     * @param string|null $name
     */
	final public function __construct(private readonly ?Validate $validate = null)
	{

	}

	/**
	 * @return Database
	 */
	final public function getDB(): Database
	{
		return $this->validate->getAction()->getDB();
	}

	/**
	 * @return string
	 */
	final public function getName(): string
	{
		return $this->validate->getName();
	}

	/**
	 * @param string $name
	 * @return mixed
	 */
	final public function getValue(string $name): mixed
	{
		return $this->validate->getAction()->getDataset()[$name] ?? null;
	}

	/**
	 * @param string $name
	 * @param mixed $value
	 * @return $this
	 */
	final public function setValue(string $name, mixed $value): self
	{
		$dataset = $this->validate->getAction()->setValue($name, $value);

		return $this;
	}

	/**
	 * @param string $code
	 * @param string $alias
	 * @return $this
	 */
	final public function reject(string $code, string $alias = ''): self
	{
		$code = trim($code);
		if ($code) {
			$this->validate->reject($code, $alias);
		}
		return $this;
	}

	/**
	 * Save the data into validation's storage.
	 *
	 * @param mixed $data
	 * @return $this
	 */
	final public function setStorage(mixed $data): self
	{
		$this->validate->setStorage($data);
		return $this;
	}

	/**
	 * Get the data from the validation's storage.
	 *
	 * @return self
	 */
	final public function getStorage(): self
	{
		return $this->validate->getStorage();
	}

	/**
	 * Set the data is ignored in SQL queue
	 *
	 * @param bool $ignore
	 * @return Plugin
	 */
	final public function ignore(bool $ignore): self
	{
		$this->ignored = $ignore;
		return $this;
	}

	/**
	 * Return true if the data is ignored
	 *
	 * @return bool
	 */
	final public function isIgnored(): bool
	{
		return $this->ignored;
	}

	/**
	 * Get the action type
	 *
	 * @return int
	 */
	final public function getType(): int
	{
		return $this->validate->getAction()->getType();
	}

	/**
	 * @param mixed $value
	 * @param mixed $compare
	 * @return bool
	 */
	public function onValidate(mixed &$value, mixed $compare = null): bool
	{
		return true;
	}

	/**
	 * @return string
	 */
	public function onPrepare(): string
	{
		return '';
	}

	/**
	 * @param array $parameters
	 * @return array
	 */
	public function onSubmit(array &$parameters): array
	{
		return $parameters;
	}

	/**
	 * @return string
	 */
	final public function getIDColumn(): string
	{
		return $this->validate->getAction()->getIDColumn();
	}

	/**
	 * @return string
	 */
	final public function getMarkerColumn(): string
	{
		return $this->validate->getAction()->getMarkerColumn();
	}

	/**
	 * @return string
	 */
	final public function getTableName(): string
	{
		return $this->validate->getAction()->getTableName();
	}

	/**
	 * @return string
	 */
	final public function getUniqueKey(): string
	{
		return $this->validate->getAction()->getUniqueKey();
	}
}