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

use Razy\Exception\DatabaseException;
use Razy\Util\StringUtil;

/**
 * Class Column.
 *
 * Represents a database table column definition. Handles column type, length,
 * default values, charset, collation, keys, references (foreign keys), and
 * auto-increment configuration. Supports parsing column definition syntax
 * strings and generating SQL fragments for CREATE/ALTER TABLE statements.
 *
 * @package Razy
 *
 * @license MIT
 */
class Column
{
    /** @var string Unique identifier for this column instance (GUID) */
    private string $id;

    /** @var array Column configuration parameters (type, length, nullable, default, key, charset, etc.) */
    private array $parameters = [];

    /** @var string Optional column comment for the SQL COMMENT clause */
    private string $comment = '';

    /**
     * Column constructor.
     *
     * @param string $name
     * @param string $configSyntax
     * @param Table|null $table
     *
     * @throws Error
     */
    public function __construct(private string $name, private string $configSyntax = '', private ?Table $table = null)
    {
        $this->name = \trim($this->name);
        $this->configSyntax = \trim($this->configSyntax);

        // Validate column name: must be a backtick-quoted identifier or a lowercase-alpha-starting word
        if (!\preg_match('/^(`(?:(?:\\\\.(*SKIP)(*FAIL)|.)++|\\\\[\\\\`])+`|[a-z]\w*)$/', $this->name)) {
            throw new DatabaseException('The column name ' . $this->name . ' is not in a correct format,');
        }

        // Initialize default column parameters
        $this->parameters = [
            'type' => 'VARCHAR',
            'length' => 255,
            'default' => '',
            'nullable' => false,
            'insert_after' => '',
        ];

        // Parse and apply config syntax if provided (e.g., "type(text),nullable,default('value')")
        if ($this->configSyntax) {
            $parameters = $this->parseSyntax($configSyntax);
            $this->configure($parameters);
        }

        // Generate a unique ID for tracking this column instance across table operations
        $this->id = StringUtil::guid();
    }

    /**
     * Configure the column by an array or parameters.
     *
     * @param array $parameters
     *
     * @return Column
     *
     * @throws Error
     */
    public function configure(array $parameters): self
    {
        // Iterate through supported configuration keys and apply each if present
        foreach (['type', 'length', 'nullable', 'charset', 'collation', 'zerofill', 'create', 'key', 'oncreate', 'onupdate', 'default', 'reference'] as $method) {
            if (!\array_key_exists($method, $parameters)) {
                continue;
            }
            $arguments = $parameters[$method] ?? null;
            if ('type' == $method) {
                $this->setType((!$arguments || !\is_scalar($arguments[0])) ? 'text' : $arguments[0]);
            } elseif ('length' == $method) {
                if (null !== $arguments) {
                    $arguments[0] = \max(1, $arguments[0] ?? 1);
                    $arguments[1] = \max(0, $arguments[1] ?? 0);
                    $this->setLength($arguments[0], $arguments[1]);
                }
            } elseif ('zerofill' == $method) {
                $this->isZerofill(true);
            } elseif ('nullable' == $method) {
                $this->setNullable();
            } elseif ('reference' == $method) {
                if (null !== $arguments) {
                    $arguments[0] = \trim($arguments[0] ?? '', '`');
                    $arguments[1] = \trim($arguments[1] ?? '', '`');
                    if ($arguments[0]) {
                        $this->setReference($arguments[0], $arguments[1]);
                    }
                }
            } elseif ('charset' == $method) {
                if (null !== $arguments && isset($arguments[0])) {
                    $this->setCharset($arguments[0]);
                }
            } elseif ('collation' == $method) {
                if (null !== $arguments && isset($arguments[0])) {
                    $this->setCollation($arguments[0]);
                }
            } elseif ('default' == $method) {
                if (null !== $arguments && isset($arguments[0]) && \is_string($arguments[0])) {
                    $this->setDefault($arguments[0]);
                } else {
                    $this->setDefault(null);
                }
            } elseif ('key' == $method) {
                $this->setKey((isset($parameters[$method])) ? $arguments[0] ?? '' : '');
            } elseif ('oncreate' == $method) {
                $this->defaultCurrentTimestamp(true);
            } elseif ('onupdate' == $method) {
                $this->updateCurrentTimestamp(true);
            }
        }

        return $this;
    }

    /**
     * Set the column type.
     *
     * @param string $type
     *
     * @return $this
     *
     * @throws Error
     */
    public function setType(string $type): self
    {
        $type = \strtolower(\trim($type));
        if (!$type) {
            throw new DatabaseException('The column data type cannot be empty.');
        }

        // Reset default and map type aliases to actual SQL column types
        $this->parameters['default'] = '';
        if ('auto_id' === $type || 'auto' === $type || 'auto_increment' === $type) {
            $this->parameters['type'] = 'INT';
            $this->parameters['auto_increment'] = true;
            $this->parameters['key'] = 'primary';
            $this->parameters['length'] = 8;
            $this->parameters['default'] = '0';
        } elseif ('text' === $type) {
            $this->parameters['type'] = 'VARCHAR';
            $this->parameters['length'] = 255;
            $this->parameters['default'] = '';
        } elseif ('full_text' === $type) {
            $this->parameters['type'] = 'TEXT';
            $this->parameters['length'] = '';
            $this->parameters['nullable'] = true;
        } elseif ('long_text' === $type) {
            $this->parameters['type'] = 'LONGTEXT';
            $this->parameters['length'] = '';
            $this->parameters['nullable'] = true;
        } elseif ('int' === $type) {
            $this->parameters['type'] = 'INT';
            $this->parameters['length'] = 8;
            $this->parameters['default'] = '0';
        } elseif ('bool' === $type || 'boolean' === $type) {
            $this->parameters['type'] = 'TINYINT';
            $this->parameters['length'] = 1;
            $this->parameters['default'] = '0';
        } elseif ('decimal' === $type || 'money' === $type || 'float' === $type || 'real' === $type || 'double' === $type) {
            $this->parameters['type'] = ('decimal' === $type || 'money' === $type) ? 'DECIMAL' : \strtoupper($type);
            $this->parameters['length'] = 8;
            $this->parameters['decimal_points'] = 2;
            $this->parameters['default'] = '0';
        } elseif ('timestamp' === $type) {
            $this->parameters['type'] = 'TIMESTAMP';
            $this->parameters['default'] = null;
            $this->parameters['nullable'] = true;
        } elseif ('datetime' === $type) {
            $this->parameters['type'] = 'DATETIME';
            $this->parameters['default'] = null;
            $this->parameters['nullable'] = true;
        } elseif ('date' === $type) {
            $this->parameters['type'] = 'DATE';
            $this->parameters['default'] = null;
            $this->parameters['nullable'] = true;
        } elseif ('json' === $type) {
            $this->parameters['type'] = 'JSON';
            $this->parameters['default'] = '{}';
            $this->parameters['nullable'] = true;
        } else {
            $this->parameters['type'] = \strtoupper($type);
        }

        return $this;
    }

    /**
     * Set the length of the column.
     *
     * @param int $length
     * @param int $decPoints
     *
     * @return $this
     */
    public function setLength(int $length, int $decPoints = 0): self
    {
        $length = \max(1, $length);
        $decPoints = \max(0, \min($length - 1, $decPoints));
        $this->parameters['length'] = $length;
        $this->parameters['decimal_points'] = $decPoints;

        return $this;
    }

    /**
     * Set the column is zerofill.
     *
     * @param bool $enable
     *
     * @return Column
     */
    public function isZerofill(bool $enable): self
    {
        $this->parameters['zerofill'] = $enable;

        return $this;
    }

    /**
     * Set the column is allowed NULL value.
     *
     * @param bool $enable
     *
     * @return $this
     */
    public function setNullable(bool $enable = true): self
    {
        $this->parameters['nullable'] = $enable;

        return $this;
    }

    /**
     * Set the charset of the column.
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
        if ($charset && !\preg_match('/^\w+$/', $charset)) {
            throw new DatabaseException($charset . ' is not in a correct character set format.');
        }
        $this->parameters['charset'] = $charset;

        return $this;
    }

    /**
     * Set the collation of the column.
     *
     * @param string $collation
     *
     * @return $this
     *
     * @throws Error
     */
    public function setCollation(string $collation): self
    {
        $charset = \trim($collation);
        if ($charset && !\preg_match('/\w+^$', $collation)) {
            throw new DatabaseException($collation . ' is not in a correct character set format.');
        }
        $this->parameters['collation'] = $collation;

        return $this;
    }

    /**
     * Set the default value of the column.
     *
     * @param string|null $value
     *
     * @return $this
     */
    public function setDefault(?string $value): self
    {
        $this->parameters['default'] = $value;

        return $this;
    }

    /**
     * Set the key type.
     *
     * @param string $type
     *
     * @return $this
     */
    public function setKey(string $type): self
    {
        $type = \strtolower(\trim($type));
        $this->parameters['key'] = (\preg_match('/^primary|index|unique|fulltext|spatial$/', $type)) ? $type : null;

        return $this;
    }

    /**
     * Set the column's comment.
     *
     * @param string $comment
     *
     * @return $this
     */
    public function setComment(string $comment): self
    {
        $this->comment = $comment;

        return $this;
    }

    /**
     * Enable set current timestamp as default value when the column is a date/time/timestamp.
     *
     * @param bool $enable
     *
     * @return $this
     */
    public function defaultCurrentTimestamp(bool $enable): self
    {
        $this->parameters['default_current_timestamp'] = $enable;
        return $this;
    }

    /**
     * Generate the ALTER COLUMN SQL fragment by comparing with the original column definition.
     * Only generates syntax for properties that have actually changed.
     *
     * @param Column $column The original column to compare against
     * @param bool $reordered Whether the column has been reordered in the table
     *
     * @return string The MODIFY COLUMN SQL fragment, or empty string if no changes
     */
    public function alter(self $column, bool $reordered = false): string
    {
        $changes = '';

        // Check for default value changes
        if ($this->parameters['default'] !== $column->getDefault()) {
            $changes .= $this->getDefaultSyntax();
        }

        if ($this->parameters['charset'] !== $column->getCharset() || $this->parameters['collation'] !== $column->getCollation()) {
            $changes .= $this->getCharsetSyntax();
        }

        if ($reordered) {
            $changes .= ($this->getOrderAfter()) ? ' AFTER ' . $this->getOrderAfter() : ' FIRST';
        }

        if ($changes) {
            $changes = $this->getTypeSyntax() . $this->getNullableSyntax() . $changes;
            if ($this->name != $column->getName()) {
                $changes = ' ' . $this->name . $changes;
            }
        }

        return ($changes) ? ' MODIFY COLUMN ' . $column->getName() . $changes : '';
    }

    /**
     * Get the `after` value of the column.
     *
     * @return string
     */
    public function getOrderAfter(): string
    {
        return $this->parameters['insert_after'];
    }

    /**
     * Get the column name.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the column's comment.
     *
     * @return string
     */
    public function getComment(): string
    {
        return $this->comment;
    }

    /**
     * Rename the column.
     *
     * @param string $columnName
     *
     * @return $this
     *
     * @throws Error
     */
    public function setName(string $columnName): self
    {
        $columnName = \trim($columnName);
        if (!\preg_match('/^(`(?:(?:\\\\.(*SKIP)(*FAIL)|.)++|\\\\[\\\\`])+`|[a-z]\w*)$/', $columnName)) {
            throw new DatabaseException('The column name ' . $columnName . ' is not in a correct format,');
        }
        $this->name = $columnName;
        $this->table?->validate();

        return $this;
    }

    /**
     * Rebind the table.
     *
     * @param Table $table
     *
     * @return $this
     *
     * @throws Error
     */
    public function bindTo(Table $table): self
    {
        if ($table !== $this->table) {
            $this->table = $table;
            $table->insertColumn($this);
        }

        return $this;
    }

    /**
     * Export the Column Syntax.
     *
     * @return string
     */
    public function exportConfig(): string
    {
        $config = '`' . $this->name . '`';
        $parameters = [];

        // Iterate through exportable parameters and build the config syntax string
        foreach (['type', 'length', 'nullable', 'charset', 'collation', 'zerofill', 'default', 'key', 'comment', 'oncurrent'] as $method) {
            if ('type' == $method) {
                switch ($this->parameters['type']) {
                    case 'VARCHAR':
                        $type = 'text';

                        break;

                    case 'INT':
                        if ($this->parameters['auto_increment'] ?? false) {
                            $type = 'auto';
                        } else {
                            $type = ('1' == $this->parameters['length']) ? 'bool' : 'int';
                        }

                        break;

                    default:
                        $type = \strtolower($this->parameters['type']);
                }
                $parameters[] = 'type(' . $type . ')';
            } elseif ('length' == $method) {
                $parameters[] = 'length(' . $this->parameters['length'] . ')';
            } elseif ('nullable' == $method) {
                if ($this->parameters['nullable'] ?? false) {
                    $parameters[] = 'nullable';
                }
            } elseif ('zerofill' == $method) {
                if (\preg_match('/^(SMALL|MEDIUM|BIG)?INT$/', $this->parameters['type'])) {
                    if ($this->parameters['zerofill'] ?? false) {
                        $parameters[] = 'zerofill';
                    }
                }
            } elseif ('charset' == $method) {
                if (isset($this->parameters['charset']) && $this->parameters['charset']) {
                    $parameters[] = 'charset(' . $this->parameters['charset'] . ')';
                }
            } elseif ('collation' == $method) {
                if (isset($this->parameters['collation']) && $this->parameters['collation']) {
                    $parameters[] = 'collation(' . $this->parameters['charset'] . ')';
                }
            } elseif ('default' == $method) {
                if (isset($this->parameters['default'])) {
                    $parameters[] = 'default("' . \addslashes($this->parameters['default']) . '")';
                }
            } elseif ('key' == $method) {
                if (isset($this->parameters['key'])) {
                    $parameters[] = 'key(' . $this->parameters['key'] . ')';
                }
            } elseif ('oncreate' == $method) {
                if ($this->parameters['oncreate'] ?? false) {
                    $parameters[] = 'oncreate';
                }
            } elseif ('onupdate' == $method) {
                if ($this->parameters['onupdate'] ?? false) {
                    $parameters[] = 'onupdate';
                }
            }
        }
        if (!empty($parameters)) {
            $config .= '=' . \implode(',', $parameters);
        }

        return $config;
    }

    /**
     * Get the unique ID of the column.
     *
     * @return string
     */
    public function getID(): string
    {
        return $this->id;
    }

    /**
     * Get the index key type.
     *
     * @return string
     */
    public function getKey(): string
    {
        return $this->parameters['key'] ?? '';
    }

    /**
     * Export the column insert or alter syntax.
     *
     * @return string
     */
    public function getSyntax(): string
    {
        $syntax = '`' . $this->name . '`';

        // Auto-increment columns get a fixed INT definition with NOT NULL
        if ($this->parameters['auto_increment'] ?? false) {
            $syntax .= ' INT(' . $this->parameters['length'] . ') NOT NULL AUTO_INCREMENT';
        } else {
            // Normal columns: compose type, nullable, default, and charset clauses
            $syntax .= $this->getTypeSyntax() . $this->getNullableSyntax() . $this->getDefaultSyntax() . $this->getCharsetSyntax();
        }

        if ($this->comment) {
            $syntax .= " COMMENT '" . \addslashes($this->comment) . "'";
        }

        return $syntax;
    }

    /**
     * Generate the FOREIGN KEY constraint SQL syntax for this column.
     *
     * @return string The FOREIGN KEY clause, with optional CONSTRAINT name when aliased
     */
    public function getForeignKeySyntax(): string
    {
        $hasAlias = false;
        // Determine if the foreign key references a different column name than this column
        if (!$this->parameters['reference_column'] || $this->parameters['reference_column'] === $this->name) {
            $foreignKey = $this->name;
        } else {
            $hasAlias = true;
            $foreignKey = $this->parameters['reference_column'];
        }

        return ($hasAlias ? 'CONSTRAINT ' : '') . 'FOREIGN KEY(`' . $this->name . '`) REFERENCES `' . $this->parameters['reference_table'] . '` (`' . $foreignKey . '`)';
    }

    /**
     * Get the data type of the column.
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->parameters['type'];
    }

    /**
     * Reorder the column after a specified column.
     *
     * @param string $columnName
     *
     * @return $this
     *
     * @throws Error
     */
    public function insertAfter(string $columnName = ''): self
    {
        $columnName = \trim($columnName);
        if ($columnName && !\preg_match('/^(`(?:(?:\\\\.(*SKIP)(*FAIL)|.)++|\\\\[\\\\`])+`|[a-z]\w*)$/', $columnName)) {
            throw new DatabaseException('The column name ' . $columnName . ' is not in a correct format,');
        }

        $this->parameters['insert_after'] = $columnName;

        return $this;
    }

    /**
     * Return true if the column is an auto increment column.
     *
     * @return bool
     */
    public function isAuto(): bool
    {
        return $this->parameters['auto_increment'] ?? false;
    }

    /**
     * Return true if the column is nullable.
     *
     * @return bool
     */
    public function isNullable(): bool
    {
        return $this->parameters['nullable'];
    }

    /**
     * Check if this column has a foreign key reference defined.
     *
     * @return bool True if a reference table is set
     */
    public function hasReference(): bool
    {
        return !!($this->parameters['reference_table'] ?? null);
    }

    /**
     * Get the referenced table name for the foreign key.
     *
     * @return string The reference table name, or empty string if none
     */
    public function getReferenceTable(): string
    {
        return $this->parameters['reference_table'] ?? '';
    }

    /**
     * Get the referenced column name for the foreign key.
     *
     * @return string The reference column name, or empty string if same as this column
     */
    public function getReferenceColumn(): string
    {
        return $this->parameters['reference_column'] ?? '';
    }

    /**
     * Parse the column syntax.
     *
     * @param string $syntax
     *
     * @return array
     */
    private function parseSyntax(string $syntax): array
    {
        $parameters = [];

        // Split syntax string by commas, respecting parenthesized groups and quoted strings
        $clips = \preg_split('/(?:\\\\.|\((?:\\\\.(*SKIP)|[^()])*\)|(?<q>[\'"])(?:\\\\.(*SKIP)|(?!\k<q>).)*\k<q>)(*SKIP)(*FAIL)|\s*,\s*/', $syntax, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($clips as $clip) {
            // Match keyword with optional parenthesized arguments: e.g., "type(text)" or "nullable"
            if (\preg_match('/^(\w+)(?:\(((?:\\.(*SKIP)|[^()])*)\))?/', $clip, $matches)) {
                if (!isset($parameters[$matches[1]])) {
                    $parameters[$matches[1]] = null;
                }

                // Parse comma-separated arguments inside parentheses
                if ($matches[2] ?? '') {
                    $parameters[$matches[1]] = [];
                    // Extract each argument: identifier, number, or quoted string
                    while (\preg_match('/^(?:\A|,)(\w+|(\d+(?:\.\d+)?)|(?<q>[\'"])((?:\\\\.(*SKIP)|(?!\k<q>).)*)\k<q>)/', $matches[2], $extracted)) {
                        $parameters[$matches[1]][] = $extracted[4] ?? $extracted[1];
                        $matches[2] = \substr($matches[2], \strlen($extracted[0]));
                    }
                }
            }
        }

        return $parameters;
    }

    /**
     * Get the default value.
     *
     * @return string|null
     */
    private function getDefault(): ?string
    {
        return $this->parameters['default'];
    }

    /**
     * Generate the `default` SQL statement.
     *
     * @return string
     */
    private function getDefaultSyntax(): string
    {
        // Timestamp/Datetime columns support CURRENT_TIMESTAMP and ON UPDATE CURRENT_TIMESTAMP
        if ('TIMESTAMP' === $this->parameters['type'] || 'DATETIME' === $this->parameters['type']) {
            $syntax = ' DEFAULT ' . (($this->parameters['default_current_timestamp']) ? 'CURRENT_TIMESTAMP' : 'NULL');
            if ($this->parameters['auto_update_timestamp']) {
                $syntax .= ' ON UPDATE CURRENT_TIMESTAMP';
            }
            return $syntax;
        }

        // Nullable columns or columns with null default get DEFAULT NULL
        if ($this->parameters['nullable'] || null === $this->parameters['default']) {
            return ' DEFAULT NULL';
        }

        // Columns with explicit default values get an escaped string literal
        if (null !== $this->parameters['default']) {
            return ' DEFAULT \'' . \addslashes($this->parameters['default']) . '\'';
        }

        return '';
    }

    /**
     * Get the charset of the column.
     *
     * @return string
     */
    private function getCharset(): string
    {
        return $this->parameters['charset'] ?? '';
    }

    /**
     * Get the collation of the column.
     *
     * @return string
     */
    private function getCollation(): string
    {
        return $this->parameters['collation'] ?? '';
    }

    /**
     * Generate the `charset` SQL statement.
     *
     * @return string
     */
    private function getCharsetSyntax(): string
    {
        $syntax = '';
        // Charset and collation only apply to text-based column types
        if (\preg_match('/^TEXT|CHAR|VARCHAR$/', $this->parameters['type'])) {
            if ($this->parameters['charset'] ?? '') {
                $syntax .= ' CHARACTER SET ' . $this->parameters['charset'];
            }

            if ($this->parameters['collation'] ?? '') {
                $syntax .= ' COLLATE ' . $this->parameters['collation'];
            }
        }

        return $syntax;
    }

    /**
     * Generate the `type` and `length` SQL statement.
     *
     * @return string
     */
    private function getTypeSyntax(): string
    {
        $length = '';
        // Integer, char, and text types use a simple length specifier
        if (\preg_match('/^(?:VAR)?CHAR|(TINY|MEDIUM|LONG)?TEXT|(?:VAR)?BINARY|(SMALL|TINY|MEDIUM|BIG)?INT|BLOB$/', $this->parameters['type'])) {
            $length = $this->parameters['length'];
        } elseif (\preg_match('/^(REAL|DOUBLE|FLOAT|DEC(IMAL)?|NUMERIC|FIXED)$/i', $this->parameters['type'])) {
            // Floating-point/decimal types use precision,scale format
            $length = $this->parameters['length'] . ',' . $this->parameters['decimal_points'];
        } else {
            // YEAR type only supports length of 2 or 4, default to 4
            if ('YEAR' === $this->parameters['type']) {
                if (2 !== $this->parameters['length'] && 4 !== $this->parameters['length']) {
                    $length = 4;
                }
            }
        }

        return ' ' . $this->parameters['type'] . (($length) ? '(' . $length . ')' : '');
    }

    /**
     * Get the nullable value.
     *
     * @return string
     */
    private function getNullableSyntax(): string
    {
        return ($this->parameters['nullable']) ? ' NULL' : ' NOT NULL';
    }

    /**
     * Set the foreign key reference to another table and column.
     *
     * @param string $table The referenced table name
     * @param string $column The referenced column name (defaults to this column's name)
     *
     * @return static
     */
    private function setReference(string $table, string $column = ''): static
    {
        $table = \trim($table, '`');
        $column = \trim($column, '`');
        $this->parameters['reference_table'] = $table;
        $this->parameters['reference_column'] = (!$column || $column === $this->name) ? '' : $column;

        return $this;
    }

    /**
     * Enable or disable automatic timestamp update on row modification (ON UPDATE CURRENT_TIMESTAMP).
     *
     * @param bool $enable Whether to enable auto-update timestamp
     *
     * @return static
     */
    private function updateCurrentTimestamp(bool $enable): static
    {
        $this->parameters['auto_update_timestamp'] = $enable;
        return $this;
    }
}
