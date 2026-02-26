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
 * @license MIT
 */

namespace Razy\Database\Table;

use Razy\Database\Column;
use Razy\Database\Table;

/**
 * Class ColumnHelper
 *
 * Fluent builder for generating column-specific ALTER TABLE statements.
 * Provides chainable methods for modifying a single column's definition
 * including type, length, nullability, defaults, charset, collation,
 * auto-increment, zerofill, comments, and column positioning within
 * the table. Generates MODIFY COLUMN or CHANGE COLUMN SQL based on
 * whether a rename is involved.
 *
 * @package Razy
 * @license MIT
 */
class ColumnHelper
{
    /** @var string The original column name to alter */
    private string $columnName;

    /** @var string|null New column name for rename operations, null if not renaming */
    private ?string $newName = null;

    /** @var string|null New data type (e.g., VARCHAR, INT, TEXT), null to keep unchanged */
    private ?string $type = null;

    /** @var int|null New column length or precision, null to keep unchanged */
    private ?int $length = null;

    /** @var int|null Decimal point count for DECIMAL/FLOAT types, null to keep unchanged */
    private ?int $decimalPoints = null;

    /** @var bool|null Whether the column allows NULL values, null to keep unchanged */
    private ?bool $nullable = null;

    /** @var string|null New default value for the column, null to keep unchanged */
    private ?string $default = null;

    /** @var bool Whether the default should be explicitly set to NULL */
    private bool $defaultNull = false;

    /** @var string|null New character set for the column, null to keep unchanged */
    private ?string $charset = null;

    /** @var string|null New collation for the column, null to keep unchanged */
    private ?string $collation = null;

    /** @var string|null Column comment text, null to keep unchanged */
    private ?string $comment = null;

    /** @var bool|null Whether auto-increment is enabled, null to keep unchanged */
    private ?bool $autoIncrement = null;

    /** @var bool|null Whether zerofill padding is enabled, null to keep unchanged */
    private ?bool $zerofill = null;

    /** @var string|null Column position directive ('FIRST' or 'AFTER'), null for unchanged */
    private ?string $position = null;

    /** @var string|null Column name to position after (used when position is 'AFTER') */
    private ?string $afterColumn = null;

    /** @var bool Whether to remove the existing default value */
    private bool $dropDefault = false;

    /**
     * ColumnHelper constructor.
     *
     * @param Table $table The table containing the column
     * @param string $columnName The column to alter
     */
    public function __construct(private readonly Table $table, string $columnName)
    {
        $this->columnName = trim($columnName, '` ');
    }

    /**
     * Rename the column.
     *
     * @param string $newName The new column name
     * @return $this
     */
    public function rename(string $newName): static
    {
        $this->newName = trim($newName, '` ');
        return $this;
    }

    /**
     * Set the column type.
     *
     * @param string $type The data type (VARCHAR, INT, TEXT, etc.)
     * @return $this
     */
    public function type(string $type): static
    {
        $this->type = strtoupper(trim($type));
        return $this;
    }

    /**
     * Set the column as VARCHAR with optional length.
     *
     * @param int $length The length (default: 255)
     * @return $this
     */
    public function varchar(int $length = 255): static
    {
        $this->type = 'VARCHAR';
        $this->length = max(1, $length);
        return $this;
    }

    /**
     * Set the column as INT with optional length.
     *
     * @param int $length The display width (default: 11)
     * @return $this
     */
    public function int(int $length = 11): static
    {
        $this->type = 'INT';
        $this->length = max(1, $length);
        return $this;
    }

    /**
     * Set the column as BIGINT.
     *
     * @param int $length The display width (default: 20)
     * @return $this
     */
    public function bigint(int $length = 20): static
    {
        $this->type = 'BIGINT';
        $this->length = max(1, $length);
        return $this;
    }

    /**
     * Set the column as TINYINT (for boolean-like values).
     *
     * @param int $length The display width (default: 1)
     * @return $this
     */
    public function tinyint(int $length = 1): static
    {
        $this->type = 'TINYINT';
        $this->length = max(1, $length);
        return $this;
    }

    /**
     * Set the column as DECIMAL.
     *
     * @param int $precision Total number of digits
     * @param int $scale Number of digits after decimal point
     * @return $this
     */
    public function decimal(int $precision = 10, int $scale = 2): static
    {
        $this->type = 'DECIMAL';
        $this->length = max(1, $precision);
        $this->decimalPoints = max(0, min($precision - 1, $scale));
        return $this;
    }

    /**
     * Set the column as FLOAT.
     *
     * @param int $precision Total number of digits
     * @param int $scale Number of digits after decimal point
     * @return $this
     */
    public function float(int $precision = 10, int $scale = 2): static
    {
        $this->type = 'FLOAT';
        $this->length = max(1, $precision);
        $this->decimalPoints = max(0, min($precision - 1, $scale));
        return $this;
    }

    /**
     * Set the column as TEXT.
     *
     * @return $this
     */
    public function text(): static
    {
        $this->type = 'TEXT';
        $this->length = null;
        return $this;
    }

    /**
     * Set the column as LONGTEXT.
     *
     * @return $this
     */
    public function longtext(): static
    {
        $this->type = 'LONGTEXT';
        $this->length = null;
        return $this;
    }

    /**
     * Set the column as MEDIUMTEXT.
     *
     * @return $this
     */
    public function mediumtext(): static
    {
        $this->type = 'MEDIUMTEXT';
        $this->length = null;
        return $this;
    }

    /**
     * Set the column as DATETIME.
     *
     * @return $this
     */
    public function datetime(): static
    {
        $this->type = 'DATETIME';
        $this->length = null;
        return $this;
    }

    /**
     * Set the column as TIMESTAMP.
     *
     * @return $this
     */
    public function timestamp(): static
    {
        $this->type = 'TIMESTAMP';
        $this->length = null;
        return $this;
    }

    /**
     * Set the column as DATE.
     *
     * @return $this
     */
    public function date(): static
    {
        $this->type = 'DATE';
        $this->length = null;
        return $this;
    }

    /**
     * Set the column as TIME.
     *
     * @return $this
     */
    public function time(): static
    {
        $this->type = 'TIME';
        $this->length = null;
        return $this;
    }

    /**
     * Set the column as JSON.
     *
     * @return $this
     */
    public function json(): static
    {
        $this->type = 'JSON';
        $this->length = null;
        return $this;
    }

    /**
     * Set the column as BLOB.
     *
     * @return $this
     */
    public function blob(): static
    {
        $this->type = 'BLOB';
        $this->length = null;
        return $this;
    }

    /**
     * Set the column as ENUM.
     *
     * @param array $values The enum values
     * @return $this
     */
    public function enum(array $values): static
    {
        $this->type = "ENUM('" . implode("','", array_map('addslashes', $values)) . "')";
        $this->length = null;
        return $this;
    }

    /**
     * Set the column length.
     *
     * @param int $length The column length
     * @param int $decimalPoints The decimal points (for DECIMAL, FLOAT, etc.)
     * @return $this
     */
    public function length(int $length, int $decimalPoints = 0): static
    {
        $this->length = max(1, $length);
        $this->decimalPoints = max(0, $decimalPoints);
        return $this;
    }

    /**
     * Set the column as nullable.
     *
     * @param bool $nullable Whether the column allows NULL
     * @return $this
     */
    public function nullable(bool $nullable = true): static
    {
        $this->nullable = $nullable;
        return $this;
    }

    /**
     * Set the column as NOT NULL.
     *
     * @return $this
     */
    public function notNull(): static
    {
        $this->nullable = false;
        return $this;
    }

    /**
     * Set the default value.
     *
     * @param mixed $value The default value (use null for NULL default)
     * @return $this
     */
    public function default(mixed $value): static
    {
        if ($value === null) {
            $this->defaultNull = true;
            $this->default = null;
        } else {
            $this->defaultNull = false;
            $this->default = (string) $value;
        }
        return $this;
    }

    /**
     * Set default to CURRENT_TIMESTAMP.
     *
     * @return $this
     */
    public function defaultCurrentTimestamp(): static
    {
        $this->default = 'CURRENT_TIMESTAMP';
        return $this;
    }

    /**
     * Drop the default value.
     *
     * @return $this
     */
    public function dropDefault(): static
    {
        $this->dropDefault = true;
        return $this;
    }

    /**
     * Set the column charset.
     *
     * @param string $charset The character set
     * @return $this
     */
    public function charset(string $charset): static
    {
        $this->charset = trim($charset);
        return $this;
    }

    /**
     * Set the column collation.
     *
     * @param string $collation The collation
     * @return $this
     */
    public function collation(string $collation): static
    {
        $this->collation = trim($collation);
        return $this;
    }

    /**
     * Set the column comment.
     *
     * @param string $comment The comment
     * @return $this
     */
    public function comment(string $comment): static
    {
        $this->comment = $comment;
        return $this;
    }

    /**
     * Set the column as auto increment.
     *
     * @param bool $autoIncrement Whether to enable auto increment
     * @return $this
     */
    public function autoIncrement(bool $autoIncrement = true): static
    {
        $this->autoIncrement = $autoIncrement;
        return $this;
    }

    /**
     * Set the column as zerofill.
     *
     * @param bool $zerofill Whether to enable zerofill
     * @return $this
     */
    public function zerofill(bool $zerofill = true): static
    {
        $this->zerofill = $zerofill;
        return $this;
    }

    /**
     * Move the column to first position.
     *
     * @return $this
     */
    public function first(): static
    {
        $this->position = 'FIRST';
        $this->afterColumn = null;
        return $this;
    }

    /**
     * Move the column after another column.
     *
     * @param string $columnName The column to place after
     * @return $this
     */
    public function after(string $columnName): static
    {
        $this->position = 'AFTER';
        $this->afterColumn = trim($columnName, '` ');
        return $this;
    }

    /**
     * Generate the ALTER COLUMN SQL statement.
     *
     * @return string The ALTER TABLE ... MODIFY/CHANGE COLUMN statement
     * @throws Error
     */
    public function getSyntax(): string
    {
        $tableName = addslashes($this->table->getName());
        $columnName = addslashes($this->columnName);

        // Build column definition
        $definition = $this->buildColumnDefinition();

        if ($this->newName && $this->newName !== $this->columnName) {
            // CHANGE COLUMN for rename
            $sql = 'ALTER TABLE `' . $tableName . '` CHANGE COLUMN `' . $columnName . '` ' . $definition;
        } else {
            // MODIFY COLUMN for just modification
            $sql = 'ALTER TABLE `' . $tableName . '` MODIFY COLUMN ' . $definition;
        }

        // Add position clause
        if ($this->position === 'FIRST') {
            $sql .= ' FIRST';
        } elseif ($this->position === 'AFTER' && $this->afterColumn) {
            $sql .= ' AFTER `' . addslashes($this->afterColumn) . '`';
        }

        return $sql . ';';
    }

    /**
     * Build the column definition part of the ALTER statement.
     *
     * @return string
     */
    private function buildColumnDefinition(): string
    {
        // Use the new name if renaming, otherwise keep the original column name
        $name = $this->newName ?? $this->columnName;
        $sql = '`' . addslashes($name) . '`';

        // Append data type if specified
        if ($this->type) {
            $sql .= ' ' . $this->type;

            // Append length/precision — skip for ENUM since values are embedded in the type string
            if ($this->length !== null && !str_starts_with($this->type, 'ENUM')) {
                if ($this->decimalPoints !== null && $this->decimalPoints > 0) {
                    $sql .= '(' . $this->length . ',' . $this->decimalPoints . ')';
                } else {
                    $sql .= '(' . $this->length . ')';
                }
            }
        }

        // Zerofill
        if ($this->zerofill === true) {
            $sql .= ' ZEROFILL';
        }

        // Charset and Collation
        if ($this->charset) {
            $sql .= ' CHARACTER SET ' . addslashes($this->charset);
        }
        if ($this->collation) {
            $sql .= ' COLLATE ' . addslashes($this->collation);
        }

        // Nullable
        if ($this->nullable === true) {
            $sql .= ' NULL';
        } elseif ($this->nullable === false) {
            $sql .= ' NOT NULL';
        }

        // Default
        if ($this->dropDefault) {
            // No default clause - effectively drops it
        } elseif ($this->defaultNull) {
            $sql .= ' DEFAULT NULL';
        } elseif ($this->default !== null) {
            if ($this->default === 'CURRENT_TIMESTAMP') {
                $sql .= ' DEFAULT CURRENT_TIMESTAMP';
            } else {
                $sql .= " DEFAULT '" . addslashes($this->default) . "'";
            }
        }

        // Auto increment
        if ($this->autoIncrement === true) {
            $sql .= ' AUTO_INCREMENT';
        }

        // Comment
        if ($this->comment !== null) {
            $sql .= " COMMENT '" . addslashes($this->comment) . "'";
        }

        return $sql;
    }

    /**
     * Get the table.
     *
     * @return Table
     */
    public function getTable(): Table
    {
        return $this->table;
    }

    /**
     * Get the original column name.
     *
     * @return string
     */
    public function getColumnName(): string
    {
        return $this->columnName;
    }

    /**
     * Get the new column name (if renamed).
     *
     * @return string|null
     */
    public function getNewName(): ?string
    {
        return $this->newName;
    }

    /**
     * Convert to string (returns SQL syntax).
     *
     * @return string
     * @throws Error
     */
    public function __toString(): string
    {
        return $this->getSyntax();
    }
}
