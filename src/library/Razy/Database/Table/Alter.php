<?php

namespace Razy\Database\Table;

use Razy\Database\Column;
use Razy\Error;

class Alter
{
    private string $name = '';

    public function __construct(private readonly Table $table)
    {
    }

    /**
     * Add a new column.
     *
     * @param string $columnSyntax
     * @param string $after
     *
     * @return Column
     * @throws Error
     */
    public function addColumn(string $columnSyntax, string $after = ''): Column
    {
        $columnSyntax = trim($columnSyntax);
        if (preg_match('/^(\w+|`(?:\\\\.(*SKIP)|[^`])*`)(?:=(.+))?/', $columnSyntax, $matches)) {
            $columnName = trim($matches[1], '`');
            foreach ($this->columns as $column) {
                if ($column->getName() == $columnSyntax) {
                    throw new Error('The column `' . $columnName . '` already exists.');
                }
            }
            $column = new Column($columnName, $matches[2] ?? '', $this->table);
            $after = trim($after);
            $this->columns[] = $column;
            if ($after) {
                $this->moveColumnAfter($columnName, $after);
                $this->validate();
            } else {
                $lastColumn = end($this->columns);
                if ($lastColumn) {
                    $column->insertAfter($lastColumn->getName());
                }
            }

            return $column;
        }

        throw new Error('The column name or the Column Syntax is not valid.');
    }

    /**
     * @param string $name
     * @return $this
     */
    public function rename(string $name): static
    {
        $this->name = trim($name);
        return $this;
    }

    public function removeColumn()
    {

    }

    public function updateColumn()
    {

    }
}