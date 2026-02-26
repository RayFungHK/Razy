<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * @package Razy
 *
 * @license MIT
 */

namespace Razy\Database;

use Razy\Database\Table\ColumnHelper;
use Razy\Database\Table\TableHelper;
use Razy\Exception\DatabaseException;

/**
 * Class Table.
 *
 * Represents a database table definition. Manages column definitions, charset,
 * collation, indexing, and foreign key references. Supports generating CREATE TABLE
 * and ALTER TABLE SQL statements, importing/exporting table configurations via
 * a compact syntax string, and tracking schema changes for migrations.
 *
 * @package Razy
 *
 * @license MIT
 */
class Table
{
    /** @var string Table character set (e.g., 'utf8mb4') */
    private string $charset = 'utf8mb4';

    /** @var string Table collation (e.g., 'utf8mb4_general_ci') */
    private string $collation = 'utf8mb4_general_ci';

    /** @var Column[] Ordered array of Column instances defining this table */
    private array $columns = [];

    /** @var Table|null Snapshot of the table at last commit, used for generating ALTER statements */
    private ?Table $committed = null;

    /** @var array<string, bool> Tracks which columns have been reordered since last commit */
    private array $reordered = [];

    /** @var array<string, string[]> Composite index definitions keyed by index name */
    private array $groupIndexingList = [];

    /** @var array{add: array, remove: array, modify: array} Pending ALTER TABLE column operations */
    private array $alterColumn = [
        'add' => [],
        'remove' => [],
        'modify' => [],
    ];

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
        $this->name = \trim($this->name);
        $configSyntax = \trim($configSyntax);
        if ($configSyntax) {
            $parameters = $this->parseSyntax($configSyntax);
            $this->configure($parameters);
        }
    }

    /**
     * Deep clone handler. Creates independent copies of all Column instances
     * and re-binds them to this cloned table.
     *
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
     * Import a table definition from a compact configuration syntax string.
     * Format: tableName=option1,option2[col1=config1:col2=config2].
     *
     * @param string $syntax The table configuration syntax string
     *
     * @return Table The created Table instance
     *
     * @throws Error If the syntax is invalid
     */
    public static function import(string $syntax): self
    {
        $syntax = \trim($syntax);
        // Match table name (plain or backtick-quoted), optional config, optional columns block
        if (\preg_match('/(?<skip>\\\.|\((?:\\\.(*SKIP)|[^()])*\)|(?<q>[\'"])(?:\\\.(*SKIP)|(?!\k<q>).)*\k<q>)(*SKIP)(*FAIL)|^(\w+|`(?:\\\.(*SKIP)|[^`])*`)(?:=(.+?))?(?:\[(.+?)])?$/', $syntax, $matches)) {
            $tableName = \trim($matches[3], '`');
            $table = new self($tableName, $matches[4] ?? '');

            // Parse column definitions separated by colons
            if ($matches[5] ?? '') {
                $clips = \preg_split('/(?:\\\.|\((?:\\\.(*SKIP)|[^()])*\)|(?<q>[\'"])(?:\\\.(*SKIP)|(?!\k<q>).)*\k<q>)(*SKIP)(*FAIL)|\s*:\s*/', $matches[5], -1, PREG_SPLIT_NO_EMPTY);
                foreach ($clips as $clip) {
                    $table->addColumn($clip);
                }
            }

            return $table;
        }

        throw new DatabaseException('Invalid configuration syntax.');
    }

    /**
     * Configure the table properties from a parsed parameter array.
     * Currently supports 'charset' and 'collation' settings.
     *
     * @param array $parameters Parsed configuration parameters
     *
     * @return Table
     *
     * @throws Error
     */
    public function configure(array $parameters): self
    {
        // Apply charset and collation from the parameters, if provided
        foreach (['charset', 'collation'] as $method) {
            $arguments = $parameters[$method] ?? null;
            if ('charset' == $method) {
                $this->setCharset((!$arguments || !\is_scalar($arguments[0])) ? '' : $arguments[0]);
            } elseif ('collation' == $method) {
                $this->setCollation((!$arguments || !\is_scalar($arguments[0])) ? '' : $arguments[0]);
            }
        }

        return $this;
    }

    /**
     * Add a new column.
     *
     * @param string $columnSyntax
     * @param string $after
     *
     * @return Column
     *
     * @throws Error
     */
    public function addColumn(string $columnSyntax, string $after = ''): Column
    {
        $columnSyntax = \trim($columnSyntax);
        if (\preg_match('/^(\w+|`(?:\\\.(*SKIP)|[^`])*`)(?:=(.+))?/', $columnSyntax, $matches)) {
            $columnName = \trim($matches[1], '`');
            foreach ($this->columns as $column) {
                if ($column->getName() == $columnSyntax) {
                    throw new DatabaseException('The column `' . $columnName . '` already exists.');
                }
            }
            $column = new Column($columnName, $matches[2] ?? '', $this);
            $after = \trim($after);
            $this->columns[] = $column;
            if ($after) {
                $this->moveColumnAfter($columnName, $after);
                $this->validate();
            } else {
                $lastColumn = \end($this->columns);
                if ($lastColumn) {
                    $column->insertAfter($lastColumn->getName());
                }
            }

            return $column;
        }

        throw new DatabaseException('The column name or the Column Syntax is not valid.');
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
     * Create a TableHelper instance for this table.
     * Use this to build ALTER TABLE statements with a fluent interface.
     *
     * @return TableHelper
     */
    public function createHelper(): TableHelper
    {
        return new TableHelper($this);
    }

    /**
     * Create a ColumnHelper instance for modifying a specific column.
     *
     * @param string $columnName The column to alter
     *
     * @return ColumnHelper
     */
    public function columnHelper(string $columnName): ColumnHelper
    {
        return new ColumnHelper($this, $columnName);
    }

    /**
     * Reorder the column after the specified column.
     *
     * @param string $selected
     * @param string $dest
     *
     * @return $this
     *
     * @throws Error
     */
    public function moveColumnAfter(string $selected, string $dest = ''): self
    {
        $selected = \trim($selected);
        $selectedColumn = null;
        foreach ($this->columns as $index => $column) {
            if ($column->getName() == $selected) {
                unset($this->columns[$index]);
                $selectedColumn = $column;

                break;
            }
        }

        if (!$selectedColumn) {
            throw new DatabaseException('The source column ' . $selected . ' does not exists in table.');
        }

        $dest = \trim($dest);
        if (!$dest) {
            $this->columns = \array_merge([$selectedColumn], $this->columns);
        } else {
            $destIndex = \array_search($dest, \array_keys($this->columns));
            $beginning = \array_slice($this->columns, 0, $destIndex + 1, true);
            $ending = ($destIndex + 1 == \count($this->columns)) ? [] : \array_slice($this->columns, $destIndex + 1, true);
            $this->columns = \array_merge($beginning, [$selectedColumn], $ending);
        }

        $this->reordered[$selectedColumn->getName()] = true;
        $this->validate();

        return $this;
    }

    /**
     * Update column ordering metadata. Ensures each column's 'insert_after'
     * property is set correctly based on the current column order.
     *
     * @return Table
     *
     * @throws Error
     */
    public function validate(): self
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
     * Commit the current table definition and generate the SQL statement.
     * If a previous commit exists, generates ALTER TABLE; otherwise CREATE TABLE.
     * After committing, stores a snapshot for future diff-based ALTER generation.
     *
     * @param bool $alter Reserved for future use
     *
     * @return string The SQL statement (CREATE TABLE or ALTER TABLE)
     *
     * @throws Error
     */
    public function commit(bool $alter = false): string
    {
        if ($this->committed) {
            $syntax = 'ALTER TABLE `' . \addslashes($this->committed->getName()) . '`';
            if ($this->name != $this->committed->getName()) {
                $syntax .= ' RENAME TO `' . \addslashes($this->name) . '`';
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

            // Compare current columns against the committed snapshot to detect modifications and additions
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

            // Build a map of original foreign key references for diff comparison
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

            if (\count($orgReference)) {
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
     *
     * @throws Error
     */
    public function setCharset(string $charset): self
    {
        $charset = \trim($charset);
        if (!\preg_match('/^\w+$/', $charset)) {
            throw new DatabaseException($charset . ' is not in a correct character set format.');
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
     *
     * @throws Error
     */
    public function setCollation(string $collation): self
    {
        $collation = \trim($collation);
        if ($collation && !\preg_match('/^\w+?_\w+$/', $collation)) {
            throw new DatabaseException($collation . ' is not in a correct collation format.');
        }

        $charset = \strtok($collation, '_');
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
     * @return Column|null
     */
    public function getColumnByID(string $id): ?Column
    {
        $id = \trim($id);
        foreach ($this->columns as $column) {
            if ($column->getID() == $id) {
                return $column;
            }
        }

        return null;
    }

    /**
     * Generate the full CREATE TABLE SQL statement, including columns,
     * primary/index keys, foreign key constraints, and table options.
     *
     * @return string The CREATE TABLE statement
     *
     * @throws Error If duplicate auto-increment columns are found
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

            // Track auto-increment columns for PRIMARY KEY (only one allowed)
            if ($column->isAuto()) {
                if ($autoColumn) {
                    throw new DatabaseException('The column ' . $column->getName() . ' cannot declare as auto increment that ' . $autoColumn->getName() . ' is already declared.');
                }
                $keySet['primary'][] = $column->getName();
                $autoColumn = $column;
            } elseif ($key = $column->getKey()) {
                if (\array_key_exists($key, $keySet)) {
                    $keySet[$key][] = $column->getName();
                }
            }

            if ($column->hasReference()) {
                $referenceSet[$column->getName()] = $column;
            }
        }

        $syntax = 'CREATE TABLE IF NOT EXISTS ' . $this->name . ' (';

        // Add PRIMARY KEY constraint if any primary columns exist
        if (\count($keySet['primary'])) {
            $clips[] = 'PRIMARY KEY(`' . \implode('`, `', $keySet['primary']) . '`)';
        }
        unset($keySet['primary']);

        // Add INDEX, UNIQUE, FULLTEXT, and SPATIAL keys
        foreach ($keySet as $index => $columns) {
            foreach ($columns as $column) {
                $clips[] = \strtoupper($index) . '(`' . $column . '`)';
            }
        }

        if (\count($referenceSet)) {
            foreach ($referenceSet as $reference) {
                $clips[] = $reference->getForeignKeySyntax();
            }
        }

        if (\count($this->groupIndexingList)) {
            foreach ($this->groupIndexingList as $keyName => $columns) {
                $clips[] = 'KEY `' . $keyName . '` (' . \implode(', ', $columns) . ')';
            }
        }

        $syntax .= \implode(', ', $clips) . ') ENGINE InnoDB CHARSET=' . $this->charset . ' COLLATE ' . $this->collation . ';';

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
            $config .= '=' . \implode(',', $parameters);
        }

        if (\count($this->columns)) {
            $config .= '[';
            $columnsSyntax = [];
            foreach ($this->columns as $column) {
                $columnsSyntax[] = $column->exportConfig();
            }
            $config .= \implode(':', $columnsSyntax) . ']';
        }

        if (\count($this->groupIndexingList)) {
            $config .= '[';
            $groupIndexingSyntax = [];
            foreach ($this->groupIndexingList as $indexName => $columns) {
                $groupIndexingSyntax[] = $indexName . '=' . \implode(',', $columns);
            }
            $config .= \implode(':', $groupIndexingSyntax) . ']';
        }

        return $config;
    }

    /**
     * Get the column by the name.
     *
     * @param string $columnName
     *
     * @return Column|null
     */
    public function getColumn(string $columnName): ?Column
    {
        $columnName = \trim($columnName);
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
     *
     * @throws Error
     */
    public function insertColumn(Column $newColumn): self
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
    public function removeColumn(string $columnName): self
    {
        $columnName = \trim($columnName);
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
    public function removeColumnById(string $id): self
    {
        $id = \trim($id);
        foreach ($this->columns as $index => $column) {
            if ($column->getID() == $id) {
                unset($this->columns[$index]);

                return $this;
            }
        }

        return $this;
    }

    /**
     * Define a composite (multi-column) index on the table.
     *
     * @param array $columns Column names to include in the index
     * @param string $indexKey Optional custom index name (auto-generated if empty)
     *
     * @return $this
     */
    public function groupIndexing(array $columns, string $indexKey = ''): static
    {
        $keyName = 'fk';
        foreach ($columns as $index => &$column) {
            // Validate column name format and strip backticks
            if (!\preg_match(Statement::REGEX_COLUMN, $column)) {
                unset($columns[$index]);
            } else {
                $column = \trim($column, '`');
                if (!$indexKey) {
                    $keyName .= '_' . ((\strlen($column) > 4) ? \substr($column, 0, 4) : $column);
                }

                $column = '`' . $column . '`';
            }
        }

        if (\count($columns)) {
            $this->groupIndexingList[$indexKey ?: $keyName] = $columns;
        }
        return $this;
    }

    /**
     * Queue a column addition for a batch ALTER TABLE operation.
     *
     * @param string $columnSyntax Column definition syntax
     * @param string $after Column name to insert after (empty for end)
     *
     * @return Column The created Column instance
     *
     * @throws Error
     */
    public function alterAddColumn(string $columnSyntax, string $after = ''): Column
    {
        $columnSyntax = \trim($columnSyntax);
        if (\preg_match('/^(\w+|`(?:\\\.(*SKIP)|[^`])*`)(?:=(.+))?/', $columnSyntax, $matches)) {
            $columnName = \trim($matches[1], '`');
            foreach ($this->alterColumn['add'] as $column) {
                if ($column->getName() == $columnSyntax) {
                    throw new DatabaseException('The column `' . $columnName . '` already exists.');
                }
            }
            $column = new Column($columnName, $matches[2] ?? '', $this);
            $after = \trim($after);
            $this->alterColumn['add'][] = ['entity' => $column, 'after' => $after];

            return $column;
        }

        throw new DatabaseException('The column name or the Column Syntax is not valid.');
    }

    /**
     * Queue a column removal for a batch ALTER TABLE operation.
     *
     * @param string $columnName Column name to remove
     *
     * @return Table
     *
     * @throws Error
     */
    public function alterRemoveColumn(string $columnName): static
    {
        $columnName = \trim($columnName);
        foreach ($this->alterColumn['remove'] as $column) {
            if ($column->getName() == $columnName) {
                throw new DatabaseException('The column `' . $columnName . '` already exists.');
            }
        }
        $this->alterColumn['remove'][] = $columnName;

        return $this;
    }

    /**
     * Queue a column modification for a batch ALTER TABLE operation.
     *
     * @param string $columnSyntax Column definition syntax with the new configuration
     * @param string $after Column name to position after (empty to keep position)
     *
     * @return Column The created Column instance
     *
     * @throws Error
     */
    public function alterModifyColumn(string $columnSyntax, string $after = ''): Column
    {
        $columnSyntax = \trim($columnSyntax);
        if (\preg_match('/^(\w+|`(?:\\\.(*SKIP)|[^`])*`)(?:=(.+))?/', $columnSyntax, $matches)) {
            $columnName = \trim($matches[1], '`');
            foreach ($this->alterColumn['add'] as $column) {
                if ($column->getName() == $columnSyntax) {
                    throw new DatabaseException('The column `' . $columnName . '` already exists.');
                }
            }
            $column = new Column($columnName, $matches[2] ?? '', $this);
            $after = \trim($after);
            $this->alterColumn['modify'][] = ['entity' => $column, 'after' => $after];

            return $column;
        }

        throw new DatabaseException('The column name or the Column Syntax is not valid.');
    }

    /**
     * Generate the ALTER TABLE SQL statement from all queued column operations
     * (add, modify, remove).
     *
     * @return string The complete ALTER TABLE statement
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
            $syntax[] = 'DROP COLUMN `' . \addslashes($columnName) . '`';
        }

        return 'ALTER TABLE `' . \addslashes($this->getName()) . '`' . \implode(', ', $syntax) . ';';
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

        // Split syntax by commas, respecting parenthesized groups and quoted strings
        $clips = \preg_split('/(?:\\\.|\((?:\\\.(*SKIP)|[^()])*\)|(?<q>[\'"])(?:\\\.(*SKIP)|(?!\k<q>).)*\k<q>)(*SKIP)(*FAIL)|\s*,\s*/', $syntax, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($clips as $clip) {
            if (\preg_match('/^(\w+)(?:\(((?:\.(*SKIP)|[^()])*)\))?/', $clip, $matches)) {
                if (!isset($parameters[$matches[1]])) {
                    $parameters[$matches[1]] = null;
                }

                if ($matches[2] ?? '') {
                    $parameters[$matches[1]] = [];
                    while (\preg_match('/^(?:\A|,)(\w+|(\d+(?:\.\d+)?)|(?<q>[\'"])((?:\\\.(*SKIP)|(?!\k<q>).)*)\k<q>)/', $matches[2], $extracted)) {
                        $parameters[$matches[1]][] = $extracted[4] ?? $extracted[1];
                        $matches[2] = \substr($matches[2], \strlen($extracted[0]));
                    }
                }
            }
        }

        return $parameters;
    }
}
