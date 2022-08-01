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

use Closure;
use Razy\Database;
use Razy\Error;
use Razy\SimpleSyntax;
use Throwable;
use function preg_match;

class Statement
{
    /**
     * @var string
     */
    private string $type = '';

    /**
     * @var array
     */
    private array $selectColumns;

    /**
     * @var ?WhereSyntax
     */
    private ?WhereSyntax $whereSyntax = null;

    /**
     * @var ?WhereSyntax
     */
    private ?WhereSyntax $havingSyntax = null;

    /**
     * @var ?TableJoinSyntax
     */
    private ?TableJoinSyntax $tableJoinSyntax = null;

    /**
     * @var Database
     */
    private Database $database;

    /**
     * @var string
     */
    private string $tableName;

    /**
     * @var string
     */
    private string $sql;

    /**
     * @var array
     */
    private array $parameters = [];

    /**
     * @var int
     */
    private int $fetchLength = 0;

    /**
     * @var int
     */
    private int $position = 0;

    /**
     * @var array
     */
    private array $columns = [];

    /**
     * @var array
     */
    private array $updateSyntax = [];

    /**
     * @var array
     */
    private array $groupby = [];

    /**
     * @var array
     */
    private array $orderby = [];

    /**
     * @var null|Closure
     */
    private ?Closure $parser = null;

    /**
     * @var bool
     */
    private bool $once = false;

    /**
     * Statement constructor.
     *
     * @param Database $database The Database instance
     * @param string   $sql      A string of the SQL statement
     */
    public function __construct(Database $database, string $sql = '')
    {
        $this->sql = trim($sql);
        if ($sql) {
            $this->type = 'sql';
        }
        $this->database      = $database;
        $this->selectColumns = ['*'];
    }

    /**
     * @param string $parameter
     * @param array  $columns
     *
     * @throws Error
     *
     * @return string
     */
    public static function GetSearchTextSyntax(string $parameter, array $columns): string
    {
        $syntax    = [];
        $parameter = trim($parameter);
        if (!$parameter) {
            throw new Error('The parameter is required');
        }
        foreach ($columns as $column) {
            $column = self::StandardizeColumn($column);
            if ($column) {
                $syntax[] = $column . '*=:' . $parameter;
            }
        }

        return (count($syntax)) ? implode('|', $syntax) : '';
    }

    /**
     * Standardize the column string.
     *
     * @param string $column
     *
     * @return string
     */
    public static function StandardizeColumn(string $column): string
    {
        $column = trim($column);
        if (preg_match('/^((\`(?:(?:\\\\.(*SKIP)(*FAIL)|.)++|\\\\[\\\\`])+`|[a-z]\w*)(?:\.((?2)))?)(?:(->>?)([\'"])\$(.+)\5)?$/', $column, $matches)) {
            if (isset($matches[3]) && preg_match('/^[a-z]\w*$/', $matches[3])) {
                return $matches[2] . '.`' . trim($matches[3]) . '`';
            }
            if (preg_match('/^[a-z]\w*$/', $matches[2])) {
                return '`' . trim($matches[2]) . '`';
            }
        }

        return '';
    }

    /**
     * Get the table prefix.
     *
     * @return string
     */
    public function getPrefix(): string
    {
        return $this->database->getPrefix();
    }

    /**
     * @return Database
     */
    public function getDatabase(): Database
    {
        return $this->database;
    }

    /**
     * Functional SELECT syntax.
     *
     * @param string $columns a set of columns to be generated as SELECT expression
     *
     * @return $this
     */
    public function select(string $columns): Statement
    {
        $this->selectColumns = preg_split('/(?:(?<q>[\'"`])(?:\\\\.(*SKIP)|(?!\k<q>).)*\k<q>|\\\\.)(*SKIP)(*FAIL)|\s*,\s*/', $columns);

        foreach ($this->selectColumns as &$column) {
            $column = (preg_match('/^\w+$/', $column)) ? '`' . $column . '`' : $column;
        }

        return $this;
    }

    /**
     * Get or create the TableJoinSyntax instance by the name, it will replace the table name as a sub query in the
     * Table Join Syntax.
     *
     * @param string $tableName the table will be replaced
     *
     * @return null|Statement
     */
    public function alias(string $tableName): ?Statement
    {
        return ($this->tableJoinSyntax) ? $this->tableJoinSyntax->getAlias($tableName) : null;
    }

    /**
     * Functional FROM syntax with TableJoin Simple Syntax.
     *
     * @param string $syntax A well formatted TableJoin Simple Syntax
     *
     *@throws Error
     *
     * @return $this
     */
    public function from(string $syntax): Statement
    {
        if (!$this->tableJoinSyntax) {
            $this->tableJoinSyntax = new TableJoinSyntax($this);
        }
        $this->tableJoinSyntax->parseSyntax($syntax);
        $this->type = 'select';

        return $this;
    }

    /**
     * Execute the statement and fetch the first result.
     *
     * @param array $parameters
     *
     * @throws Throwable
     *
     * @return mixed
     */
    public function lazy(array $parameters = [])
    {
        $result = $this->query($parameters)->fetch();
        if ($result) {
            $parser = $this->parser;
            if (is_callable($parser)) {
                $parser($result);
            }
        }

        // If the parser set only execute once, reset the parser.
        if ($this->once) {
            $this->once   = false;
            $this->parser = null;
        }

        return $result;
    }

    /**
     * Execute the statement and return the Query instance.
     *
     * @param array $parameters
     *
     * @throws Throwable
     *
     * @return Query
     */
    public function query(array $parameters = []): Query
    {
        if (count($parameters)) {
            $this->parameters = array_merge($this->parameters, $parameters);
        }

        return $this->database->execute($this);
    }

    /**
     * Execute the statement and return the result by group if the column is given.
     *
     * @param array  $parameters An array contains the parameter
     * @param string $column     The key of the group result
     * @param bool   $stackable  Group all result with same column into an array
     *
     * @throws Throwable
     *
     * @return array Return an array of result
     */
    public function &lazyGroup(array $parameters = [], string $column = '', bool $stackable = false): array
    {
        $result = [];
        $query  = $this->query($parameters);
        while ($row = $query->fetch()) {
            $parser = $this->parser;
            if (is_callable($parser)) {
                $parser($row);
            }

            if (!$column || !array_key_exists($column, $row)) {
                $result[] = $row;
            } else {
                if ($stackable) {
                    if (!isset($result[$row[$column]])) {
                        $result[$row[$column]] = [];
                    }
                    $result[$row[$column]][] = $row;
                } else {
                    $result[$row[$column]] = $row;
                }
            }
        }

        // If the parser set only execute once, reset the parser.
        if ($this->once) {
            $this->once   = false;
            $this->parser = null;
        }

        return $result;
    }

    /**
     * Assign parameters for generating SQL statement.
     *
     * @param array $parameters
     *
     * @return $this
     */
    public function assign(array $parameters = []): Statement
    {
        $this->parameters = $parameters;

        return $this;
    }

    /**
     * Set the parser used to parse the fetching data.
     *
     * @param Closure $closure
     * @param bool    $once
     *
     * @return Statement
     */
    public function setParser(Closure $closure, bool $once = false): Statement
    {
        $this->parser = $closure;
        $this->once   = $once;

        return $this;
    }

    /**
     * Set the fetch count and position.
     *
     * @param int $position
     * @param int $fetchLength
     *
     * @return Statement
     */
    public function limit(int $position, int $fetchLength = 0): Statement
    {
        $this->fetchLength = $fetchLength;
        $this->position    = $position;

        return $this;
    }

    /**
     * Set the order by syntax.
     *
     * @param string $syntax
     *
     * @return $this
     */
    public function order(string $syntax): Statement
    {
        $clips = preg_split('/\s*,\s*/', $syntax);

        $this->orderby = [];
        foreach ($clips as $column) {
            $ordering = '';
            if (preg_match('/^([<>])?(.+)/', $column, $matches)) {
                $ordering = $matches[1];
                $column   = $matches[2];
            }

            $column = self::StandardizeColumn($column);
            if ($column) {
                $column .= ('>' === $ordering) ? ' DESC' : ' ASC';
                $this->orderby[] = $column;
            }
        }

        return $this;
    }

    /**
     * Set the group by syntax.
     *
     * @param string $syntax
     *
     * @return $this
     */
    public function group(string $syntax): Statement
    {
        $clips = preg_split('/\s*,\s*/', $syntax);

        $this->groupby = [];
        foreach ($clips as &$column) {
            $column          = self::StandardizeColumn($column);
            $this->groupby[] = trim($column);
        }

        return $this;
    }

    /**
     * Generate the SQL statement.
     *
     * @throws Throwable
     *
     * @return string
     */
    public function getSyntax(): string
    {
        if ('select' === $this->type) {
            if (!$this->tableJoinSyntax) {
                throw new Error('No Table Join Syntax is provided.');
            }
            $tableJoinSyntax = $this->tableJoinSyntax->getSyntax();

            $sql = 'SELECT ' . implode(', ', $this->selectColumns) . ' FROM ' . $tableJoinSyntax;
            if ($this->whereSyntax) {
                $syntax = $this->whereSyntax->getSyntax();
                if ($syntax) {
                    $sql .= ' WHERE ' . $syntax;
                }
            }

            if (count($this->groupby)) {
                $sql .= ' GROUP BY ' . implode(', ', $this->groupby);
            }

            if ($this->havingSyntax) {
                $syntax = $this->havingSyntax->getSyntax();
                if ($syntax) {
                    $sql .= ' HAVING ' . $syntax;
                }
            }

            if (count($this->orderby)) {
                $sql .= ' ORDER BY ' . implode(', ', $this->orderby);
            }

            if ($this->fetchLength > 0) {
                $sql .= ' LIMIT ' . $this->position . ', ' . $this->fetchLength;
            }

            return $sql;
        }

        if ('update' == $this->type) {
            if ($this->tableName && !empty($this->updateSyntax)) {
                $updateSyntax = [];
                foreach ($this->updateSyntax as $column => $syntax) {
                    $updateSyntax[] = '`' . $column . '` = ' . $this->getUpdateSyntax($syntax, $column);
                }

                $sql = 'UPDATE ' . $this->tableName . ' SET ' . implode(', ', $updateSyntax);
                if ($this->whereSyntax) {
                    $syntax = $this->whereSyntax->getSyntax();
                    if ($syntax) {
                        $sql .= ' WHERE ' . $syntax;
                    }
                }

                return $sql;
            }

            throw new Error('Missing update syntax.');
        } elseif ('insert' == $this->type) {
            if ($this->tableName && !empty($this->columns)) {
                $sql    = 'INSERT INTO ' . $this->tableName . ' (`' . implode('`, `', $this->columns) . '`) VALUES (';
                $values = [];
                foreach ($this->columns as $column) {
                    $values[] = $this->getValueAsStatement($column);
                }
                $sql .= implode(', ', $values) . ')';

                return $sql;
            }

            throw new Error('Invalid insert statement or no columns has provided.');
        }

        return $this->sql;
    }

    /**
     * Get the assigned parameter value by given name.
     *
     * @param string $name
     *
     * @return null|mixed
     */
    public function getValue(string $name)
    {
        return $this->parameters[$name] ?? null;
    }

    /**
     * Functional WHERE syntax with Where Simple Syntax.
     *
     * @param string $syntax The well formatted Where Simple Syntax
     *
     *@throws Error
     *
     * @return Statement
     */
    public function where(string $syntax): Statement
    {
        if (!$this->whereSyntax) {
            $this->whereSyntax = new WhereSyntax($this);
        }
        $this->whereSyntax->parseSyntax($syntax);

        return $this;
    }

    /**
     * Set the Statement as an insert statement.
     *
     * @param string $tableName The table name will be inserted a new record
     * @param array  $columns   A set of columns to insert
     *
     *@throws Error
     *
     * @return $this
     */
    public function insert(string $tableName, array $columns): Statement
    {
        $this->type = 'insert';
        $tableName  = trim($tableName);
        if (!$tableName) {
            throw new Error('The table name cannot be empty.');
        }
        $this->tableName = $this->getPrefix() . $tableName;

        foreach ($columns as $index => &$column) {
            $column = trim($column);
            if (preg_match('/^(?:`(?:\\\\.(*SKIP)(*FAIL)|.)+`|[a-z]\w*)$/', $column)) {
                $column = trim($column, '`');
            } else {
                unset($columns[$index]);
            }
        }

        $this->columns = $columns;

        return $this;
    }

    /**
     * Set the Statement as a update statement.
     *
     * @param string $tableName    The table name will be updated record
     * @param array  $updateSyntax A set of Update Simple Syntax
     *
     * @throws Error
     *
     * @return $this
     */
    public function update(string $tableName, array $updateSyntax): Statement
    {
        $this->type = 'update';
        $tableName  = trim($tableName);
        if (!$tableName) {
            throw new Error('The table name cannot be empty.');
        }
        $this->tableName = $this->getPrefix() . $tableName;

        $this->updateSyntax = [];
        foreach ($updateSyntax as &$syntax) {
            $syntax = trim($syntax);
            if (preg_match('/(?:(?<q>[\'"])(?:\\\\.(*SKIP)|(?!\k<q>).)*\k<q>|(?<w>\[)(?:\\\\.(*SKIP)|[^\[\]])*]|\\\\.)(*SKIP)(*FAIL)|^(`(?:\\\\.(*SKIP)(*FAIL)|.)+`|[a-z]\w*)(?:(\+\+|--)|\s*([+\-*\/&]?=)\s*(.+))?$/', $syntax, $matches)) {
                $matches[3] = trim($matches[3], '`');

                if (isset($matches[4]) && $matches[4]) {
                    $this->updateSyntax[$matches[3]] = ['`' . $matches[3] . '`', ('++' === $matches[4]) ? '+' : '-', 1];
                } elseif (isset($matches[6])) {
                    $shortenSyntax = [];
                    if (2 === strlen($matches[5])) {
                        $operator      = $matches[5][0];
                        $shortenSyntax = ['`' . $matches[3] . '`', $operator];
                    }
                    $this->updateSyntax[$matches[3]] = array_merge($shortenSyntax, $this->parseSyntax($matches[6]));
                } else {
                    $this->updateSyntax[$matches[3]] = [':' . $matches[3]];
                }
            }
        }

        if (empty($this->updateSyntax)) {
            throw new Error('There is no update syntax will be executed.');
        }

        return $this;
    }

    /**
     * Generate the UPDATE statement.
     *
     * @param array  $updateSyntax
     * @param string $column
     *
     * @throws Error
     *
     * @return string
     */
    private function getUpdateSyntax(array $updateSyntax, string $column): string
    {
        $parser = function (array &$extracted) use (&$parser, $column) {
            $parsed  = [];
            $operand = '';
            while ($clip = array_shift($extracted)) {
                if (is_array($clip)) {
                    if ('&' === $operand) {
                        $merged = implode(', ', $parsed) . ', ';
                        $parsed = ['CONCAT(' . $merged . $parser($clip) . ')'];
                    } else {
                        $parsed[] = '(' . $parser($clip) . ')';
                    }
                } else {
                    if (!preg_match('/^[+\-*\/&]$/', $clip)) {
                        $matches = null;
                        if ('?' === $clip || preg_match('/^:(\w+)$/', $clip, $matches)) {
                            $clip = $this->getValueAsStatement(('?' === $clip) ? $column : $matches[1]);
                        } else {
                            $clip = preg_replace_callback('/(?:(?<q>[\'"`])(?:\\\\.(*SKIP)|(?!\k<q>).)*\k<q>|(?<w>\[)(?:\\\\.(*SKIP)|[^\[\]])*]|\\\\.)(*SKIP)(*FAIL)|:(\w+)/', function ($matches) {
                                return $this->getValueAsStatement($matches[3]);
                            }, $clip);
                        }

                        if ('&' === $operand) {
                            $merged = implode(', ', $parsed) . ', ';
                            $parsed = ['CONCAT(' . $merged . $clip . ')'];
                        } else {
                            $parsed[] = $clip;
                        }
                    } else {
                        throw new Error('Cannot generate the update statement because the Update Simple Syntax is not correct.');
                    }
                }

                $operand = array_shift($extracted);
                if ($operand) {
                    if (!is_string($operand) || !preg_match('/^[+\-*\/&]$/', $operand)) {
                        throw new Error('Cannot generate the update statement because the Update Simple Syntax is not correct.');
                    }

                    if ('&' !== $operand) {
                        $parsed[] = $operand;
                    }
                }
            }

            return implode(' ', $parsed);
        };

        $extracted = $updateSyntax;

        return $parser($extracted);
    }

    /**
     * Get the value as a string for statement.
     *
     * @param string $column
     *
     * @return string
     */
    private function getValueAsStatement(string $column): string
    {
        $value = $this->getValue($column);
        if (is_scalar($value)) {
            return (is_string($value)) ? '\'' . addslashes($value) . '\'' : $value;
        }

        return '\'\'';
    }

    /**
     * Parse the update syntax.
     *
     * @param string $syntax
     *
     *@throws Error
     *
     * @return array
     */
    private function parseSyntax(string $syntax): array
    {
        $syntax = trim($syntax);

        return SimpleSyntax::ParseSyntax($syntax, '+-*/&');
    }
}
