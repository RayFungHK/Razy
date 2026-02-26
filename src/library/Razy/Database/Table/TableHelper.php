<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 *
 * @license MIT
 */

namespace Razy\Database\Table;

use Razy\Database\Column;
use Razy\Database\Table;
use Razy\Exception\DatabaseException;

/**
 * Class TableHelper.
 *
 * Fluent builder for generating ALTER TABLE SQL statements. Supports a comprehensive
 * range of table modification operations including adding, modifying, dropping, and
 * renaming columns; managing indexes (INDEX, UNIQUE, FULLTEXT, SPATIAL, PRIMARY KEY);
 * adding and dropping foreign key constraints; and changing table-level options such
 * as charset, collation, engine, and comments. Can output a single combined ALTER
 * statement via getSyntax() or individual statements via getSyntaxArray().
 *
 *
 * @license MIT
 */
class TableHelper
{
    /** @var string New table name for rename operations (empty if not renaming) */
    private string $newTableName = '';

    /** @var string New character set for the table (empty to keep unchanged) */
    private string $newCharset = '';

    /** @var string New collation for the table (empty to keep unchanged) */
    private string $newCollation = '';

    /** @var string New storage engine, e.g., InnoDB, MyISAM (empty to keep unchanged) */
    private string $newEngine = '';

    /** @var string New table comment (empty to keep unchanged) */
    private string $newComment = '';

    /** @var array<string, array{column: Column, position: string, after: string}> */
    private array $addColumns = [];

    /** @var array<string, array{column: Column, oldName: string, position: string, after: string}> */
    private array $modifyColumns = [];

    /** @var string[] */
    private array $dropColumns = [];

    /** @var array<string, array{type: string, columns: string[], name: string}> */
    private array $addIndexes = [];

    /** @var string[] */
    private array $dropIndexes = [];

    /** @var array<string, array{column: string, refTable: string, refColumn: string, onDelete: string, onUpdate: string}> */
    private array $addForeignKeys = [];

    /** @var string[] */
    private array $dropForeignKeys = [];

    /** @var array<string, string> */
    private array $renameColumns = [];

    /**
     * TableHelper constructor.
     *
     * @param Table $table The table entity to alter
     */
    public function __construct(private readonly Table $table)
    {
    }

    /**
     * Convert to string (returns SQL syntax).
     *
     * @return string
     *
     * @throws Error
     */
    public function __toString(): string
    {
        return $this->getSyntax();
    }

    /**
     * Rename the table.
     *
     * @param string $newName The new table name
     *
     * @return $this
     */
    public function rename(string $newName): static
    {
        $this->newTableName = \trim($newName);
        return $this;
    }

    /**
     * Change the table charset.
     *
     * @param string $charset The new charset
     *
     * @return $this
     */
    public function charset(string $charset): static
    {
        $this->newCharset = \trim($charset);
        return $this;
    }

    /**
     * Change the table collation.
     *
     * @param string $collation The new collation
     *
     * @return $this
     */
    public function collation(string $collation): static
    {
        $this->newCollation = \trim($collation);
        return $this;
    }

    /**
     * Change the table engine.
     *
     * @param string $engine The new engine (InnoDB, MyISAM, etc.)
     *
     * @return $this
     */
    public function engine(string $engine): static
    {
        $this->newEngine = \trim($engine);
        return $this;
    }

    /**
     * Set or change the table comment.
     *
     * @param string $comment The table comment
     *
     * @return $this
     */
    public function comment(string $comment): static
    {
        $this->newComment = $comment;
        return $this;
    }

    /**
     * Add a new column to the table.
     *
     * @param string $columnSyntax Column definition syntax (e.g., "name=type(text),nullable")
     * @param string $position Position: 'FIRST' or 'AFTER column_name' or empty for end
     *
     * @return Column The created column for further configuration
     *
     * @throws Error
     */
    public function addColumn(string $columnSyntax, string $position = ''): Column
    {
        $columnSyntax = \trim($columnSyntax);
        if (!\preg_match('/^(\w+|`(?:\\\.(*SKIP)|[^`])*`)(?:=(.+))?/', $columnSyntax, $matches)) {
            throw new DatabaseException('Invalid column syntax: ' . $columnSyntax);
        }

        $columnName = \trim($matches[1], '`');

        // Check if column already queued for addition
        if (isset($this->addColumns[$columnName])) {
            throw new DatabaseException('Column `' . $columnName . '` is already queued for addition.');
        }

        $column = new Column($columnName, $matches[2] ?? '', $this->table);
        $position = \strtoupper(\trim($position));
        $after = '';

        if (\str_starts_with($position, 'AFTER ')) {
            $after = \trim(\substr($position, 6), '` ');
            $position = 'AFTER';
        } elseif ($position !== 'FIRST') {
            $position = '';
        }

        $this->addColumns[$columnName] = [
            'column' => $column,
            'position' => $position,
            'after' => $after,
        ];

        return $column;
    }

    /**
     * Modify an existing column.
     *
     * @param string $columnName The column name to modify
     * @param string $newSyntax New column definition syntax
     * @param string $position Optional new position
     *
     * @return Column The column for further configuration
     *
     * @throws Error
     */
    public function modifyColumn(string $columnName, string $newSyntax = '', string $position = ''): Column
    {
        $columnName = \trim($columnName, '` ');
        if (!$columnName) {
            throw new DatabaseException('Column name is required.');
        }

        // Parse new syntax if provided
        $configSyntax = '';
        if ($newSyntax) {
            if (\preg_match('/^(\w+|`(?:\\\.(*SKIP)|[^`])*`)(?:=(.+))?/', $newSyntax, $matches)) {
                $configSyntax = $matches[2] ?? '';
            } else {
                $configSyntax = $newSyntax;
            }
        }

        $column = new Column($columnName, $configSyntax, $this->table);
        $position = \strtoupper(\trim($position));
        $after = '';

        if (\str_starts_with($position, 'AFTER ')) {
            $after = \trim(\substr($position, 6), '` ');
            $position = 'AFTER';
        } elseif ($position !== 'FIRST') {
            $position = '';
        }

        $this->modifyColumns[$columnName] = [
            'column' => $column,
            'oldName' => $columnName,
            'position' => $position,
            'after' => $after,
        ];

        return $column;
    }

    /**
     * Rename a column.
     *
     * @param string $oldName Current column name
     * @param string $newName New column name
     * @param string $newSyntax Optional new column definition
     *
     * @return Column The column for further configuration
     *
     * @throws Error
     */
    public function renameColumn(string $oldName, string $newName, string $newSyntax = ''): Column
    {
        $oldName = \trim($oldName, '` ');
        $newName = \trim($newName, '` ');

        if (!$oldName || !$newName) {
            throw new DatabaseException('Both old and new column names are required.');
        }

        $this->renameColumns[$oldName] = $newName;

        // Also modify the column if syntax provided
        $configSyntax = '';
        if ($newSyntax && \preg_match('/^(?:\w+|`(?:\\\.(*SKIP)|[^`])*`)(?:=(.+))?/', $newSyntax, $matches)) {
            $configSyntax = $matches[1] ?? '';
        }

        $column = new Column($newName, $configSyntax, $this->table);
        $this->modifyColumns[$oldName] = [
            'column' => $column,
            'oldName' => $oldName,
            'position' => '',
            'after' => '',
        ];

        return $column;
    }

    /**
     * Drop a column from the table.
     *
     * @param string $columnName The column name to drop
     *
     * @return $this
     */
    public function dropColumn(string $columnName): static
    {
        $columnName = \trim($columnName, '` ');
        if ($columnName && !\in_array($columnName, $this->dropColumns)) {
            $this->dropColumns[] = $columnName;
        }
        return $this;
    }

    /**
     * Add an index to the table.
     *
     * @param string $type Index type: 'INDEX', 'UNIQUE', 'FULLTEXT', 'SPATIAL'
     * @param array|string $columns Column(s) to index
     * @param string $indexName Optional index name
     *
     * @return $this
     *
     * @throws Error
     */
    public function addIndex(string $type, array|string $columns, string $indexName = ''): static
    {
        $type = \strtoupper(\trim($type));
        if (!\in_array($type, ['INDEX', 'KEY', 'UNIQUE', 'FULLTEXT', 'SPATIAL', 'PRIMARY'])) {
            throw new DatabaseException('Invalid index type: ' . $type);
        }

        if ($type === 'KEY') {
            $type = 'INDEX';
        }

        $columns = \is_array($columns) ? $columns : [$columns];
        $columns = \array_map(fn ($c) => \trim($c, '` '), $columns);
        $columns = \array_filter($columns);

        if (empty($columns)) {
            throw new DatabaseException('At least one column is required for index.');
        }

        if (!$indexName) {
            $indexName = 'idx_' . \implode('_', \array_map(fn ($c) => \substr($c, 0, 4), $columns));
        }

        $this->addIndexes[$indexName] = [
            'type' => $type,
            'columns' => $columns,
            'name' => $indexName,
        ];

        return $this;
    }

    /**
     * Add a primary key.
     *
     * @param array|string $columns Column(s) for primary key
     *
     * @return $this
     *
     * @throws Error
     */
    public function addPrimaryKey(array|string $columns): static
    {
        return $this->addIndex('PRIMARY', $columns, 'PRIMARY');
    }

    /**
     * Add a unique index.
     *
     * @param array|string $columns Column(s) to index
     * @param string $indexName Optional index name
     *
     * @return $this
     *
     * @throws Error
     */
    public function addUniqueIndex(array|string $columns, string $indexName = ''): static
    {
        return $this->addIndex('UNIQUE', $columns, $indexName);
    }

    /**
     * Add a fulltext index.
     *
     * @param array|string $columns Column(s) to index
     * @param string $indexName Optional index name
     *
     * @return $this
     *
     * @throws Error
     */
    public function addFulltextIndex(array|string $columns, string $indexName = ''): static
    {
        return $this->addIndex('FULLTEXT', $columns, $indexName);
    }

    /**
     * Drop an index.
     *
     * @param string $indexName The index name to drop
     *
     * @return $this
     */
    public function dropIndex(string $indexName): static
    {
        $indexName = \trim($indexName);
        if ($indexName && !\in_array($indexName, $this->dropIndexes)) {
            $this->dropIndexes[] = $indexName;
        }
        return $this;
    }

    /**
     * Drop the primary key.
     *
     * @return $this
     */
    public function dropPrimaryKey(): static
    {
        return $this->dropIndex('PRIMARY');
    }

    /**
     * Add a foreign key constraint.
     *
     * @param string $column The column in this table
     * @param string $referenceTable The referenced table
     * @param string $referenceColumn The referenced column
     * @param string $onDelete Action on delete: CASCADE, SET NULL, RESTRICT, NO ACTION
     * @param string $onUpdate Action on update: CASCADE, SET NULL, RESTRICT, NO ACTION
     * @param string $constraintName Optional constraint name
     *
     * @return $this
     *
     * @throws Error
     */
    public function addForeignKey(
        string $column,
        string $referenceTable,
        string $referenceColumn = '',
        string $onDelete = 'RESTRICT',
        string $onUpdate = 'RESTRICT',
        string $constraintName = '',
    ): static {
        $column = \trim($column, '` ');
        $referenceTable = \trim($referenceTable, '` ');
        $referenceColumn = \trim($referenceColumn, '` ') ?: $column;

        if (!$column || !$referenceTable) {
            throw new DatabaseException('Column and reference table are required for foreign key.');
        }

        $onDelete = \strtoupper(\trim($onDelete));
        $onUpdate = \strtoupper(\trim($onUpdate));
        $validActions = ['CASCADE', 'SET NULL', 'RESTRICT', 'NO ACTION', 'SET DEFAULT'];

        if (!\in_array($onDelete, $validActions)) {
            $onDelete = 'RESTRICT';
        }
        if (!\in_array($onUpdate, $validActions)) {
            $onUpdate = 'RESTRICT';
        }

        if (!$constraintName) {
            $constraintName = 'fk_' . $this->table->getName() . '_' . $column;
        }

        $this->addForeignKeys[$constraintName] = [
            'column' => $column,
            'refTable' => $referenceTable,
            'refColumn' => $referenceColumn,
            'onDelete' => $onDelete,
            'onUpdate' => $onUpdate,
        ];

        return $this;
    }

    /**
     * Drop a foreign key constraint.
     *
     * @param string $constraintName The constraint name to drop
     *
     * @return $this
     */
    public function dropForeignKey(string $constraintName): static
    {
        $constraintName = \trim($constraintName);
        if ($constraintName && !\in_array($constraintName, $this->dropForeignKeys)) {
            $this->dropForeignKeys[] = $constraintName;
        }
        return $this;
    }

    /**
     * Reset all pending alterations.
     *
     * @return $this
     */
    public function reset(): static
    {
        $this->newTableName = '';
        $this->newCharset = '';
        $this->newCollation = '';
        $this->newEngine = '';
        $this->newComment = '';
        $this->addColumns = [];
        $this->modifyColumns = [];
        $this->dropColumns = [];
        $this->addIndexes = [];
        $this->dropIndexes = [];
        $this->addForeignKeys = [];
        $this->dropForeignKeys = [];
        $this->renameColumns = [];
        return $this;
    }

    /**
     * Check if there are any pending alterations.
     *
     * @return bool
     */
    public function hasPendingChanges(): bool
    {
        return $this->newTableName !== ''
            || $this->newCharset !== ''
            || $this->newCollation !== ''
            || $this->newEngine !== ''
            || $this->newComment !== ''
            || !empty($this->addColumns)
            || !empty($this->modifyColumns)
            || !empty($this->dropColumns)
            || !empty($this->addIndexes)
            || !empty($this->dropIndexes)
            || !empty($this->addForeignKeys)
            || !empty($this->dropForeignKeys)
            || !empty($this->renameColumns);
    }

    /**
     * Get the table being altered.
     *
     * @return Table
     */
    public function getTable(): Table
    {
        return $this->table;
    }

    /**
     * Generate the ALTER TABLE SQL statement.
     *
     * @return string The complete ALTER TABLE statement
     *
     * @throws Error
     */
    public function getSyntax(): string
    {
        if (!$this->hasPendingChanges()) {
            return '';
        }

        $alterations = [];

        // Rename table (included inline at the beginning for single-statement mode)
        if ($this->newTableName && $this->newTableName !== $this->table->getName()) {
            $alterations[] = 'RENAME TO `' . \addslashes($this->newTableName) . '`';
        }

        $alterations = \array_merge($alterations, $this->collectAlterations());

        if (empty($alterations)) {
            return '';
        }

        $tableName = \addslashes($this->table->getName());

        return 'ALTER TABLE `' . $tableName . '` ' . \implode(', ', $alterations) . ';';
    }

    /**
     * Generate multiple ALTER TABLE statements (one per alteration).
     * Useful when some databases don't support multiple alterations in one statement.
     *
     * @return string[] Array of ALTER TABLE statements
     *
     * @throws Error
     */
    public function getSyntaxArray(): array
    {
        if (!$this->hasPendingChanges()) {
            return [];
        }

        $tableName = \addslashes($this->table->getName());
        $alterations = $this->collectAlterations();

        $statements = \array_map(
            fn (string $alteration) => 'ALTER TABLE `' . $tableName . '` ' . $alteration . ';',
            $alterations,
        );

        // Apply rename last (separate statement so prior statements use the original name)
        if ($this->newTableName && $this->newTableName !== $this->table->getName()) {
            $statements[] = 'ALTER TABLE `' . $tableName . '` RENAME TO `' . \addslashes($this->newTableName) . '`;';
        }

        return $statements;
    }

    /**
     * Build the FIRST/AFTER position clause for a column alteration.
     *
     * @param array $data Column data array with 'position' and 'after' keys
     *
     * @return string The position clause (e.g. ' FIRST', ' AFTER `col`') or empty string
     */
    private function buildColumnPositionClause(array $data): string
    {
        if ($data['position'] === 'FIRST') {
            return ' FIRST';
        }

        if ($data['position'] === 'AFTER' && $data['after']) {
            return ' AFTER `' . \addslashes($data['after']) . '`';
        }

        return '';
    }

    /**
     * Collect all alteration clauses (excluding RENAME) in the correct order.
     *
     * @return string[] Array of alteration clause strings
     */
    private function collectAlterations(): array
    {
        $alterations = [];

        // Drop foreign keys first (before dropping columns they might reference)
        foreach ($this->dropForeignKeys as $constraintName) {
            $alterations[] = 'DROP FOREIGN KEY `' . \addslashes($constraintName) . '`';
        }

        // Drop indexes (before dropping columns they might include)
        foreach ($this->dropIndexes as $indexName) {
            if ($indexName === 'PRIMARY') {
                $alterations[] = 'DROP PRIMARY KEY';
            } else {
                $alterations[] = 'DROP INDEX `' . \addslashes($indexName) . '`';
            }
        }

        // Drop columns
        foreach ($this->dropColumns as $columnName) {
            $alterations[] = 'DROP COLUMN `' . \addslashes($columnName) . '`';
        }

        // Add columns
        foreach ($this->addColumns as $data) {
            /** @var Column $column */
            $column = $data['column'];
            $alterations[] = 'ADD COLUMN ' . $column->getSyntax() . $this->buildColumnPositionClause($data);
        }

        // Modify/rename columns
        foreach ($this->modifyColumns as $oldName => $data) {
            /** @var Column $column */
            $column = $data['column'];

            if (isset($this->renameColumns[$oldName])) {
                // CHANGE for rename + modify
                $sql = 'CHANGE COLUMN `' . \addslashes($oldName) . '` ' . $column->getSyntax();
            } else {
                // MODIFY for just modification
                $sql = 'MODIFY COLUMN ' . $column->getSyntax();
            }

            $alterations[] = $sql . $this->buildColumnPositionClause($data);
        }

        // Add indexes
        foreach ($this->addIndexes as $data) {
            $columns = \array_map(fn ($c) => '`' . \addslashes($c) . '`', $data['columns']);
            $columnList = \implode(', ', $columns);

            if ($data['type'] === 'PRIMARY') {
                $alterations[] = 'ADD PRIMARY KEY (' . $columnList . ')';
            } else {
                $indexName = '`' . \addslashes($data['name']) . '`';
                $alterations[] = 'ADD ' . $data['type'] . ' ' . $indexName . ' (' . $columnList . ')';
            }
        }

        // Add foreign keys
        foreach ($this->addForeignKeys as $name => $data) {
            $sql = 'ADD CONSTRAINT `' . \addslashes($name) . '` ';
            $sql .= 'FOREIGN KEY (`' . \addslashes($data['column']) . '`) ';
            $sql .= 'REFERENCES `' . \addslashes($data['refTable']) . '` (`' . \addslashes($data['refColumn']) . '`)';

            if ($data['onDelete'] !== 'RESTRICT') {
                $sql .= ' ON DELETE ' . $data['onDelete'];
            }
            if ($data['onUpdate'] !== 'RESTRICT') {
                $sql .= ' ON UPDATE ' . $data['onUpdate'];
            }

            $alterations[] = $sql;
        }

        // Table options
        if ($this->newEngine) {
            $alterations[] = 'ENGINE = ' . \addslashes($this->newEngine);
        }

        if ($this->newCharset || $this->newCollation) {
            $charsetSql = '';
            if ($this->newCharset) {
                $charsetSql = 'CONVERT TO CHARACTER SET ' . \addslashes($this->newCharset);
            }
            if ($this->newCollation) {
                $charsetSql .= ($charsetSql ? ' ' : '') . 'COLLATE ' . \addslashes($this->newCollation);
            }
            if ($charsetSql) {
                $alterations[] = $charsetSql;
            }
        }

        if ($this->newComment !== '') {
            $alterations[] = "COMMENT = '" . \addslashes($this->newComment) . "'";
        }

        return $alterations;
    }
}
