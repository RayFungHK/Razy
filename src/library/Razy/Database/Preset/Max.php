<?php

namespace Razy\Database\Preset;

use Razy\Database\Preset;
use Razy\Database\Statement;
use Razy\Error;

class Max extends Preset
{
    private string $binding = '';

    private string $valueColumn = '';

    private string $groupColumn = '';

    public function init(array $params = []): Preset
    {
        [$this->binding, $this->valueColumn, $this->groupColumn] = $params;
        return $this;
    }

    /**
     * @param string $binding
     *
     * @return $this
     */
    public function setBinding(string $binding): Max
    {
        $this->binding = $binding;
        return $this;
    }

    /**
     * @param string $groupColumn
     *
     * @return Max
     */
    public function setGroupColumn(string $groupColumn): Max
    {
        $this->groupColumn = $groupColumn;
        return $this;
    }

    /**
     * @param string $valueColumn
     *
     * @return Max
     */
    public function setValueColumn(string $valueColumn): Max
    {
        $this->valueColumn = $valueColumn;
        return $this;
    }

    /**
     * @return Statement
     * @throws Error
     */
    public function getStatement(): Statement
    {
        if (!$this->binding || !$this->valueColumn || !$this->groupColumn) {
            throw new Error('Missing binding, value or group parameter in `max` preset syntax.');
        }

        $statement = $this->statement->from('a.' . $this->table . '-b.' . $this->table . '[' . $this->binding . ']')->alias('b')->select($this->binding . ', MAX(' . $this->valueColumn . ') as ' . $this->valueColumn)->from($this->table)->group($this->groupColumn);
        if ($this->whereSyntax) {
            $statement->where($this->whereSyntax);
        }
        return $this->statement;
    }
}