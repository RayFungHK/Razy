<?php

/*
 * This file is part of Razy v0.4.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Database;

use Razy\Error;
use function Razy\guid;

class Column
{
    /**
     * The Column's unique id
     * @var string
     */
    private string $id;
    /**
     * The Column name
     * @var string
     */
    private string $name;

    /**
     * The storage of the parameters
     * @var array
     */
    private array $parameters = [];
    /**
     * The Table entity
     * @var null|Table
     */
    private ?Table $table;

    /**
     * The column's comment
     * @var string
     */
    private string $comment = '';

    /**
     * Column constructor.
     *
     * @param string $columnName
     * @param string $configSyntax
     * @param null|Table $table
     *
     * @throws Error
     */
    public function __construct(string $columnName, string $configSyntax = '', ?Table $table = null)
    {
        $columnName   = trim($columnName);
        $configSyntax = trim($configSyntax);
        if (!preg_match('/^(`(?:(?:\\\\.(*SKIP)(*FAIL)|.)++|\\\\[\\\\`])+`|[a-z]\w*)$/', $columnName)) {
            throw new Error('The column name ' . $columnName . ' is not in a correct format,');
        }

        $this->parameters = [
            'type'         => 'VARCHAR',
            'length'       => 255,
            'default'      => '',
            'nullable'     => false,
            'insert_after' => '',
        ];

        $this->name  = $columnName;
        $this->table = $table;
        if ($configSyntax) {
            $parameters = $this->parseSyntax($configSyntax);
            $this->configure($parameters);
        }
        $this->id = guid();
    }

    /**
     * Parse the column syntax.
     *
     * @param string $syntax
     * @return array
     */
    private function parseSyntax(string $syntax): array
    {
        $parameters = [];
        $clips      = preg_split('/(?:\\\\.|\((?:\\\\.(*SKIP)|[^()])*\)|(?<q>[\'"])(?:\\\\.(*SKIP)|(?!\k<q>).)*\k<q>)(*SKIP)(*FAIL)|\s*,\s*/', $syntax, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($clips as $clip) {
            if (preg_match('/^(\w+)(?:\(((?:\\.(*SKIP)|[^()])*)\))?/', $clip, $matches)) {
                if (!isset($parameters[$matches[1]])) {
                    $parameters[$matches[1]] = null;
                }

                if ($matches[2] ?? '') {
                    $parameters[$matches[1]] = [];
                    while (preg_match('/^(?:\A|,)(\w+|(\d+(?:\.\d+)?)|(?<q>[\'"])((?:\\\\.(*SKIP)|(?!\k<q>).)*)\k<q>)/', $matches[2], $extracted)) {
                        $parameters[$matches[1]][] = $extracted[4] ?? $extracted[1];
                        $matches[2]                = substr($matches[2], strlen($extracted[0]));
                    }
                }
            }
        }

        return $parameters;
    }

    /**
     * Configure the column by an array or parameters.
     *
     * @param array $parameters
     *
     * @return Column
     * @throws Error
     */
    public function configure(array $parameters): Column
    {
        foreach (['type', 'length', 'nullable', 'charset', 'collation', 'zerofill', 'create', 'key', 'oncurrent', 'default'] as $method) {
            if (!array_key_exists($method, $parameters)) {
                continue;
            }
            $arguments = $parameters[$method] ?? null;
            if ('type' == $method) {
                $this->setType((!$arguments || !is_scalar($arguments[0])) ? 'text' : $arguments[0]);
            } elseif ('length' == $method) {
                if (null !== $arguments) {
                    $arguments[0] = max(1, $arguments[0] ?? 1);
                    $arguments[1] = max(0, $arguments[1] ?? 0);
                    $this->setLength($arguments[0], $arguments[1]);
                }
            } elseif ('zerofill' == $method) {
                $this->isZerofill(true);
            } elseif ('nullable' == $method) {
                $this->setNullable();
            } elseif ('charset' == $method) {
                if (null !== $arguments && isset($arguments[0])) {
                    $this->setCharset($arguments[0]);
                }
            } elseif ('collation' == $method) {
                if (null !== $arguments && isset($arguments[0])) {
                    $this->setCollation($arguments[0]);
                }
            } elseif ('default' == $method) {
                if (null !== $arguments && isset($arguments[0]) && is_string($arguments[0])) {
                    $this->setDefault($arguments[0]);
                } else {
                    $this->setDefault(null);
                }
            } elseif ('key' == $method) {
                $this->setKey((isset($parameters[$method])) ? $arguments[0] ?? '' : '');
            } elseif ('oncurrent' == $method) {
                $this->defaultCurrentTimestamp(true);
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
     * @throws Error
     *
     */
    public function setType(string $type): Column
    {
        $type = strtolower(trim($type));
        if (!$type) {
            throw new Error('The column data type cannot be empty.');
        }

        $this->parameters['default'] = '';
        if ('auto_id' === $type || 'auto' === $type || 'auto_increment' === $type) {
            $this->parameters['type']           = 'INT';
            $this->parameters['auto_increment'] = true;
            $this->parameters['key']            = 'primary';
            $this->parameters['length']         = 8;
            $this->parameters['default']        = '0';
        } elseif ('text' === $type) {
            $this->parameters['type']    = 'VARCHAR';
            $this->parameters['length']  = 255;
            $this->parameters['default'] = '';
        } elseif ('full_text' === $type) {
            $this->parameters['type']    = 'TEXT';
            $this->parameters['length']  = '';
            $this->parameters['default'] = '';
        } elseif ('long_text' === $type) {
            $this->parameters['type']    = 'LONGTEXT';
            $this->parameters['length']  = '';
            $this->parameters['default'] = '';
        } elseif ('int' === $type) {
            $this->parameters['type']    = 'INT';
            $this->parameters['length']  = 8;
            $this->parameters['default'] = '0';
        } elseif ('bool' === $type || 'boolean' === $type) {
            $this->parameters['type']    = 'TINYINT';
            $this->parameters['length']  = 1;
            $this->parameters['default'] = '0';
        } elseif ('decimal' === $type || 'money' === $type || 'float' === $type || 'real' === $type || 'double' === $type) {
            $this->parameters['type']           = ('decimal' === $type || 'money' === $type) ? 'DECIMAL' : strtoupper($type);
            $this->parameters['length']         = 8;
            $this->parameters['decimal_points'] = 2;
            $this->parameters['default']        = '0';
        } elseif ('timestamp' === $type) {
            $this->parameters['type']     = 'TIMESTAMP';
            $this->parameters['default']  = null;
            $this->parameters['nullable'] = true;
        } elseif ('datetime' === $type) {
            $this->parameters['type']     = 'DATETIME';
            $this->parameters['default']  = null;
            $this->parameters['nullable'] = true;
        } elseif ('date' === $type) {
            $this->parameters['type']     = 'DATE';
            $this->parameters['default']  = null;
            $this->parameters['nullable'] = true;
        } elseif ('json' === $type) {
            $this->parameters['type']     = 'JSON';
            $this->parameters['default']  = '{}';
            $this->parameters['nullable'] = true;
        } else {
            $this->parameters['type'] = strtoupper($type);
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
    public function setLength(int $length, int $decPoints = 0): Column
    {
        $length                             = max(1, $length);
        $decPoints                          = max(0, min($length - 1, $decPoints));
        $this->parameters['length']         = $length;
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
    public function isZerofill(bool $enable): Column
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
    public function setNullable(bool $enable = true): Column
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
     * @throws Error
     */
    public function setCharset(string $charset): Column
    {
        $charset = trim($charset);
        if ($charset && !preg_match('/^\w+$/', $charset)) {
            throw new Error($charset . ' is not in a correct character set format.');
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
     * @throws Error
     */
    public function setCollation(string $collation): Column
    {
        $charset = trim($collation);
        if ($charset && !preg_match('/\w+^$', $collation)) {
            throw new Error($collation . ' is not in a correct character set format.');
        }
        $this->parameters['collation'] = $collation;

        return $this;
    }

    /**
     * Set the default value of the column.
     *
     * @param null|string $value
     *
     * @return $this
     */
    public function setDefault(?string $value): Column
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
    public function setKey(string $type): Column
    {
        $type                    = strtolower(trim($type));
        $this->parameters['key'] = (preg_match('/^primary|index|unique|fulltext|spatial$/', $type)) ? $type : null;

        return $this;
    }

    /**
     * Set the column's comment.
     *
     * @param string $comment
     *
     * @return $this
     */
    public function setComment(string $comment): Column
    {
        $this->comment = $comment;

        return $this;
    }

    /**
     * Enable set current timestamp as default value when the column is a date/time/timestamp
     *
     * @param bool $enable
     *
     * @return $this
     */
    public function defaultCurrentTimestamp(bool $enable): Column
    {
        $this->parameters['auto_current_timestamp'] = $enable;
        return $this;
    }

    /**
     * Generate the alter SQL statement by the different with specified column.
     *
     * @param Column $column
     * @param bool $reordered
     *
     * @return string
     */
    public function alter(Column $column, bool $reordered = false): string
    {
        $changes = '';
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
     * Get the default value.
     *
     * @return null|string
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
        if ('TIMESTAMP' === $this->parameters['type'] || 'DATETIME' === $this->parameters['type']) {
            return ' DEFAULT ' . (($this->parameters['auto_current_timestamp']) ? 'CURRENT_TIMESTAMP()' : 'NULL');
        }

        if ($this->parameters['nullable'] || null === $this->parameters['default']) {
            return ' DEFAULT NULL';
        }
        if (null !== $this->parameters['default']) {
            return ' DEFAULT \'' . addslashes($this->parameters['default']) . '\'';
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
        if (preg_match('/^TEXT|CHAR|VARCHAR$/', $this->parameters['type'])) {
            if ($this->parameters['charset']) {
                $syntax .= ' CHARACTER SET ' . $this->parameters['charset'];
            }

            if ($this->parameters['collation']) {
                $syntax .= ' COLLATE ' . $this->parameters['collation'];
            }
        }

        return $syntax;
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
     * Generate the `type` and `length` SQL statement.
     *
     * @return string
     */
    private function getTypeSyntax(): string
    {
        $length = '';
        if (preg_match('/^(?:VAR)?CHAR|(TINY|MEDIUM|LONG)?TEXT|(?:VAR)?BINARY|(SMALL|TINY|MEDIUM|BIG)?INT|BLOB$/', $this->parameters['type'])) {
            $length = $this->parameters['length'];
        } elseif (preg_match('/^(REAL|DOUBLE|FLOAT|DEC(IMAL)?|NUMERIC|FIXED)$/i', $this->parameters['type'])) {
            $length = $this->parameters['length'] . ',' . $this->parameters['decimal_points'];
        } else {
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
     * @throws Error
     */
    public function setName(string $columnName): Column
    {
        $columnName = trim($columnName);
        if (!preg_match('/^(`(?:(?:\\\\.(*SKIP)(*FAIL)|.)++|\\\\[\\\\`])+`|[a-z]\w*)$/', $columnName)) {
            throw new Error('The column name ' . $columnName . ' is not in a correct format,');
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
     * @throws Error
     */
    public function bindTo(Table $table): Column
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
        $config     = '`' . $this->name . '`';
        $parameters = [];
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
                        $type = strtolower($this->parameters['type']);
                }
                $parameters[] = 'type(' . $type . ')';
            } elseif ('length' == $method) {
                $parameters[] = 'length(' . $this->parameters['length'] . ')';
            } elseif ('nullable' == $method) {
                if ($this->parameters['nullable'] ?? false) {
                    $parameters[] = 'nullable';
                }
            } elseif ('zerofill' == $method) {
                if (preg_match('/^(SMALL|MEDIUM|BIG)?INT$/', $this->parameters['type'])) {
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
                    $parameters[] = 'default("' . addslashes($this->parameters['default']) . '")';
                }
            } elseif ('key' == $method) {
                if (isset($this->parameters['key'])) {
                    $parameters[] = 'key(' . $this->parameters['key'] . ')';
                }
            } elseif ('oncurrent' == $method) {
                if ($this->parameters['oncurrent'] ?? false) {
                    $parameters[] = 'oncurrent';
                }
            }
        }
        if (!empty($parameters)) {
            $config .= '=' . implode(',', $parameters);
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
        if ($this->parameters['auto_increment'] ?? false) {
            $syntax .= ' INT(' . $this->parameters['length'] . ') NOT NULL AUTO_INCREMENT';
        } else {
            $syntax .= $this->getTypeSyntax() . $this->getNullableSyntax() . $this->getDefaultSyntax() . $this->getCharsetSyntax();
        }

        if ($this->comment) {
            $syntax .= " COMMENT '" . addslashes($this->comment) . "'";
        }

        return $syntax;
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
     * @throws Error
     */
    public function insertAfter(string $columnName = ''): Column
    {
        $columnName = trim($columnName);
        if ($columnName && !preg_match('/^(`(?:(?:\\\\.(*SKIP)(*FAIL)|.)++|\\\\[\\\\`])+`|[a-z]\w*)$/', $columnName)) {
            throw new Error('The column name ' . $columnName . ' is not in a correct format,');
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
}
