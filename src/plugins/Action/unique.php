<?php

use Razy\Action\Plugin;
use Razy\Collection;

/**
 * Check the value is not exists in database
 * Error code:  *_duplicated
 */
return new class extends Plugin {
	public function onValidate(mixed &$value, mixed $compare = null): bool
	{
		$parameters = [];
		$parameters[$this->getName()] = $value;
		$parameters[$this->getIDColumn()] = $this->getUniqueKey() ?? 0;

		$result = $this->getDB()->prepare()->from($this->getTableName())->where($this->getName() . '=?,' . $this->getIDColumn() . '!=?' . ($this->getMarkerColumn() ? ',!' . $this->getMarkerColumn() : ''))->lazy($parameters);

		if ($result) {
			$this->reject('duplicated');
			return false;
		}
		return true;
	}
};