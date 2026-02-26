<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Extracted from Statement::getSyntax() DELETE branch (Phase 2.3).
 *
 * @package Razy
 * @license MIT
 */

namespace Razy\Database\Statement;

use Razy\Database\Statement;
use Razy\Exception\QueryException;

/**
 * Builds SQL DELETE syntax from a Statement's configured properties.
 *
 * Handles: DELETE FROM table WHERE condition.
 */
class DeleteSyntaxBuilder implements SyntaxBuilderInterface
{
    /**
     * Build the DELETE SQL syntax.
     *
     * @param Statement $statement The statement instance
     *
     * @return string The generated DELETE SQL
     *
     * @throws Error If table name or columns are missing
     */
    public function buildSyntax(Statement $statement): string
    {
        $tableName = $statement->getTableName();
        $columns = $statement->getColumnsArray();

        if ($tableName && !empty($columns)) {
            $sql = 'DELETE FROM ' . $tableName;

            // Append WHERE clause if defined
            $where = $statement->getWhereSyntax();
            if ($where) {
                $sql .= ' WHERE ' . $where;
            }

            return $sql;
        }

        throw new QueryException('Invalid delete statement.');
    }
}
