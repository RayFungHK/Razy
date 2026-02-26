<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Extracted from Statement::getSyntax() SELECT branch (Phase 2.3).
 *
 * @package Razy
 *
 * @license MIT
 */

namespace Razy\Database\Statement;

use Razy\Database\Statement;
use Razy\Exception\QueryException;

/**
 * Builds SQL SELECT syntax from a Statement's configured properties.
 *
 * Handles: SELECT columns, FROM/JOIN, WHERE, GROUP BY, HAVING, ORDER BY, LIMIT/OFFSET.
 */
class SelectSyntaxBuilder implements SyntaxBuilderInterface
{
    /**
     * Build the SELECT SQL syntax.
     *
     * @param Statement $statement The statement instance
     *
     * @return string The generated SELECT SQL
     *
     * @throws Error If no FROM clause (TableJoinSyntax) is defined
     */
    public function buildSyntax(Statement $statement): string
    {
        $fromSyntax = $statement->getFromSyntax();
        if (null === $fromSyntax) {
            throw new QueryException('No Table Join Syntax is provided.');
        }

        // Build SELECT ... FROM ... clause
        $sql = 'SELECT ' . \implode(', ', $statement->getSelectColumnsArray()) . ' FROM ' . $fromSyntax;

        // Append WHERE clause if defined and non-empty
        $where = $statement->getWhereSyntax();
        if ($where) {
            $sql .= ' WHERE ' . $where;
        }

        // Append GROUP BY clause
        $groupby = $statement->getGroupByArray();
        if (\count($groupby)) {
            $sql .= ' GROUP BY ' . \implode(', ', $groupby);
        }

        // Append HAVING clause for aggregate filtering
        $having = $statement->getHavingSyntax();
        if ($having) {
            $sql .= ' HAVING ' . $having;
        }

        // Append ORDER BY clause, resolving any deferred WhereSyntax-based expressions
        $orderby = $statement->getOrderByArray();
        if (\count($orderby)) {
            $resolved = [];
            foreach ($orderby as $entry) {
                if (\is_array($entry)) {
                    $resolved[] = $entry['syntax']->parseSyntax($entry['column'])->getSyntax() . ' ' . $entry['ordering'];
                } else {
                    $resolved[] = $entry;
                }
            }
            $sql .= ' ORDER BY ' . \implode(', ', $resolved);
        }

        // Append LIMIT/OFFSET using driver-specific syntax when available
        $driver = $statement->getDatabase()->getDriver();
        $position = $statement->getPosition();
        $fetchLength = $statement->getFetchLength();

        if ($driver) {
            $sql .= $driver->getLimitSyntax($position, $fetchLength);
        } else {
            // Legacy MySQL fallback
            if ($fetchLength == 0 && $position > 0) {
                $sql .= ' LIMIT ' . $position;
            } elseif ($fetchLength > 0) {
                $sql .= ' LIMIT ' . $position . ', ' . $fetchLength;
            }
        }

        return $sql;
    }
}
