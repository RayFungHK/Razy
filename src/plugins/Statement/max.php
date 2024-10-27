<?php

use Razy\Database\Statement;
use Razy\Database\Statement\Plugin;
use function Razy\guid;

return new class() extends Plugin {
	private string $tableName = '';

	private array $grouping = [];

	private string $compareColumn = '';

	private ?Closure $onBuildClosure = null;

	/**
	 * @param string $tableName
	 * @return void
	 */
	public function build(string $tableName): void
	{
		$aliasA = 's_' . guid(1);
		$aliasB = 'c_' . guid(1);

		$tableName = ($this->tableName) ?: $tableName;

		$statement = $this->statement
			->select($aliasA . '.*')
			->from($aliasA . '.' . $tableName . '-' . $aliasB . '.' . $tableName . '_latest' . '[' . implode(',', $this->grouping) . ',' . $this->compareColumn . ']');

		$subQuery = $statement->alias($aliasB);
		$subQuery->select(implode(',', $this->grouping) . ', MAX(' . $this->compareColumn . ') as ' . $this->compareColumn)->from($tableName)->group(implode(',', $this->grouping));

		if ($this->onBuildClosure) {
			call_user_func($this->onBuildClosure, $subQuery);
		}
	}

	/**
	 * @param Closure $closure
	 * @return Plugin
	 */
	public function onBuild(Closure $closure): self
	{
		$this->onBuildClosure = $closure;
		return $this;
	}

	/**
	 * @param string $tableName
	 * @return Plugin
	 */
	public function setTableName(string $tableName): self
	{
		$this->tableName = $tableName;
		return $this;
	}

	/**
	 * @param string $column
	 * @return Plugin
	 */
	public function setCompareColumn(string $column): self
	{
		$this->compareColumn = $column;
		return $this;
	}

	/**
	 * @param array $columns
	 * @return Plugin
	 */
	public function setGrouping(array $columns): self
	{
		$this->grouping = [];
		foreach ($columns as $column) {
			if (preg_match(Statement::REGEX_COLUMN, $column)) {
				$this->grouping[] = $column;
			}
		}
		return $this;
	}
};