<?php

use Razy\Action\Plugin;
use Razy\Database\Statement;

/**
 * Check the value is not exists in database
 * Error code:  *_duplicated
 */
return new class extends Plugin {
	private ?Statement $statement = null;
	private string $column = '';

	public function setup(string $tableName, string $column = '', string $markerColumn = '')
	{
		$this->column = $column ?: $this->getName();
		return ($this->statement = $this->getDB()->prepare()->from($tableName)->where($column . '=?' . ($markerColumn ? ',!' . $markerColumn : '')));
	}

	public function onValidate(mixed &$value, mixed $compare = null): bool
	{
		if ($value) {
			$parameters = [];
			$parameters[$this->column] = $value;

			if (!($result = $this->statement->lazy($parameters))) {
				$this->reject('not_found');
				$value = null;
				return false;
			}
			$this->setStorage($result);
			return true;
		}

		$value = null;
		return true;
	}
};