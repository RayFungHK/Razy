<?php

use Razy\Action\Plugin;

/**
 * Checkbox result as bool
 */
return new class extends Plugin {
	private string $table = '';
	private string $column = '';
	private string $marker = '';

	public function setup(string $table, string $column, string $marker = ''): self
	{
		$this->table = $table;
		$this->column = $column;
		$this->marker = $marker;

		return $this;
	}

	public function onValidate(mixed &$value, mixed $compare = null): bool
	{
		if ($this->table && $this->column) {
			$parameters = [];
			$parameters[$this->column] = $value;
			$list = $this->getDB()->prepare()->from($this->table)->where($this->column . '|=?' . ($this->marker ? ',!' . $this->marker : ''))->lazyGroup($parameters, $this->column);
			$value = array_keys($list);
		}
		return true;
	}
};