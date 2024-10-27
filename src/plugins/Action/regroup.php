<?php

use Razy\Action\Plugin;

/**
 * Checkbox result as bool
 */
return new class extends Plugin {
	private string $table = '';
	private string $column = '';
    private string $marker = '';
    private string $kvpValue = '';

	public function setup(string $table, string $column, string $marker = '', string $kvpValue = ''): self
	{
		$this->table = $table;
		$this->column = $column;
        $this->marker = $marker;
        $this->kvpValue = trim($kvpValue);

		return $this;
	}

	public function onValidate(mixed &$value, mixed $compare = null): bool
	{
		if ($this->table && $this->column) {
			$parameters = [];
			$parameters[$this->column] = $value;
			$statement = $this->getDB()->prepare()->from($this->table)->where($this->column . '|=?' . ($this->marker ? ',!' . $this->marker : ''));
            $list = ($this->kvpValue) ? $statement->lazyKeyValuePair($this->column, $this->kvpValue, $parameters) : $statement->lazyGroup($parameters, $this->column);
			$value = ($this->kvpValue) ? $list : array_keys($list);
		}
		return true;
	}
};