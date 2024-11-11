<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Database;

use Razy\Error;

class Table
{
    private string $charset = 'utf8mb4';
    private string $collation = 'utf8mb4_general_ci';
    private array $columns = [];
    private ?Table $committed = null;
    private array $reordered = [];
    private array $groupIndexingList = [];

    /**
     * Table constructor.
     *
     * @param string $name
     * @param string $configSyntax
     *
     * @throws Error
     */
    public function __construct(private string $name, string $configSyntax = '')
    {
        $this->name = trim($this->name);
        $configSyntax = trim($configSyntax);
        if ($configSyntax) {
            $parameters = $this->parseSyntax($configSyntax);
            $this->configure($parameters);
        }
    }

    /**
     * Parse the config syntax.
     *
     * @param string $syntax
     *
     * @return array
     */
    private function parseSyntax(string $syntax): array
    {
        $parameters = [];
        $clips = preg_split('/(?:\\\\.|\((?:\\\\.(*SKIP)|[^()])*\)|(?<q>[\'"])(?:\\\\.(*SKIP)|(?!\k<q>).)*\k<q>)(*SKIP)(*FAIL)|\s*,\s*/', $syntax, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($clips as $clip) {
            if (preg_match('/^(\w+)(?:\(((?:\\.(*SKIP)|[^()])*)\))?/', $clip, $matches)) {
                if (!isset($parameters[$matches[1]])) {
                    $parameters[$matches[1]] = null;
                }

                if ($matches[2] ?? '') {
                    $parameters[$matches[1]] = [];
                    while (preg_match('/^(?:\A|,)(\w+|(\d+(?:\.\d+)?)|(?<q>[\'"])((?:\\\\.(*SKIP)|(?!\k<q>).)*)\k<q>)/', $matches[2], $extracted)) {
                        $parameters[$matches[1]][] = $extracted[4] ?? $extracted[1];
                        $matches[2] = substr($matches[2], strlen($extracted[0]));
                    }
                }
            }
        }

        return $parameters;
    }

    /**
     * Pass a set of parameter to config the column.
     *
     * @param array $parameters
     *
     * @return Table
     * @throws Error
     *
     */
    public function configure(array $parameters): Table
    {
        foreach (['charset', 'collation'] as $method) {
            $arguments = $parameters[$method] ?? null;
            if ('charset' == $method) {
                $this->setCharset((!$arguments || !is_scalar($arguments[0])) ? '' : $arguments[0]);
            } elseif ('collation' == $method) {
                $this->setCollation((!$arguments || !is_scalar($arguments[0])) ? '' : $arguments[0]);
            }
        }

        return $this;
    }

    /**
     * Import the table config to create the Table.
     *
     * @param string $syntax
     *
     * @return Table
     * @throws Error
     *
     */
    public static function Import(string $syntax): Table
    {
        $syntax = trim($syntax);
        if (preg_match('/(?<skip>\\\\.|\((?:\\\\.(*SKIP)|[^()])*\)|(?<q>[\'"])(?:\\\\.(*SKIP)|(?!\k<q>).)*\k<q>)(*SKIP)(*FAIL)|^(\w+|`(?:\\\\.(*SKIP)|[^`])*`)(?:=(.+?))?(?:\[(.+?)])?$/', $syntax, $matches)) {
            $tableName = trim($matches[3], '`');
            $table = new Table($tableName, $matches[4] ?? '');
            if ($matches[5] ?? '') {
                $clips = preg_split('/(?:\\\\.|\((?:\\\\.(*SKIP)|[^()])*\)|(?<q>[\'"])(?:\\\\.(*SKIP)|(?!\k<q>).)*\k<q>)(*SKIP)(*FAIL)|\s*:\s*/', $matches[5], -1, PREG_SPLIT_NO_EMPTY);
                foreach ($clips as $clip) {
                    $table->addColumn($clip);
                }
            }

            return $table;
        }

        throw new Error('Invalid configuration syntax.');
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
            $column = new Column($columnName, $matches[2] ?? '', $this);
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
     * Get the table name.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Reorder the column after the specified column.
     *
     * @param string $selected
     * @param string $dest
     *
     * @return $this
     * @throws Error
     */
    public function moveColumnAfter(string $selected, string $dest = ''): Table
    {
        $selected = trim($selected);
        $selectedColumn = null;
        foreach ($this->columns as $index => $column) {
            if ($column->getName() == $selected) {
                unset($this->columns[$index]);
                $selectedColumn = $column;

                break;
            }
        }

        if (!$selectedColumn) {
            throw new Error('The source column ' . $selected . ' does not exists in table.');
        }

        $dest = trim($dest);
        if (!$dest) {
            $this->columns = array_merge([$selectedColumn], $this->columns);
        } else {
            $destIndex = array_search($dest, array_keys($this->columns));
            $beginning = array_slice($this->columns, 0, $destIndex + 1, true);
            $ending = ($destIndex + 1 == count($this->columns)) ? [] : array_slice($this->columns, $destIndex + 1, true);
            $this->columns = array_merge($beginning, [$selectedColumn], $ending);
        }

        $this->reordered[$selectedColumn->getName()] = true;
        $this->validate();

        return $this;
    }

    /**
     * Update the column order setting.
     *
     * @return Table
     * @throws Error
     */
    public function validate(): Table
    {
        $columns = [];
        $previous = '';
        foreach ($this->columns as $column) {
            $column->insertAfter($previous);
        }
        $this->columns = $columns;

        return $this;
    }

    /**
     * @throws Error
     */
    public function __clone()
    {
        $columns = $this->columns;
        $this->columns = [];
        foreach ($columns as $column) {
            $this->columns[] = (clone $column)->bindTo($this);
        }
    }

    /**
     * Commit the update and generate the SQL statement.
     *
     * @param bool $alter
     * @return string
     * @throws Error
     */
    public function commit(bool $alter = false): string
    {
        if ($this->committed) {
            $syntax = 'ALTER TABLE `' . addslashes($this->committed->getName()) . '`';
            if ($this->name != $this->committed->getName()) {
                $syntax .= ' RENAME TO `' . addslashes($this->name) . '`';
            }

            if ($this->charset != $this->committed->getCharset() || $this->collation != $this->committed->getCollation()) {
                $syntax .= ' CONVERT TO CHARACTER';
                if ($this->charset != $this->committed->getCharset()) {
                    $syntax .= ' SET ' . $this->charset;
                }

                if ($this->collation != $this->committed->getCollation()) {
                    $syntax .= ' COLLATE ' . $this->collation;
                }
            }

            // Modify Column
            $modifyColumnSyntax = '';
            $addColumnSyntax = '';
            foreach ($this->columns as $column) {
                $orgColumn = $this->committed->getColumnByID($column->getID());
                if ($orgColumn) {
                    if (($alterSyntax = $column->alter($orgColumn, isset($this->reordered[$column->getName()]))) !== '') {
                        $modifyColumnSyntax .= ',' . $alterSyntax;
                    }
                } else {
                    // If the column id is not find in list, new column added
                    $addColumnSyntax .= ', ADD COLUMN ' . $column->getSyntax();
                }
            }

            $alterReferenceSyntax = '';
            $orgReference = [];
            foreach ($this->committed as $committedColumn) {
                $name = $committedColumn->getReferenceColumn() || $committedColumn->getName();
                $orgReference[$name] = $committedColumn;
            }

            foreach ($this->columns as $column) {
                $name = $column->getName();
                if (!isset($orgReference[$name])) {
                    $alterReferenceSyntax .= ', ADD ' . $column->getForeignKeySyntax();
                } else {
                    $syntax = $column->getForeignKeySyntax();
                    if ($orgReference[$name]->getForeignKeySyntax() !== $syntax) {
                        $alterReferenceSyntax .= ', DROP FOREIGN KEY `' . $name . '`';
                        $alterReferenceSyntax .= ', ADD ' . $column->getForeignKeySyntax();
                    }
                    unset($orgReference[$name]);
                }
            }

            if (count($orgReference)) {
                foreach ($orgReference as $column) {
                    $name = $column->getReferenceColumn() || $column->getName();
                    $alterReferenceSyntax .= ', DROP FOREIGN KEY `' . $name . '`';
                }
            }

            $syntax .= $addColumnSyntax . $modifyColumnSyntax . $alterReferenceSyntax . ';';
        } else {
            $syntax = $this->getSyntax();
        }

        $this->committed = clone $this;

        return $syntax;
    }

    /**
     * Get the table charset.
     *
     * @return string
     */
    public function getCharset(): string
    {
        return $this->charset;
    }

    /**
     * Set the table charset.
     *
     * @param string $charset
     *
     * @return $this
     * @throws Error
     */
    public function setCharset(string $charset): Table
    {
        $charset = trim($charset);
        if (!preg_match('/^\w+$/', $charset)) {
            throw new Error($charset . ' is not in a correct character set format.');
        }
        $this->charset = $charset;

        return $this;
    }

    /**
     * Get the table collation.
     *
     * @return string
     */
    public function getCollation(): string
    {
        return $this->collation;
    }

    /**
     * Set the table collation.
     *
     * @param string $collation
     *
     * @return $this
     * @throws Error
     */
    public function setCollation(string $collation): Table
    {
        $collation = trim($collation);
        if ($collation && !preg_match('/^\w+?_\w+$/', $collation)) {
            throw new Error($collation . ' is not in a correct collation format.');
        }

        $charset = strtok($collation, '_');
        if ($charset !== $this->charset) {
            $this->charset = $charset;
        }

        $this->collationUpdated = true;
        $this->collation = $collation;

        return $this;
    }

    /**
     * Get the column by the unique id.
     *
     * @param string $id
     *
     * @return null|Column
     */
    public function getColumnByID(string $id): ?Column
    {
        $id = trim($id);
        foreach ($this->columns as $column) {
            if ($column->getID() == $id) {
                return $column;
            }
        }

        return null;
    }

    /**
     * Generate the statement of the table create.
     *
     * @return string
     * @throws Error
     */
    public function getSyntax(): string
    {
        $autoColumn = null;
        $keySet = [
            'primary' => [],
            'index' => [],
            'unique' => [],
            'fulltext' => [],
            'spatial' => [],
        ];
        $referenceSet = [];

        $clips = [];
        foreach ($this->columns as $column) {
            $clips[] = $column->getSyntax();
            if ($column->isAuto()) {
                if ($autoColumn) {
                    throw new Error('The column ' . $column->getName() . ' cannot declare as auto increment that ' . $autoColumn->getName() . ' is already declared.');
                }
                $keySet['primary'][] = $column->getName();
                $autoColumn = $column;
            } elseif ($key = $column->getKey()) {
                if (array_key_exists($key, $keySet)) {
                    $keySet[$key][] = $column->getName();
                }
            }

            if ($column->hasReference()) {
                $referenceSet[$column->getName()] = $column;
            }
        }

        $syntax = 'CREATE TABLE ' . $this->name . ' (';

        // Primary key
        if (count($keySet['primary'])) {
            $clips[] = 'PRIMARY KEY(`' . implode('`, `', $keySet['primary']) . '`)';
        }
        unset($keySet['primary']);

        foreach ($keySet as $index => $columns) {
            foreach ($columns as $column) {
                $clips[] = strtoupper($index) . '(`' . $column . '`)';
            }
        }

        if (count($referenceSet)) {
            foreach ($referenceSet as $reference) {
                $clips[] = $reference->getForeignKeySyntax();
            }
        }

        if (count($this->groupIndexingList)) {
            foreach ($this->groupIndexingList as $keyName => $columns) {
                $clips[] = 'KEY `' . $keyName . '` (' . implode(', ', $columns) . ')';
            }
        }

        $syntax .= implode(', ', $clips) . ') ENGINE InnoDB CHARSET=' . $this->charset . ' COLLATE ' . $this->collation . ';';

        return $syntax;
    }

    /**
     * Export the table config.
     *
     * @return string
     */
    public function exportConfig(): string
    {
        $config = '`' . $this->name . '`';
        $parameters = [];
        $parameters[] = 'charset(' . $this->charset . ')';
        $parameters[] = 'collation(' . $this->collation . ')';
        if (!empty($parameters)) {
            $config .= '=' . implode(',', $parameters);
        }

        if (count($this->columns)) {
            $config .= '[';
            $columnsSyntax = [];
            foreach ($this->columns as $column) {
                $columnsSyntax[] = $column->exportConfig();
            }
            $config .= implode(':', $columnsSyntax) . ']';
        }

        if (count($this->groupIndexingList)) {
            $config .= '[';
            $groupIndexingSyntax = [];
            foreach ($this->groupIndexingList as $indexName => $columns) {
                $groupIndexingSyntax[] = $indexName . '=' . implode(',', $columns);
            }
            $config .= implode(':', $groupIndexingSyntax) . ']';
        }

        return $config;
    }

    /**
     * Get the column by the name.
     *
     * @param string $columnName
     *
     * @return null|Column
     */
    public function getColumn(string $columnName): ?Column
    {
        $columnName = trim($columnName);
        foreach ($this->columns as $column) {
            if ($column->getName() == $columnName) {
                return $column;
            }
        }

        return null;
    }

    /**
     * Insert the Column entity into the table.
     *
     * @param Column $newColumn
     *
     * @return Table
     * @throws Error
     */
    public function insertColumn(Column $newColumn): Table
    {
        foreach ($this->columns as $column) {
            if ($column->getID() == $newColumn->getID()) {
                return $this;
            }
        }
        $this->columns[] = $newColumn;
        $this->validate();

        return $this;
    }

    /**
     * Remove the column by the name.
     *
     * @param string $columnName
     *
     * @return $this
     */
    public function removeColumn(string $columnName): Table
    {
        $columnName = trim($columnName);
        foreach ($this->columns as $index => $column) {
            if ($column->getName() == $columnName) {
                unset($this->columns[$index]);

                return $this;
            }
        }

        return $this;
    }

    /**
     * Remove the column by the unique id.
     *
     * @param string $id
     *
     * @return $this
     */
    public function removeColumnById(string $id): Table
    {
        $id = trim($id);
        foreach ($this->columns as $index => $column) {
            if ($column->getID() == $id) {
                unset($this->columns[$index]);

                return $this;
            }
        }

        return $this;
    }

    /**
     * @param array $columns
     * @param string $indexKey
     * @return $this
     */
    public function groupIndexing(array $columns, string $indexKey = ''): static
    {
        $keyName = 'fk';
        foreach ($columns as $index => &$column) {
            if (!preg_match(Statement::REGEX_COLUMN, $column)) {
                unset($columns[$index]);
            } else {
                $column = trim($column, '`');
                if (!$indexKey) {
                    $keyName .= '_' . ((strlen($column) > 4) ? substr($column, 0, 4) : $column);
                }

                $column = '`' . $column . '`';
            }
        }

        if (count($columns)) {
            $this->groupIndexingList[$indexKey ?: $keyName] = $columns;
        }
        return $this;
    }

    private array $alterColumn = [
        'add' => [],
        'remove' => [],
        'modify' => [],
    ];

    /**
     * Add a new column.
     *
     * @param string $columnSyntax
     * @param string $after
     *
     * @return Column
     * @throws Error
     */
    public function alterAddColumn(string $columnSyntax, string $after = ''): Column
    {
        $columnSyntax = trim($columnSyntax);
        if (preg_match('/^(\w+|`(?:\\\\.(*SKIP)|[^`])*`)(?:=(.+))?/', $columnSyntax, $matches)) {
            $columnName = trim($matches[1], '`');
            foreach ($this->alterColumn['add'] as $column) {
                if ($column->getName() == $columnSyntax) {
                    throw new Error('The column `' . $columnName . '` already exists.');
                }
            }
            $column = new Column($columnName, $matches[2] ?? '', $this);
            $after = trim($after);
            $this->alterColumn['add'][] = ['entity' => $column, 'after' => $after];

            return $column;
        }

        throw new Error('The column name or the Column Syntax is not valid.');
    }

    /**
     * Add a new column.
     *
     * @param string $columnName
     * @return Table
     * @throws Error
     */
    public function alterRemoveColumn(string $columnName): static
    {
        $columnName = trim($columnName);
        foreach ($this->alterColumn['remove'] as $column) {
            if ($column->getName() == $columnName) {
                throw new Error('The column `' . $columnName . '` already exists.');
            }
        }
        $this->alterColumn['remove'][] = $columnName;

        return $this;
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
    public function alterModifyColumn(string $columnSyntax, string $after = ''): Column
    {
        $columnSyntax = trim($columnSyntax);
        if (preg_match('/^(\w+|`(?:\\\\.(*SKIP)|[^`])*`)(?:=(.+))?/', $columnSyntax, $matches)) {
            $columnName = trim($matches[1], '`');
            foreach ($this->alterColumn['add'] as $column) {
                if ($column->getName() == $columnSyntax) {
                    throw new Error('The column `' . $columnName . '` already exists.');
                }
            }
            $column = new Column($columnName, $matches[2] ?? '', $this);
            $after = trim($after);
            $this->alterColumn['modify'][] = ['entity' => $column, 'after' => $after];

            return $column;
        }

        throw new Error('The column name or the Column Syntax is not valid.');
    }

    /**
     * @return string
     */
    public function alter(): string
    {
        $syntax = [];
        foreach ($this->alterColumn['add'] as $column) {
            $syntax[] = 'ADD COLUMN ' . $column['entity']->getSyntax();
        }
        foreach ($this->alterColumn['modify'] as $column) {
            $syntax[] = 'MODIFY COLUMN ' . $column['entity']->getSyntax();
        }
        foreach ($this->alterColumn['remove'] as $columnName) {
            $syntax[] = 'DROP COLUMN `' . addslashes($columnName) . '`';
        }

        return 'ALTER TABLE `' . addslashes($this->getName()) . '`' . implode(', ', $syntax) . ';';
    }
}