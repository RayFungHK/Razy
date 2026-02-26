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

use Razy\Exception\QueryException;
use Razy\SimpleSyntax;
use Throwable;

/**
 * Class TableJoinSyntax.
 *
 * Parses and generates the FROM clause of a SELECT statement using the
 * TableJoin Simple Syntax. Supports standard table references, table aliases,
 * sub-queries via aliased Statement objects, and multiple JOIN types
 * (INNER, LEFT, RIGHT, CROSS, LEFT/RIGHT OUTER). Join conditions can use
 * column lists (USING / ON), or full Where Simple Syntax via the [?...] prefix.
 *
 * @package Razy
 *
 * @license MIT
 */
class TableJoinSyntax
{
    /**
     * @var array<string, string> Maps join operator symbols to SQL JOIN keywords.
     *                            '<<' => LEFT OUTER JOIN, '>>' => RIGHT OUTER JOIN,
     *                            '<' => LEFT JOIN, '>' => RIGHT JOIN,
     *                            '-' => (INNER) JOIN, '*' => CROSS JOIN
     */
    private const JOIN_TYPE = [
        '<<' => 'LEFT OUTER JOIN',
        '>>' => 'RIGHT OUTER JOIN',
        '<' => 'LEFT JOIN',
        '>' => 'RIGHT JOIN',
        '-' => 'JOIN',
        '*' => 'CROSS JOIN',
    ];

    /** @var array Parsed table join tokens from SimpleSyntax::parseSyntax */
    private array $extracted = [];

    /** @var array<string, Statement> Table alias => sub-query Statement mappings */
    private array $tableAlias = [];

    /** @var array<string, Preset> Table alias => active Preset instances */
    private array $preset = [];

    /** @var string Raw syntax string, preserved for callable re-parsing */
    private string $syntax = '';

    /**
     * TableJoinSyntax constructor.
     *
     * @param Statement $statement
     */
    public function __construct(private readonly Statement $statement)
    {
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
     * Generate the complete FROM clause SQL string.
     * Recursively processes table references, sub-queries, join operators,
     * and join conditions into a valid SQL FROM clause.
     *
     * @return string The generated FROM clause
     *
     * @throws Throwable
     */
    public function getSyntax(): string
    {
        // Get driver-specific quote function for identifier escaping
        $driver = $this->statement->getDatabase()->getDriver();
        $quote = $driver
            ? fn ($name) => $driver->quoteIdentifier($name)
            : fn ($name) => '`' . $name . '`';

        $parser = function (array &$extracted, string &$source = '') use (&$parser, $quote) {
            $parsed = [];
            $join = '';
            while ($clip = \array_shift($extracted)) {
                if (\is_array($clip)) {
                    // Handle parenthesized sub-expression (sub-query or nested join)
                    $alias = '';
                    $syntax = '(' . $parser($clip, $alias) . ')';
                    $condition = \array_shift($extracted);
                    if (!$condition) {
                        throw new QueryException('Invalid Table Join Syntax.');
                    }
                    $condition = $this->parseCondition($condition, $source, $alias);
                    $parsed[] = $syntax . (($condition) ? ' ' . $condition : '');
                } else {
                    // Match table reference: optional alias.tableName or alias.`backtick-quoted`
                    if (\preg_match('/^(?:([a-z]\w*)\.)?(?:([a-z]\w*)|`((?:(?:\\\\.(*SKIP)(*FAIL)|.)+|\\\\[\\\\`])+)`)(\[[?:]?.+])?$/', $clip, $matches)) {
                        $table = (isset($matches[3]) && $matches[3]) ? $matches[3] : $matches[2];
                        $alias = ($matches[1]) ?: $table;

                        // Check if this alias maps to a sub-query Statement
                        if (isset($this->tableAlias[$alias])) {
                            $statement = $this->tableAlias[$alias];

                            $builder = $statement->getBuilder();
                            $builder?->build($table);

                            $tableName = '(' . $statement->getSyntax() . ')';
                            // Sub-query aliases are mandatory so the table can be referenced
                            if (!$matches[1]) {
                                throw new QueryException('Inner SELECT syntax must given an alias.');
                            }
                            $alias = $quote($matches[1]);
                        } else {
                            $tableName = $quote($this->statement->getPrefix() . $table);
                            $alias = ($matches[1]) ? $quote($matches[1]) : $tableName;
                        }
                        $parsed[] = $tableName . ($matches[1] ? ' AS ' . $quote($matches[1]) : '');

                        $condition = $matches[4] ?? '';

                        if (!$source) {
                            // First table in the join: cannot have a join condition
                            if ($condition) {
                                throw new QueryException('Invalid Table Join Syntax.');
                            }
                            $source = $alias;
                        } else {
                            // Subsequent tables: validate join conditions
                            // CROSS JOIN (+) must not have condition; other joins require one
                            if (('+' == $join && $condition) || ('+' !== $join && !$condition)) {
                                throw new QueryException('Invalid Table Join Syntax.');
                            }
                            if ($condition) {
                                $parsed[] = $this->parseCondition($condition, $source, $alias);
                            }
                        }
                    } else {
                        throw new QueryException('Invalid Table Join Syntax.');
                    }
                }

                // Consume the next join operator symbol and map to SQL keyword
                $join = \array_shift($extracted);
                if ($join && \preg_match('/[\-><+]/', $join)) {
                    $parsed[] = self::JOIN_TYPE[$join];
                } else {
                    if (\count($extracted)) {
                        throw new QueryException('Invalid Table Join Syntax.');
                    }
                }
            }

            return \implode(' ', $parsed);
        };

        $extracted = $this->extracted;

        return $parser($extracted);
    }

    /**
     * Parse the TableJoin Simple Syntax string into structured tokens.
     * Supports callable syntax for dynamic syntax generation, and detects
     * Preset class references in the format alias.table->PresetClass(args).
     *
     * @param string|callable $syntax The TableJoin syntax string or callable returning one
     *
     * @return $this
     *
     * @throws Error
     */
    public function parseSyntax(string|callable $syntax): self
    {
        if (\is_callable($syntax)) {
            $syntax = \call_user_func($syntax(...), $this->syntax);
        }

        if (!\is_string($syntax)) {
            throw new QueryException('Invalid syntax data type, only string is allowed.');
        }

        $this->syntax = \trim($syntax);

        // Parse the syntax using SimpleSyntax with join operators, and a custom callback
        // that detects Preset class references and initializes them
        $this->extracted = SimpleSyntax::parseSyntax($this->syntax, '-<>+', '', function ($syntax) {
            // Match: [alias.]table[->PresetClass(args)][condition] with backtick-quoted name support
            if (\preg_match('/^(?:([a-z]\w*)\.)?(?:([a-z]\w*)|`((?:(?:\\\\.(*SKIP)(*FAIL)|.)+|\\\\[\\\\`])+)`)(?:->(\w+)\((.+?)?\))?(\[[?:]?.+])?$/', $syntax, $matches)) {
                $table = (isset($matches[3]) && $matches[3]) ? $matches[3] : $matches[2];
                $alias = ($matches[1]) ?: $table;

                if (isset($matches[4])) {
                    // Detect Preset class reference: alias.table->ClassName(args)
                    $className = 'Razy\\Database\\Preset\\' . $matches[4];
                    if (\preg_match('/[a-z](\w+)?/i', $alias) && \class_exists($className)) {
                        $this->preset[$alias] = new $className($this->getAlias($alias), $table, $alias);
                    }
                }

                if (isset($this->preset[$alias])) {
                    $params = [];
                    if (\preg_match('/^,(?:(\w+)|(?<q>[\'"])((?:\\.(*SKIP)|(?!\k<q>).)*)\k<q>|(-?\d+(?:\.\d+)?))$/', ',' . $matches[5])) {
                        $params = SimpleSyntax::parseSyntax($matches[5], ',');
                        foreach ($params as &$param) {
                            $param = \trim($param, '\'"');
                        }
                    }
                    $this->preset[$alias]->init($params);

                    return $alias . '.' . $table . $matches[6];
                }
            }

            return $syntax;
        });

        return $this;
    }

    /**
     * Parse a join condition expression into an ON or USING clause.
     * Supports three formats:
     *   [?where_syntax]   - Full Where Simple Syntax ??ON clause
     *   [:col1,col2]      - Column list ??USING clause
     *   [col1,col2]       - Column list ??ON source.col = alias.col AND ...
     *   [alias:col1,col2]  - Column list with explicit source alias.
     *
     * @param string $syntax The bracketed condition string
     * @param string $source The source table alias/name
     * @param string $alias The joined table alias/name
     *
     * @return string The generated ON or USING clause
     *
     * @throws Error
     * @throws Throwable
     */
    private function parseCondition(string $syntax, string $source, string $alias): string
    {
        if (\preg_match('/^\[(\?|([a-z]\w*)?:)?(.+)]$/', $syntax, $matches)) {
            $condition = \trim($matches[3]);
            $type = '';

            // Determine condition type: '?' for WHERE syntax, ':' for USING/ON column list
            if ($matches[1]) {
                $type = ($matches[1] == '?') ? '?' : ':';
            }
            $source = $matches[2] ? '`' . $matches[2] . '`' : $source;

            if (!$condition) {
                throw new QueryException('Invalid Syntax.');
            }

            if ('?' == $type) {
                // Full WHERE syntax: parse as WhereSyntax and generate ON clause
                $whereSyntax = new WhereSyntax($this->statement);
                $whereSyntax->parseSyntax($condition);

                return 'ON ' . $whereSyntax->getSyntax();
            }
            $columns = \preg_split('/(?:(?<q>[\'"`])(?:\\\\.(*SKIP)|(?!\k<q>).)*\k<q>|\\\\.)(*SKIP)(*FAIL)|\s*,\s*/', $condition);

            if (':' === $type && !$matches[2]) {
                // USING clause: matching column names in both tables
                return 'USING (' . \implode(', ', $columns) . ')';
            }

            foreach ($columns as &$column) {
                $column = \trim($column);
                if (!\preg_match(Statement::REGEX_COLUMN, $column)) {
                    throw new QueryException('USING clause only allow column name.');
                }

                // Generate explicit ON condition: source.column = alias.column
                $column = $source . '.`' . $column . '` = ' . $alias . '.`' . $column . '`';
            }

            return 'ON ' . \implode(' AND ', $columns);
        }

        throw new QueryException('invalid condition syntax');
    }
}
