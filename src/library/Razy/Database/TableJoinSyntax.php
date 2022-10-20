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
use Razy\SimpleSyntax;
use Throwable;

class TableJoinSyntax
{
    private const JOIN_TYPE = [
        '<<' => 'LEFT OUTER JOIN',
        '>>' => 'RIGHT OUTER JOIN',
        '<'  => 'LEFT JOIN',
        '>'  => 'RIGHT JOIN',
        '-'  => 'JOIN',
        '*'  => 'CROSS JOIN',
    ];

    /**
     * The storage of extracted syntax
     *
     * @var array
     */
    private array $extracted = [];

    /**
     * The Statement entity
     *
     * @var Statement
     */
    private Statement $statement;

    /**
     * The storage of table alias
     *
     * @var Statement[]
     */
    private array $tableAlias = [];

    /**
     * TableJoinSyntax constructor.
     *
     * @param Statement $statement
     */
    public function __construct(Statement $statement)
    {
        $this->statement = $statement;
    }

    /**
     * Get or create the TableJoinSyntax instance by the name, it will replace the table name as a sub query in the
     * Table Join Syntax.
     *
     * @param string $tableName
     *
     * @return Statement
     */
    public function getAlias(string $tableName): Statement
    {
        if (!isset($this->tableAlias[$tableName])) {
            $this->tableAlias[$tableName] = new Statement($this->statement->getDatabase());
        }

        return $this->tableAlias[$tableName];
    }

    /**
     * Generate the FROM statement.
     *
     * @return string
     * @throws Throwable
     */
    public function getSyntax(): string
    {
        $parser = function (array &$extracted, string &$source = '') use (&$parser) {
            $parsed = [];
            $join   = '';
            while ($clip = array_shift($extracted)) {
                if (is_array($clip)) {
                    $alias     = '';
                    $syntax    = '(' . $parser($clip, $alias) . ')';
                    $condition = array_shift($extracted);
                    if (!$condition) {
                        throw new Error('Invalid Table Join Syntax.');
                    }
                    $condition = $this->parseCondition($condition, $source, $alias);
                    $parsed[]  = $syntax . (($condition) ? ' ' . $condition : '');
                } else {
                    if (preg_match('/^(?:([a-z]\w*)\.)?(?:([a-z]\w*)|`((?:(?:\\\\.(*SKIP)(*FAIL)|.)+|\\\\[\\\\`])+)`)(\[[?:]?.+])?$/', $clip, $matches)) {
                        $table = $matches[3] ?: $matches[2];

                        if (isset($this->tableAlias[$table])) {
                            $statement = $this->tableAlias[$table];
                            $tableName = '(' . $statement->getSyntax() . ')';
                            if (!$matches[1]) {
                                throw new Error('Inner SELECT syntax must given an alias.');
                            }
                            $alias = '`' . $matches[1] . '`';
                        } else {
                            $tableName = '`' . $this->statement->getPrefix() . $table . '`';
                            $alias     = ($matches[1]) ? '`' . $matches[1] . '`' : $tableName;
                        }
                        $parsed[]  = $tableName . ($matches[1] ? ' AS `' . $matches[1] . '`' : '');
                        $condition = $matches[4] ?? '';

                        if (!$source) {
                            if ($condition) {
                                throw new Error('Invalid Table Join Syntax.');
                            }
                            $source = $alias;
                        } else {
                            if (('+' == $join && $condition) || ('+' !== $join && !$condition)) {
                                throw new Error('Invalid Table Join Syntax.');
                            }
                            if ($condition) {
                                $parsed[] = $this->parseCondition($condition, $source, $alias);
                            }
                        }
                    } else {
                        throw new Error('Invalid Table Join Syntax.');
                    }
                }

                $join = array_shift($extracted);
                if (preg_match('/[\-><+]/', $join)) {
                    $parsed[] = self::JOIN_TYPE[$join];
                } else {
                    if (count($extracted)) {
                        throw new Error('Invalid Table Join Syntax.');
                    }
                }
            }

            return implode(' ', $parsed);
        };

        $extracted = $this->extracted;

        return $parser($extracted);
    }

    /**
     * Parse the Where Simple Syntax in TableJoin condition.
     *
     * @param string $syntax
     * @param string $source
     * @param string $alias
     *
     * @return string
     * @throws Error
     * @throws Throwable
     */
    private function parseCondition(string $syntax, string $source, string $alias): string
    {
        if (preg_match('/^\[(\?|([a-z]\w*)?:)?(.+)]$/', $syntax, $matches)) {
            $condition = trim($matches[3]);
            $type      = '';
            if ($matches[1]) {
                $type = ($matches[1] == '?') ? '?' : ':';
            }
            $source = $matches[2] ? '`' . $matches[2] . '`': $source;

            if (!$condition) {
                throw new Error('Invalid Syntax.');
            }

            if ('?' == $type) {
                $whereSyntax = new WhereSyntax($this->statement);
                $whereSyntax->parseSyntax($condition);

                return 'ON ' . $whereSyntax->getSyntax();
            }
            $columns = preg_split('/(?:(?<q>[\'"`])(?:\\\\.(*SKIP)|(?!\k<q>).)*\k<q>|\\\\.)(*SKIP)(*FAIL)|\s*,\s*/', $condition);

            foreach ($columns as &$column) {
                $column = trim($column);
                if (!preg_match('/^(?:`(?:(?:\\\\.(*SKIP)(*FAIL)|.)+|\\\\[\\\\`])+`|[a-z]\w*)$/', $column)) {
                    throw new Error('USING clause only allow column name.');
                }

                if (!$type) {
                    $column = $source . '.`' . $column . '` = ' . $alias . '.`' . $column . '`';
                }
            }

            if (':' === $type) {
                return 'USING (' . implode(', ', $columns) . ')';
            }

            return 'ON ' . implode(' AND ', $columns);
        }

        throw new Error('invalid condition syntax');
    }

    /**
     * Parse the TableJoin Simple Syntax.
     *
     * @param string $syntax
     *
     * @return $this
     * @throws Error
     */
    public function parseSyntax(string $syntax): TableJoinSyntax
    {
        $syntax          = trim($syntax);
        $this->extracted = SimpleSyntax::parseSyntax($syntax, '-<>+');

        return $this;
    }
}
