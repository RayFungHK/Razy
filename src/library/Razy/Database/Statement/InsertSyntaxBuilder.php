<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Extracted from Statement::getSyntax() INSERT/REPLACE branch (Phase 2.3).
 *
 *
 * @license MIT
 */

namespace Razy\Database\Statement;

use Razy\Database\Statement;
use Razy\Exception\QueryException;

/**
 * Builds SQL INSERT (and REPLACE) syntax from a Statement's configured properties.
 *
 * Handles: INSERT INTO table (cols) VALUES (vals), ON DUPLICATE KEY UPDATE,
 * and driver-specific upsert (ON CONFLICT) syntax.
 */
class InsertSyntaxBuilder implements SyntaxBuilderInterface
{
    /**
     * Build the INSERT/REPLACE SQL syntax.
     *
     * @param Statement $statement The statement instance
     *
     * @return string The generated INSERT/REPLACE SQL
     *
     * @throws Error If table name or columns are missing
     */
    public function buildSyntax(Statement $statement): string
    {
        $tableName = $statement->getTableName();
        $columns = $statement->getColumnsArray();

        if ($tableName && !empty($columns)) {
            $driver = $statement->getDatabase()->getDriver();
            $onDuplicateKey = $statement->getOnDuplicateKey();

            // Use driver-specific upsert (INSERT ON DUPLICATE / ON CONFLICT) if available
            if ($driver && \count($onDuplicateKey)) {
                return $driver->getUpsertSyntax(
                    $tableName,
                    $columns,
                    $onDuplicateKey,
                    fn ($col) => $statement->getValueAsStatement($col),
                );
            }

            // Standard INSERT/REPLACE syntax
            $quote = $driver ? fn ($c) => $driver->quoteIdentifier($c) : fn ($c) => '`' . $c . '`';
            $rawType = $statement->getRawType();
            $sql = \strtoupper($rawType) . ' INTO ' . $tableName . ' (' . \implode(', ', \array_map($quote, $columns)) . ') VALUES (';
            $values = [];
            foreach ($columns as $column) {
                $values[] = $statement->getValueAsStatement($column);
            }
            $sql .= \implode(', ', $values) . ')';

            if (\count($onDuplicateKey)) {
                // Legacy MySQL fallback for ON DUPLICATE KEY UPDATE
                $duplicatedKeys = [];
                foreach ($onDuplicateKey as $column) {
                    if (\is_string($column)) {
                        $duplicatedKeys[] = '`' . $column . '` = ' . $statement->getValueAsStatement($column);
                    }
                }

                if (\count($duplicatedKeys)) {
                    $sql .= ' ON DUPLICATE KEY UPDATE ' . \implode(', ', $duplicatedKeys);
                }
            }

            return $sql;
        }

        throw new QueryException('Invalid insert statement or no columns has provided.');
    }
}
