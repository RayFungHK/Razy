<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Extracted from Statement::getSyntax() UPDATE branch + getUpdateSyntax() (Phase 2.3).
 *
 * @package Razy
 *
 * @license MIT
 */

namespace Razy\Database\Statement;

use Razy\Database\Statement;
use Razy\Exception\QueryException;

/**
 * Builds SQL UPDATE syntax from a Statement's configured properties.
 *
 * Handles: UPDATE table SET col=expr WHERE condition.
 * Includes the recursive Update Simple Syntax parser for SET expressions.
 */
class UpdateSyntaxBuilder implements SyntaxBuilderInterface
{
    /**
     * Build the UPDATE SQL syntax.
     *
     * @param Statement $statement The statement instance
     *
     * @return string The generated UPDATE SQL
     *
     * @throws Error If table name or update syntax is missing
     */
    public function buildSyntax(Statement $statement): string
    {
        $tableName = $statement->getTableName();
        $updateExpressions = $statement->getUpdateExpressions();

        if ($tableName && !empty($updateExpressions)) {
            // Get driver for identifier quoting
            $driver = $statement->getDatabase()->getDriver();
            $quote = $driver ? fn ($c) => $driver->quoteIdentifier($c) : fn ($c) => '`' . $c . '`';

            // Build SET clause with each column's update expression
            $updateSyntax = [];
            foreach ($updateExpressions as $column => $syntax) {
                $updateSyntax[] = $quote($column) . ' = ' . $this->buildUpdateExpression($syntax, $column, $statement);
            }

            $sql = 'UPDATE ' . $tableName . ' SET ' . \implode(', ', $updateSyntax);

            // Append WHERE clause if defined
            $where = $statement->getWhereSyntax();
            if ($where) {
                $sql .= ' WHERE ' . $where;
            }

            return $sql;
        }

        throw new QueryException('Missing update syntax.');
    }

    /**
     * Generate the UPDATE SET expression for a single column from parsed Update Simple Syntax.
     * Handles arithmetic operators (+, -, *, /), concatenation (&), parameter references,
     * and nested sub-expressions.
     *
     * Moved from Statement::getUpdateSyntax().
     *
     * @param array $updateSyntax The parsed update expression tokens
     * @param string $column The column name being updated
     * @param Statement $statement The statement instance for value resolution
     *
     * @return string The SQL expression for the SET clause
     *
     * @throws Error If the update syntax is invalid
     */
    private function buildUpdateExpression(array $updateSyntax, string $column, Statement $statement): string
    {
        $driver = $statement->getDatabase()->getDriver();

        // Helper function for concatenation - uses driver-specific syntax
        $concat = function (array $parts) use ($driver): string {
            if ($driver) {
                return $driver->getConcatSyntax($parts);
            }
            // Legacy MySQL fallback using CONCAT()
            return 'CONCAT(' . \implode(', ', $parts) . ')';
        };

        // Recursive parser that processes operand tokens and builds SQL expressions
        $parser = function (array &$extracted) use (&$parser, $column, $concat, $statement) {
            $parsed = [];
            $operand = '';
            while ($clip = \array_shift($extracted)) {
                if (\is_array($clip)) {
                    // Nested sub-expression: recurse and handle concatenation operator
                    if ('&' === $operand) {
                        $parts = \array_merge($parsed, [$parser($clip)]);
                        $parsed = [$concat($parts)];
                    } else {
                        $parsed[] = '(' . $parser($clip) . ')';
                    }
                } else {
                    if (!\preg_match('/^[+\-*\/&]$/', $clip)) {
                        $matches = null;
                        if ('?' === $clip || \preg_match('/^:(\w+)$/', $clip, $matches)) {
                            $clip = $statement->getValueAsStatement(('?' === $clip) ? $column : $matches[1]);
                        } else {
                            // Replace inline :parameter references within complex expressions
                            $clip = \preg_replace_callback('/(?:(?<q>[\'"`])(?:\\\\.(*SKIP)|(?!\k<q>).)*\k<q>|(?<w>\[)(?:\\\\.(*SKIP)|[^\[\]])*]|\\\\.)(*SKIP)(*FAIL)|:(\w+)/', function ($matches) use ($statement) {
                                return $statement->getValueAsStatement($matches[3]);
                            }, $clip);
                        }

                        if ('&' === $operand) {
                            $parts = \array_merge($parsed, [$clip]);
                            $parsed = [$concat($parts)];
                        } else {
                            $parsed[] = $clip;
                        }
                    } else {
                        throw new QueryException('Cannot generate the update statement because the Update Simple Syntax is not correct.');
                    }
                }

                $operand = \array_shift($extracted);
                if ($operand) {
                    if (!\is_string($operand) || !\preg_match('/^[+\-*\/&]$/', $operand)) {
                        throw new QueryException('Cannot generate the update statement because the Update Simple Syntax is not correct.');
                    }

                    if ('&' !== $operand) {
                        $parsed[] = $operand;
                    }
                }
            }

            return \implode(' ', $parsed);
        };

        $extracted = $updateSyntax;

        return $parser($extracted);
    }
}
